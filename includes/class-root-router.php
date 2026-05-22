<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes root-level paths (/mcp, /oauth/*) directly to the existing MCP and
 * OAuth server handlers, bypassing WP's REST API. Many shared hosts (Hostinger
 * being the trigger for this) block /wp-json/* from datacenter IPs as a
 * REST-API abuse protection — which silently breaks remote MCP clients
 * (Claude, ChatGPT) that run from AWS / GCP. Serving the same endpoints at
 * root paths sidesteps the block entirely.
 *
 * Paths handled here:
 *   POST/GET  /mcp                → WPAIC_MCP_Server::handle_request
 *   POST      /oauth/register     → WPAIC_OAuth_Server::handle_register
 *   GET/POST  /oauth/authorize    → WPAIC_OAuth_Server::handle_authorize
 *   POST      /oauth/token        → WPAIC_OAuth_Server::handle_token
 *   POST      /oauth/revoke       → WPAIC_OAuth_Server::handle_revoke
 *
 * Discovery metadata (/.well-known/oauth-*) is already root-path served by
 * WPAIC_OAuth_Server::maybe_handle_well_known and not duplicated here.
 *
 * The original /wp-json/wp-ai-connector/v1/* routes stay registered so
 * existing tooling (MCP Inspector, scripts using Application Password) keeps
 * working. OAuth metadata advertises the root paths.
 */
class WPAIC_Root_Router {
	private WPAIC_MCP_Server $mcp;
	private WPAIC_OAuth_Server $oauth;

	public function __construct( WPAIC_MCP_Server $mcp, WPAIC_OAuth_Server $oauth ) {
		$this->mcp   = $mcp;
		$this->oauth = $oauth;
	}

	public function register(): void {
		add_action( 'parse_request', array( $this, 'route' ), 0 );
	}

	public function route(): void {
		$path = (string) parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );

		if ( '/mcp' === $path ) {
			$this->handle_mcp();
			return; // unreachable; handlers exit.
		}

		if ( preg_match( '#^/oauth/(authorize|token|register|revoke)/?$#', $path, $m ) ) {
			$this->handle_oauth( $m[1] );
			return; // unreachable.
		}
	}

	private function handle_mcp(): void {
		$this->emit_cors_headers();

		$request = $this->build_rest_request( '/mcp' );

		// check_permission either returns true, or calls emit_unauthorized()
		// which exits (after logging itself) — same flow as REST API.
		$permission = $this->mcp->check_permission( $request );
		if ( is_wp_error( $permission ) ) {
			$this->log_request( (string) $request->get_body(), (int) ( $permission->get_error_data()['status'] ?? 500 ) );
			$this->emit( $permission );
			exit;
		}

		$response = $this->mcp->handle_request( $request );
		$this->log_request( (string) $request->get_body(), $this->status_of( $response ) );
		$this->emit( $response );
		exit;
	}

	private function handle_oauth( string $endpoint ): void {
		$this->emit_cors_headers();

		$request = $this->build_rest_request( '/oauth/' . $endpoint );
		$method  = 'handle_' . $endpoint;

		// OAuth handlers may exit() internally (consent page render, browser
		// redirects). For those, log here before the call — the handler
		// won't return so we won't get to log after. For handlers that do
		// return, log after with the actual status. We accept that the
		// exit-path entries have status=null since we can't know in advance.
		$is_redirect_path = in_array( $endpoint, array( 'authorize' ), true );
		if ( $is_redirect_path ) {
			$this->log_request( (string) $request->get_body(), null );
		}

		$response = $this->oauth->$method( $request );

		if ( ! $is_redirect_path ) {
			$this->log_request( (string) $request->get_body(), $this->status_of( $response ) );
		}

		$this->emit( $response );
		exit;
	}

	/**
	 * @param mixed $response
	 */
	private function status_of( $response ): ?int {
		if ( $response instanceof WP_REST_Response ) {
			return $response->get_status();
		}
		if ( is_wp_error( $response ) ) {
			return (int) ( $response->get_error_data()['status'] ?? 500 );
		}
		return null;
	}

	private function emit_cors_headers(): void {
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS, DELETE' );
		header( 'Access-Control-Allow-Headers: Authorization, Content-Type, Mcp-Session-Id, MCP-Protocol-Version' );
		header( 'Access-Control-Expose-Headers: WWW-Authenticate, Mcp-Session-Id' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Pragma: no-cache' );
		header( 'X-LiteSpeed-Cache-Control: no-cache' );

		if ( ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) === 'OPTIONS' ) {
			status_header( 204 );
			exit;
		}
	}

	private function log_request( string $body, ?int $status ): void {
		if ( ! function_exists( 'wpaic_request_log' ) ) {
			return;
		}
		wpaic_request_log()->log_manual(
			(string) ( $_SERVER['REQUEST_URI'] ?? '' ),
			$status,
			$body
		);
	}

	private function build_rest_request( string $route ): WP_REST_Request {
		$method  = (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' );
		$request = new WP_REST_Request( $method, $route );

		$raw_body = file_get_contents( 'php://input' );
		if ( false !== $raw_body && '' !== $raw_body ) {
			$request->set_body( $raw_body );
		}

		// Mirror PHP-server HTTP_* vars into WP_REST_Request headers.
		foreach ( $_SERVER as $key => $value ) {
			if ( 0 === strpos( (string) $key, 'HTTP_' ) ) {
				$name = strtolower( str_replace( '_', '-', substr( $key, 5 ) ) );
				$request->set_header( $name, (string) $value );
			}
		}
		if ( ! empty( $_SERVER['CONTENT_TYPE'] ) ) {
			$request->set_header( 'content-type', (string) $_SERVER['CONTENT_TYPE'] );
		}

		$request->set_query_params( wp_unslash( $_GET ) );

		// JSON body → json_params (what get_json_params() returns).
		// Form body → body_params (what set_body_params normally has).
		$ct = (string) $request->get_header( 'content-type' );
		if ( false !== stripos( $ct, 'application/json' ) && '' !== (string) $raw_body ) {
			$data = json_decode( (string) $raw_body, true );
			if ( is_array( $data ) ) {
				$request->set_json_params( $data );
			}
		}
		if ( ! empty( $_POST ) ) {
			$request->set_body_params( wp_unslash( $_POST ) );
		}

		return $request;
	}

	/**
	 * @param mixed $response
	 */
	private function emit( $response ): void {
		if ( null === $response ) {
			return;
		}

		if ( is_wp_error( $response ) ) {
			$status = (int) ( $response->get_error_data()['status'] ?? 500 );
			status_header( $status );
			header( 'Content-Type: application/json; charset=utf-8' );
			echo wp_json_encode( array(
				'code'    => $response->get_error_code(),
				'message' => $response->get_error_message(),
				'data'    => $response->get_error_data(),
			) );
			return;
		}

		if ( $response instanceof WP_REST_Response ) {
			status_header( $response->get_status() );
			foreach ( $response->get_headers() as $name => $value ) {
				header( $name . ': ' . $value );
			}
			$data = $response->get_data();
			if ( null !== $data ) {
				if ( ! $response->get_headers()['Content-Type'] ?? null ) {
					header( 'Content-Type: application/json; charset=utf-8' );
				}
				echo wp_json_encode( $data );
			}
		}
	}
}
