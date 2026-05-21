<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAIC_MCP_Server {
	private WPAIC_Tool_Registry $registry;
	private WPAIC_OAuth_Server $oauth;

	public function __construct( WPAIC_Tool_Registry $registry, WPAIC_OAuth_Server $oauth ) {
		$this->registry = $registry;
		$this->oauth    = $oauth;
	}

	public function register_routes(): void {
		register_rest_route(
			WPAIC_REST_NAMESPACE,
			'/mcp',
			array(
				'methods'             => array( 'POST', 'GET', 'DELETE' ),
				'callback'            => array( $this, 'handle_request' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	public function check_permission( WP_REST_Request $request ) {
		// Auth is enforced per-method in dispatch() so that unauthenticated
		// MCP clients can complete protocol negotiation (initialize, ping,
		// notifications/initialized) before being challenged. The challenge
		// itself comes from tools/list, tools/call, etc. — that's when the
		// client sees our 401 + WWW-Authenticate and starts OAuth discovery.
		// Resolve a Bearer token here so wp_set_current_user runs in time
		// for the route handler.
		$bearer = $this->extract_bearer_token();
		if ( '' !== $bearer ) {
			$user_id = $this->oauth->resolve_bearer_token( $bearer );
			if ( $user_id ) {
				wp_set_current_user( $user_id );
			}
		}
		return true;
	}

	/**
	 * Methods that don't require authentication. Protocol negotiation,
	 * keepalive, and listing (tool/resource/prompt definitions are not
	 * sensitive — they're public schemas). Auth kicks in on tools/call.
	 */
	private const UNAUTHENTICATED_METHODS = array(
		'initialize',
		'notifications/initialized',
		'initialized',
		'ping',
		'tools/list',
		'resources/list',
		'prompts/list',
	);

	private function require_auth( WP_REST_Request $request, string $method ): void {
		if ( in_array( $method, self::UNAUTHENTICATED_METHODS, true ) ) {
			return;
		}
		// Bearer was already resolved in check_permission. Re-check token
		// validity here so we can distinguish "no token" from "bad token"
		// and report invalid_token to the client.
		$bearer = $this->extract_bearer_token();
		if ( '' !== $bearer ) {
			$user_id = $this->oauth->resolve_bearer_token( $bearer );
			if ( ! $user_id ) {
				$this->emit_unauthorized( $request, 'invalid_token', 'Bearer token is invalid, expired, or bound to a different resource.' );
			}
		}
		if ( ! is_user_logged_in() ) {
			$this->emit_unauthorized( $request, '', 'Authentication required. Use an OAuth Bearer token or a WordPress Application Password.' );
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			$this->emit_unauthorized( $request, 'insufficient_scope', 'User lacks edit_posts capability.' );
		}
	}

	/**
	 * Send a 401 directly with a JSON-RPC formatted body, the WWW-Authenticate
	 * header, and the OAuth discovery hint inside error.data.resource_metadata.
	 * MCP clients expect JSON-RPC responses on the MCP endpoint; returning
	 * WordPress's default WP_Error JSON shape confuses some of them and they
	 * abort discovery instead of following WWW-Authenticate.
	 */
	private function emit_unauthorized( WP_REST_Request $request, string $oauth_error, string $message ): void {
		// rest_pre_dispatch already logged this request, but log_manual ensures
		// the entry has the correct status (401). Belt-and-suspenders.
		if ( function_exists( 'wpaic_request_log' ) ) {
			wpaic_request_log()->log_manual(
				(string) ( $_SERVER['REQUEST_URI'] ?? $request->get_route() ),
				401,
				(string) $request->get_body()
			);
		}

		// JSON-RPC strict parsers (Claude included) reject responses whose id
		// doesn't match the request's id. Echo the incoming id when parseable;
		// fall back to null only if the body wasn't valid JSON.
		$request_id = null;
		$body       = json_decode( (string) $request->get_body(), true );
		if ( is_array( $body ) && array_key_exists( 'id', $body ) ) {
			$request_id = $body['id'];
		}

		$this->send_www_authenticate( $oauth_error );
		status_header( 401 );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'MCP-Protocol-Version: ' . WPAIC_MCP_PROTOCOL_VERSION );

		$resource_metadata = home_url( WPAIC_OAuth_Server::WELL_KNOWN_PR_PATH );
		echo wp_json_encode( array(
			'jsonrpc' => '2.0',
			'id'      => $request_id,
			'error'   => array(
				'code'    => -32001,
				'message' => $message,
				'data'    => array(
					'status'            => 401,
					'oauth_error'       => $oauth_error,
					'resource_metadata' => $resource_metadata,
				),
			),
		) );
		exit;
	}

	private function extract_bearer_token(): string {
		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) && 0 === stripos( $_SERVER[ $key ], 'bearer ' ) ) {
				return trim( substr( (string) $_SERVER[ $key ], 7 ) );
			}
		}
		if ( function_exists( 'getallheaders' ) ) {
			foreach ( getallheaders() as $name => $value ) {
				if ( 0 === strcasecmp( $name, 'Authorization' ) && 0 === stripos( (string) $value, 'bearer ' ) ) {
					return trim( substr( (string) $value, 7 ) );
				}
			}
		}
		return '';
	}

	private function send_www_authenticate( string $error = '' ): void {
		$header = $this->oauth->www_authenticate_header();
		if ( '' !== $error ) {
			$header .= ', error="' . $error . '"';
		}
		header( 'WWW-Authenticate: ' . $header );
		// Auth challenges must never be cached. Belt-and-suspenders for hosts
		// (Hostinger/LiteSpeed) that otherwise apply public cache directives.
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Pragma: no-cache' );
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
	}

	public function handle_request( WP_REST_Request $request ) {
		$method = $request->get_method();

		// GET on the MCP endpoint is used by some clients to open an SSE stream.
		// We do not stream server-initiated messages in v0.1, so we respond 405.
		if ( 'GET' === $method ) {
			return new WP_REST_Response( null, 405 );
		}

		// DELETE is used to terminate sessions. We are stateless in v0.1.
		if ( 'DELETE' === $method ) {
			return new WP_REST_Response( null, 204 );
		}

		$body = $request->get_json_params();
		if ( null === $body ) {
			return $this->json_rpc_error( null, -32700, 'Parse error: invalid JSON' );
		}

		// Batch request.
		if ( $this->is_batch( $body ) ) {
			$responses = array();
			foreach ( $body as $message ) {
				$response = $this->dispatch( $message, $request );
				if ( null !== $response ) {
					$responses[] = $response;
				}
			}
			return empty( $responses ) ? new WP_REST_Response( null, 204 ) : new WP_REST_Response( $responses, 200 );
		}

		$response = $this->dispatch( $body, $request );

		// Session-ID handling. On initialize success, issue a new ID. On any
		// other request, echo back whatever ID the client sent (we're stateless,
		// we trust the client's). Strict MCP clients won't accept a response
		// without the same session ID they sent.
		$client_session = (string) ( $_SERVER['HTTP_MCP_SESSION_ID'] ?? '' );
		$session_id     = null;
		if ( is_array( $body ) && ( $body['method'] ?? '' ) === 'initialize' && null !== $response ) {
			$session_id = wp_generate_uuid4();
		} elseif ( '' !== $client_session ) {
			$session_id = $client_session;
		}

		if ( null === $response ) {
			// Notification — no body.
			$rest_response = new WP_REST_Response( null, 204 );
		} else {
			$rest_response = new WP_REST_Response( $response, 200 );
		}
		$rest_response->header( 'MCP-Protocol-Version', WPAIC_MCP_PROTOCOL_VERSION );
		if ( $session_id ) {
			$rest_response->header( 'Mcp-Session-Id', $session_id );
		}
		return $rest_response;
	}

	private function is_batch( $body ): bool {
		return is_array( $body ) && array_keys( $body ) === range( 0, count( $body ) - 1 );
	}

	/**
	 * @return array|null JSON-RPC response, or null for notifications.
	 */
	private function dispatch( array $message, WP_REST_Request $request ): ?array {
		$id     = $message['id']     ?? null;
		$method = $message['method'] ?? null;
		$params = $message['params'] ?? array();

		if ( ! is_string( $method ) ) {
			return $this->json_rpc_error( $id, -32600, 'Invalid Request: missing method' );
		}

		$is_notification = ! array_key_exists( 'id', $message );

		// Gate methods that read or mutate WordPress data. Protocol-negotiation
		// methods (initialize, ping, notifications/initialized) pass through so
		// the client can establish a session before being challenged.
		$this->require_auth( $request, $method );

		try {
			switch ( $method ) {
				case 'initialize':
					$result = $this->handle_initialize( $params );
					break;
				case 'notifications/initialized':
				case 'initialized':
					return null; // Notification, no response.
				case 'ping':
					$result = (object) array();
					break;
				case 'tools/list':
					$result = array( 'tools' => $this->registry->list_tools() );
					break;
				case 'tools/call':
					$result = $this->dispatch_tool_call( $params );
					break;
				case 'resources/list':
					$result = array( 'resources' => array() );
					break;
				case 'prompts/list':
					$result = array( 'prompts' => array() );
					break;
				default:
					return $this->json_rpc_error( $id, -32601, "Method not found: {$method}" );
			}
		} catch ( WPAIC_Tool_Exception $e ) {
			return $this->json_rpc_error( $id, $e->getCode() ?: -32000, $e->getMessage() );
		} catch ( Throwable $e ) {
			return $this->json_rpc_error( $id, -32603, 'Internal error: ' . $e->getMessage() );
		}

		if ( $is_notification ) {
			return null;
		}

		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}

	private function handle_initialize( array $params ): array {
		return array(
			'protocolVersion' => WPAIC_MCP_PROTOCOL_VERSION,
			'capabilities'    => array(
				'tools' => (object) array(),
			),
			'serverInfo'      => array(
				'name'    => 'WordPress AI Connector',
				'version' => WPAIC_VERSION,
				'site'    => get_bloginfo( 'name' ),
			),
		);
	}

	private function dispatch_tool_call( array $params ): array {
		$name      = $params['name']      ?? '';
		$arguments = $params['arguments'] ?? array();

		if ( ! is_string( $name ) || '' === $name ) {
			throw new WPAIC_Tool_Exception( 'tools/call: missing name', -32602 );
		}
		if ( ! is_array( $arguments ) ) {
			throw new WPAIC_Tool_Exception( 'tools/call: arguments must be an object', -32602 );
		}
		if ( ! $this->registry->has_tool( $name ) ) {
			throw new WPAIC_Tool_Exception( "Unknown tool: {$name}", -32601 );
		}

		$capability = $this->registry->get_capability( $name );
		if ( $capability && ! current_user_can( $capability ) ) {
			return $this->tool_error( "User lacks capability '{$capability}' required by tool '{$name}'." );
		}

		try {
			$result = $this->registry->call( $name, $arguments );
		} catch ( WPAIC_Tool_Exception $e ) {
			return $this->tool_error( $e->getMessage() );
		} catch ( Throwable $e ) {
			return $this->tool_error( 'Tool error: ' . $e->getMessage() );
		}

		return array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => is_string( $result ) ? $result : wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
				),
			),
			'isError' => false,
		);
	}

	private function tool_error( string $message ): array {
		return array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => $message,
				),
			),
			'isError' => true,
		);
	}

	private function json_rpc_error( $id, int $code, string $message ): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}
}
