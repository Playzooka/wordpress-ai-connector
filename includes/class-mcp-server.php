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
		// Prefer Bearer (OAuth). If absent, fall through to whatever WordPress
		// resolved (Application Passwords populate the current user already).
		$bearer = $this->extract_bearer_token();
		if ( '' !== $bearer ) {
			$user_id = $this->oauth->resolve_bearer_token( $bearer );
			if ( ! $user_id ) {
				$this->send_www_authenticate( 'invalid_token' );
				return new WP_Error(
					'wpaic_invalid_token',
					'Bearer token is invalid, expired, or bound to a different resource.',
					array( 'status' => 401 )
				);
			}
			wp_set_current_user( $user_id );
		}

		if ( ! is_user_logged_in() ) {
			$this->send_www_authenticate();
			return new WP_Error(
				'wpaic_unauthorized',
				'Authentication required. Use an OAuth Bearer token or a WordPress Application Password.',
				array( 'status' => 401 )
			);
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'wpaic_forbidden',
				'User lacks edit_posts capability.',
				array( 'status' => 403 )
			);
		}
		return true;
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
				$response = $this->dispatch( $message );
				if ( null !== $response ) {
					$responses[] = $response;
				}
			}
			return empty( $responses ) ? new WP_REST_Response( null, 204 ) : new WP_REST_Response( $responses, 200 );
		}

		$response = $this->dispatch( $body );
		if ( null === $response ) {
			// Notification — no body.
			return new WP_REST_Response( null, 204 );
		}
		return new WP_REST_Response( $response, 200 );
	}

	private function is_batch( $body ): bool {
		return is_array( $body ) && array_keys( $body ) === range( 0, count( $body ) - 1 );
	}

	/**
	 * @return array|null JSON-RPC response, or null for notifications.
	 */
	private function dispatch( array $message ): ?array {
		$id     = $message['id']     ?? null;
		$method = $message['method'] ?? null;
		$params = $message['params'] ?? array();

		if ( ! is_string( $method ) ) {
			return $this->json_rpc_error( $id, -32600, 'Invalid Request: missing method' );
		}

		$is_notification = ! array_key_exists( 'id', $message );

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
