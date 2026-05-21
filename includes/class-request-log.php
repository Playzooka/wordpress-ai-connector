<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lightweight rolling log of incoming requests to this plugin's namespace plus
 * the OAuth well-known paths. Used to diagnose MCP client discovery flows.
 *
 * Disable by defining WPAIC_DEBUG_REQUESTS to false in wp-config.php.
 */
class WPAIC_Request_Log {
	const OPTION      = 'wpaic_request_log';
	const MAX_ENTRIES = 100;

	public function register(): void {
		if ( defined( 'WPAIC_DEBUG_REQUESTS' ) && ! WPAIC_DEBUG_REQUESTS ) {
			return;
		}
		add_action( 'parse_request', array( $this, 'maybe_log_root' ), 1 );
		add_filter( 'rest_post_dispatch', array( $this, 'log_rest' ), 1, 3 );
	}

	public function maybe_log_root(): void {
		$path = (string) parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
		if ( 0 === strpos( $path, '/.well-known/oauth-' ) ) {
			$this->record( $path, null );
		}
	}

	/**
	 * @param mixed $result
	 * @return mixed
	 */
	public function log_rest( $result, $server, $request ) {
		$route = (string) $request->get_route();
		if ( 0 !== strpos( $route, '/' . WPAIC_REST_NAMESPACE ) ) {
			return $result;
		}
		$status = null;
		if ( $result instanceof WP_REST_Response ) {
			$status = $result->get_status();
		} elseif ( is_wp_error( $result ) ) {
			$status = (int) ( $result->get_error_data()['status'] ?? 500 );
		}
		$this->record( $_SERVER['REQUEST_URI'] ?? $route, $status );
		return $result;
	}

	private function record( string $path, ?int $status ): void {
		$entry = array(
			'time'    => time(),
			'method'  => substr( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ), 0, 10 ),
			'path'    => substr( $path, 0, 500 ),
			'status'  => $status,
			'origin'  => substr( (string) ( $_SERVER['HTTP_ORIGIN'] ?? '' ), 0, 200 ),
			'ua'      => substr( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 200 ),
			'headers' => $this->collect_headers(),
			'body'    => $this->collect_body(),
		);
		$log = $this->all();
		$log[] = $entry;
		if ( count( $log ) > self::MAX_ENTRIES ) {
			$log = array_slice( $log, -self::MAX_ENTRIES );
		}
		update_option( self::OPTION, $log, false );
	}

	/**
	 * Pull a curated set of request headers useful for MCP debugging. We avoid
	 * the Authorization header value so tokens aren't persisted in the log.
	 */
	private function collect_headers(): array {
		$wanted = array( 'Accept', 'Content-Type', 'MCP-Protocol-Version', 'Mcp-Session-Id', 'Referer' );
		$out    = array();
		foreach ( $wanted as $name ) {
			$key = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$out[ $name ] = substr( (string) $_SERVER[ $key ], 0, 200 );
			}
		}
		// Just record whether an Authorization header was present, and which scheme.
		$auth = (string) ( $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '' );
		if ( '' !== $auth ) {
			$scheme           = strtok( $auth, ' ' );
			$out['Authorization'] = $scheme . ' <redacted>';
		}
		return $out;
	}

	private function collect_body(): string {
		// REST API requests have already consumed php://input, so try the
		// most reliable sources in turn.
		$body = '';
		if ( ! empty( $GLOBALS['HTTP_RAW_POST_DATA'] ) ) {
			$body = (string) $GLOBALS['HTTP_RAW_POST_DATA'];
		}
		if ( '' === $body ) {
			$raw = file_get_contents( 'php://input' );
			if ( false !== $raw ) {
				$body = (string) $raw;
			}
		}
		if ( '' === $body && ! empty( $_POST ) ) {
			$body = (string) wp_json_encode( $_POST );
		}
		return substr( $body, 0, 500 );
	}

	public function all(): array {
		$log = get_option( self::OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	public function clear(): void {
		update_option( self::OPTION, array(), false );
	}
}
