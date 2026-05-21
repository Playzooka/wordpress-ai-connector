<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OAuth 2.1 authorization server + protected resource integration.
 *
 * Supports:
 *  - Authorization Server Metadata (RFC 8414)
 *  - Protected Resource Metadata (RFC 9728)
 *  - Dynamic Client Registration (RFC 7591) — open registration
 *  - Authorization Code grant with required PKCE (RFC 7636, S256)
 *  - Refresh Token grant with rotation
 *  - Token revocation (RFC 7009)
 *  - Public and confidential clients
 *  - Single scope: "mcp"
 */
class WPAIC_OAuth_Server {
	const SCOPE              = 'mcp';
	const WELL_KNOWN_AS_PATH = '/.well-known/oauth-authorization-server';
	const WELL_KNOWN_PR_PATH = '/.well-known/oauth-protected-resource';

	private WPAIC_OAuth_Store $store;

	public function __construct( WPAIC_OAuth_Store $store ) {
		$this->store = $store;
	}

	public function register(): void {
		// Well-known endpoints live at the site root, not under /wp-json/.
		add_action( 'parse_request', array( $this, 'maybe_handle_well_known' ), 0 );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/* ===================================================================
	 * Well-known discovery (RFC 8414 + RFC 9728)
	 * =================================================================== */

	public function maybe_handle_well_known(): void {
		$path = (string) parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
		if ( self::WELL_KNOWN_AS_PATH === $path ) {
			$this->emit_json( $this->authorization_server_metadata() );
		}
		if ( self::WELL_KNOWN_PR_PATH === $path ) {
			$this->emit_json( $this->protected_resource_metadata() );
		}
	}

	public function authorization_server_metadata(): array {
		return array(
			'issuer'                                => $this->issuer(),
			'authorization_endpoint'                => $this->endpoint_url( 'authorize' ),
			'token_endpoint'                        => $this->endpoint_url( 'token' ),
			'registration_endpoint'                 => $this->endpoint_url( 'register' ),
			'revocation_endpoint'                   => $this->endpoint_url( 'revoke' ),
			'response_types_supported'              => array( 'code' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'token_endpoint_auth_methods_supported' => array( 'client_secret_basic', 'client_secret_post', 'none' ),
			'scopes_supported'                      => array( self::SCOPE ),
			'service_documentation'                 => 'https://github.com/Playzooka/wordpress-ai-connector',
		);
	}

	public function protected_resource_metadata(): array {
		return array(
			'resource'                 => $this->mcp_resource_url(),
			'authorization_servers'    => array( $this->issuer() ),
			'scopes_supported'         => array( self::SCOPE ),
			'bearer_methods_supported' => array( 'header' ),
		);
	}

	/* ===================================================================
	 * REST route registration
	 * =================================================================== */

	public function register_rest_routes(): void {
		$ns = WPAIC_REST_NAMESPACE;

		register_rest_route( $ns, '/oauth/register', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_register' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( $ns, '/oauth/authorize', array(
			'methods'             => array( 'GET', 'POST' ),
			'callback'            => array( $this, 'handle_authorize' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( $ns, '/oauth/token', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_token' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( $ns, '/oauth/revoke', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_revoke' ),
			'permission_callback' => '__return_true',
		) );
	}

	/* ===================================================================
	 * Dynamic Client Registration (RFC 7591)
	 * =================================================================== */

	public function handle_register( WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return $this->oauth_error( 'invalid_request', 'Request body must be JSON.', 400 );
		}

		$redirect_uris = (array) ( $body['redirect_uris'] ?? array() );
		if ( empty( $redirect_uris ) ) {
			return $this->oauth_error( 'invalid_redirect_uri', 'redirect_uris is required.', 400 );
		}
		foreach ( $redirect_uris as $uri ) {
			if ( ! is_string( $uri ) || ! filter_var( $uri, FILTER_VALIDATE_URL ) ) {
				return $this->oauth_error( 'invalid_redirect_uri', 'redirect_uris must contain valid absolute URLs.', 400 );
			}
		}

		try {
			$client = $this->store->create_client( $body );
		} catch ( Throwable $e ) {
			return $this->oauth_error( 'invalid_client_metadata', $e->getMessage(), 400 );
		}

		return new WP_REST_Response( $client, 201 );
	}

	/* ===================================================================
	 * Authorization endpoint (browser-facing)
	 * =================================================================== */

	public function handle_authorize( WP_REST_Request $request ) {
		$method = $request->get_method();

		$client_id            = (string) $request->get_param( 'client_id' );
		$redirect_uri         = (string) $request->get_param( 'redirect_uri' );
		$response_type        = (string) $request->get_param( 'response_type' );
		$scope                = (string) ( $request->get_param( 'scope' ) ?: self::SCOPE );
		$state                = (string) $request->get_param( 'state' );
		$code_challenge       = (string) $request->get_param( 'code_challenge' );
		$code_challenge_method = (string) ( $request->get_param( 'code_challenge_method' ) ?: 'S256' );
		$resource             = (string) ( $request->get_param( 'resource' ) ?: $this->mcp_resource_url() );

		$client = $this->store->get_client( $client_id );

		// Errors that cannot redirect (no valid client / no valid redirect_uri) must show the user.
		if ( ! $client ) {
			return $this->emit_html_error( 'unknown_client', 'The client_id was not recognized.' );
		}
		if ( ! in_array( $redirect_uri, $client['redirect_uris'], true ) ) {
			return $this->emit_html_error( 'invalid_redirect_uri', 'The redirect_uri does not match any registered URI for this client.' );
		}

		// From here on, errors redirect back to the client per RFC 6749 §4.1.2.1.
		if ( 'code' !== $response_type ) {
			return $this->redirect_with_error( $redirect_uri, 'unsupported_response_type', $state );
		}
		if ( '' === $code_challenge ) {
			return $this->redirect_with_error( $redirect_uri, 'invalid_request', $state, 'code_challenge is required (PKCE)' );
		}
		if ( 'S256' !== $code_challenge_method ) {
			return $this->redirect_with_error( $redirect_uri, 'invalid_request', $state, 'Only S256 code_challenge_method is supported' );
		}

		// User must be logged in. Send them to wp-login.php if not.
		if ( ! is_user_logged_in() ) {
			$current = $this->current_url();
			wp_safe_redirect( wp_login_url( $current ) );
			exit;
		}

		$user      = wp_get_current_user();
		$user_caps = user_can( $user, 'edit_posts' );

		if ( 'POST' === $method ) {
			// Consent submission.
			if ( ! wp_verify_nonce( (string) $request->get_param( '_wpaic_nonce' ), 'wpaic_oauth_consent' ) ) {
				return $this->redirect_with_error( $redirect_uri, 'access_denied', $state, 'Invalid nonce' );
			}
			$decision = (string) $request->get_param( 'decision' );
			if ( 'approve' !== $decision ) {
				return $this->redirect_with_error( $redirect_uri, 'access_denied', $state );
			}
			if ( ! $user_caps ) {
				return $this->redirect_with_error( $redirect_uri, 'access_denied', $state, 'User lacks edit_posts capability' );
			}

			$code = $this->store->save_code( array(
				'client_id'             => $client_id,
				'user_id'               => $user->ID,
				'redirect_uri'          => $redirect_uri,
				'scope'                 => $scope,
				'resource'              => $resource,
				'code_challenge'        => $code_challenge,
				'code_challenge_method' => $code_challenge_method,
				'issued_at'             => time(),
			) );

			$params = array( 'code' => $code );
			if ( $state ) {
				$params['state'] = $state;
			}
			wp_safe_redirect( $this->append_query( $redirect_uri, $params ) );
			exit;
		}

		// GET — render consent screen.
		return $this->render_consent_page( $client, $user, $scope, $resource );
	}

	private function render_consent_page( array $client, WP_User $user, string $scope, string $resource ): void {
		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );

		$site_name = get_bloginfo( 'name' );
		$nonce     = wp_create_nonce( 'wpaic_oauth_consent' );

		// Forward all incoming query parameters to the POST action so the
		// authorize() handler sees the same context on approve/deny.
		$forward = array_intersect_key(
			$_GET, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			array_flip( array( 'client_id', 'redirect_uri', 'response_type', 'scope', 'state', 'code_challenge', 'code_challenge_method', 'resource' ) )
		);

		$action_url = $this->endpoint_url( 'authorize' );
		?><!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title><?php echo esc_html( sprintf( /* translators: %s: site name */ __( 'Authorize access to %s', 'wp-ai-connector' ), $site_name ) ); ?></title>
	<style>
		body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f0f0f1; margin: 0; padding: 48px 16px; color: #1d2327; }
		.card { max-width: 480px; margin: 0 auto; background: #fff; border-radius: 8px; padding: 32px; box-shadow: 0 4px 12px rgba(0,0,0,0.06); }
		h1 { font-size: 20px; margin: 0 0 8px; }
		.site { color: #50575e; font-size: 14px; margin: 0 0 24px; }
		.client { display: flex; align-items: center; gap: 12px; padding: 16px; background: #f6f7f7; border-radius: 6px; margin-bottom: 24px; }
		.client img { width: 40px; height: 40px; border-radius: 6px; }
		.client-name { font-weight: 600; }
		.client-uri { color: #50575e; font-size: 13px; }
		ul.perms { padding-left: 20px; color: #1d2327; line-height: 1.6; }
		.user { color: #50575e; font-size: 13px; margin: 16px 0 24px; }
		.actions { display: flex; gap: 8px; }
		button { flex: 1; padding: 10px 16px; border-radius: 4px; border: 0; font-size: 14px; font-weight: 500; cursor: pointer; }
		button.approve { background: #2271b1; color: #fff; }
		button.approve:hover { background: #135e96; }
		button.deny { background: #fff; color: #2271b1; border: 1px solid #2271b1; }
		button.deny:hover { background: #f0f6fc; }
	</style>
</head>
<body>
	<div class="card">
		<h1><?php esc_html_e( 'Authorize access', 'wp-ai-connector' ); ?></h1>
		<p class="site"><?php echo esc_html( $site_name ); ?> · <?php echo esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?></p>

		<div class="client">
			<?php if ( ! empty( $client['logo_uri'] ) ) : ?>
				<img src="<?php echo esc_url( $client['logo_uri'] ); ?>" alt="" />
			<?php endif; ?>
			<div>
				<div class="client-name"><?php echo esc_html( $client['client_name'] ); ?></div>
				<?php if ( ! empty( $client['client_uri'] ) ) : ?>
					<div class="client-uri"><?php echo esc_html( $client['client_uri'] ); ?></div>
				<?php endif; ?>
			</div>
		</div>

		<p><?php
			printf(
				/* translators: 1: client name, 2: site name */
				esc_html__( '%1$s is requesting permission to manage content on %2$s. If you approve, it will be able to:', 'wp-ai-connector' ),
				'<strong>' . esc_html( $client['client_name'] ) . '</strong>',
				'<strong>' . esc_html( $site_name ) . '</strong>'
			);
		?></p>
		<ul class="perms">
			<li><?php esc_html_e( 'Read, create, update, and delete posts and pages', 'wp-ai-connector' ); ?></li>
			<li><?php esc_html_e( 'Upload and list media library items', 'wp-ai-connector' ); ?></li>
			<li><?php esc_html_e( 'Read site information and user list', 'wp-ai-connector' ); ?></li>
		</ul>
		<p class="user"><?php
			printf(
				/* translators: %s: display name */
				esc_html__( 'It will act as you (%s). Individual actions still require the permissions your account has.', 'wp-ai-connector' ),
				'<strong>' . esc_html( $user->display_name ) . '</strong>'
			);
		?></p>

		<form method="post" action="<?php echo esc_url( $action_url ); ?>">
			<?php foreach ( $forward as $name => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" />
			<?php endforeach; ?>
			<input type="hidden" name="_wpaic_nonce" value="<?php echo esc_attr( $nonce ); ?>" />
			<div class="actions">
				<button type="submit" name="decision" value="deny" class="deny"><?php esc_html_e( 'Deny', 'wp-ai-connector' ); ?></button>
				<button type="submit" name="decision" value="approve" class="approve"><?php esc_html_e( 'Approve', 'wp-ai-connector' ); ?></button>
			</div>
		</form>
	</div>
</body>
</html>
		<?php
		exit;
	}

	/* ===================================================================
	 * Token endpoint
	 * =================================================================== */

	public function handle_token( WP_REST_Request $request ) {
		$grant = (string) $request->get_param( 'grant_type' );

		// Identify the client (Basic auth header or POST body).
		list( $client_id, $client_secret ) = $this->extract_client_credentials( $request );
		if ( '' === $client_id ) {
			return $this->oauth_error( 'invalid_client', 'client_id is required.', 401 );
		}
		$client = $this->store->get_client( $client_id );
		if ( ! $client ) {
			return $this->oauth_error( 'invalid_client', 'Unknown client_id.', 401 );
		}
		$auth_method = $client['token_endpoint_auth_method'] ?? 'none';
		if ( 'none' !== $auth_method ) {
			if ( '' === $client_secret || ! $this->store->verify_client_secret( $client_id, $client_secret ) ) {
				return $this->oauth_error( 'invalid_client', 'Client authentication failed.', 401 );
			}
		}

		if ( 'authorization_code' === $grant ) {
			return $this->grant_authorization_code( $request, $client );
		}
		if ( 'refresh_token' === $grant ) {
			return $this->grant_refresh_token( $request, $client );
		}
		return $this->oauth_error( 'unsupported_grant_type', "Unsupported grant_type: {$grant}", 400 );
	}

	private function grant_authorization_code( WP_REST_Request $request, array $client ) {
		$code          = (string) $request->get_param( 'code' );
		$redirect_uri  = (string) $request->get_param( 'redirect_uri' );
		$code_verifier = (string) $request->get_param( 'code_verifier' );

		if ( '' === $code ) {
			return $this->oauth_error( 'invalid_request', 'code is required.', 400 );
		}
		$record = $this->store->consume_code( $code );
		if ( ! $record ) {
			return $this->oauth_error( 'invalid_grant', 'Authorization code is invalid or expired.', 400 );
		}
		if ( $record['client_id'] !== $client['client_id'] ) {
			return $this->oauth_error( 'invalid_grant', 'Code was issued to a different client.', 400 );
		}
		if ( $record['redirect_uri'] !== $redirect_uri ) {
			return $this->oauth_error( 'invalid_grant', 'redirect_uri mismatch.', 400 );
		}
		if ( '' === $code_verifier ) {
			return $this->oauth_error( 'invalid_request', 'code_verifier is required (PKCE).', 400 );
		}
		$expected = rtrim( strtr( base64_encode( hash( 'sha256', $code_verifier, true ) ), '+/', '-_' ), '=' );
		if ( ! hash_equals( $record['code_challenge'], $expected ) ) {
			return $this->oauth_error( 'invalid_grant', 'PKCE verification failed.', 400 );
		}

		return $this->issue_tokens(
			(int) $record['user_id'],
			$client['client_id'],
			(string) $record['scope'],
			(string) $record['resource']
		);
	}

	private function grant_refresh_token( WP_REST_Request $request, array $client ) {
		$refresh = (string) $request->get_param( 'refresh_token' );
		if ( '' === $refresh ) {
			return $this->oauth_error( 'invalid_request', 'refresh_token is required.', 400 );
		}
		$record = $this->store->consume_refresh_token( $refresh );
		if ( ! $record ) {
			return $this->oauth_error( 'invalid_grant', 'refresh_token is invalid or expired.', 400 );
		}
		if ( ( $record['client_id'] ?? null ) !== $client['client_id'] ) {
			return $this->oauth_error( 'invalid_grant', 'Refresh token was issued to a different client.', 400 );
		}

		return $this->issue_tokens(
			(int) $record['user_id'],
			$client['client_id'],
			(string) ( $record['scope'] ?? self::SCOPE ),
			(string) ( $record['resource'] ?? $this->mcp_resource_url() )
		);
	}

	private function issue_tokens( int $user_id, string $client_id, string $scope, string $resource ): WP_REST_Response {
		$access_token  = $this->store->save_access_token( array(
			'user_id'   => $user_id,
			'client_id' => $client_id,
			'scope'     => $scope,
			'resource'  => $resource,
			'issued_at' => time(),
		) );
		$refresh_token = $this->store->save_refresh_token( array(
			'user_id'   => $user_id,
			'client_id' => $client_id,
			'scope'     => $scope,
			'resource'  => $resource,
		) );

		return new WP_REST_Response( array(
			'access_token'  => $access_token,
			'token_type'    => 'Bearer',
			'expires_in'    => WPAIC_OAuth_Store::TOKEN_TTL,
			'refresh_token' => $refresh_token,
			'scope'         => $scope,
		), 200 );
	}

	/* ===================================================================
	 * Revocation (RFC 7009)
	 * =================================================================== */

	public function handle_revoke( WP_REST_Request $request ) {
		$token = (string) $request->get_param( 'token' );
		if ( '' === $token ) {
			return new WP_REST_Response( null, 200 );
		}
		// Try as access token first, then refresh.
		$this->store->revoke_access_token( $token );
		$this->store->consume_refresh_token( $token );
		return new WP_REST_Response( null, 200 );
	}

	/* ===================================================================
	 * Helpers used by the MCP server to validate Bearer tokens
	 * =================================================================== */

	/**
	 * Return the user_id for a valid Bearer token bound to the MCP resource,
	 * or null if the token is missing/invalid/wrong-audience.
	 */
	public function resolve_bearer_token( string $token ): ?int {
		$data = $this->store->get_access_token_data( $token );
		if ( ! $data ) {
			return null;
		}
		// Audience binding (RFC 8707). Be lenient on trailing-slash differences.
		$expected = rtrim( $this->mcp_resource_url(), '/' );
		$actual   = rtrim( (string) ( $data['resource'] ?? '' ), '/' );
		if ( $expected !== $actual ) {
			return null;
		}
		return (int) ( $data['user_id'] ?? 0 ) ?: null;
	}

	public function www_authenticate_header(): string {
		$metadata_url = home_url( self::WELL_KNOWN_PR_PATH );
		return sprintf( 'Bearer realm="MCP", resource_metadata="%s"', $metadata_url );
	}

	/* ===================================================================
	 * Internals
	 * =================================================================== */

	private function endpoint_url( string $name ): string {
		return rest_url( WPAIC_REST_NAMESPACE . '/oauth/' . $name );
	}

	private function issuer(): string {
		return home_url();
	}

	private function mcp_resource_url(): string {
		return rest_url( WPAIC_REST_NAMESPACE . '/mcp' );
	}

	private function current_url(): string {
		$scheme = is_ssl() ? 'https' : 'http';
		$host   = $_SERVER['HTTP_HOST'] ?? wp_parse_url( home_url(), PHP_URL_HOST );
		$uri    = $_SERVER['REQUEST_URI'] ?? '/';
		return $scheme . '://' . $host . $uri;
	}

	/**
	 * Pull client_id/secret from either HTTP Basic header or POST body.
	 */
	private function extract_client_credentials( WP_REST_Request $request ): array {
		$auth = $this->get_header( 'authorization' );
		if ( $auth && 0 === stripos( $auth, 'basic ' ) ) {
			$decoded = base64_decode( substr( $auth, 6 ), true );
			if ( false !== $decoded && false !== strpos( $decoded, ':' ) ) {
				list( $u, $p ) = array_pad( explode( ':', $decoded, 2 ), 2, '' );
				return array( urldecode( $u ), urldecode( $p ) );
			}
		}
		return array(
			(string) $request->get_param( 'client_id' ),
			(string) $request->get_param( 'client_secret' ),
		);
	}

	private function get_header( string $name ): string {
		$key = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
		if ( ! empty( $_SERVER[ $key ] ) ) {
			return (string) $_SERVER[ $key ];
		}
		if ( function_exists( 'getallheaders' ) ) {
			foreach ( getallheaders() as $h => $v ) {
				if ( 0 === strcasecmp( $h, $name ) ) {
					return (string) $v;
				}
			}
		}
		return '';
	}

	private function append_query( string $url, array $params ): string {
		$sep = ( false === strpos( $url, '?' ) ) ? '?' : '&';
		return $url . $sep . http_build_query( $params );
	}

	private function redirect_with_error( string $redirect_uri, string $error, string $state = '', string $description = '' ) {
		$params = array( 'error' => $error );
		if ( '' !== $description ) {
			$params['error_description'] = $description;
		}
		if ( '' !== $state ) {
			$params['state'] = $state;
		}
		wp_safe_redirect( $this->append_query( $redirect_uri, $params ) );
		exit;
	}

	private function oauth_error( string $code, string $description, int $status ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'error'             => $code,
				'error_description' => $description,
			),
			$status
		);
	}

	private function emit_json( array $data ): void {
		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode( $data );
		exit;
	}

	private function emit_html_error( string $code, string $message ): void {
		nocache_headers();
		status_header( 400 );
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!doctype html><meta charset="utf-8"><title>OAuth error</title>';
		echo '<div style="font-family:-apple-system,sans-serif;max-width:480px;margin:48px auto;padding:32px;background:#fff;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.06)">';
		echo '<h1 style="margin:0 0 8px;font-size:20px">' . esc_html__( 'Authorization error', 'wp-ai-connector' ) . '</h1>';
		echo '<p style="color:#50575e"><code>' . esc_html( $code ) . '</code></p>';
		echo '<p>' . esc_html( $message ) . '</p>';
		echo '</div>';
		exit;
	}
}
