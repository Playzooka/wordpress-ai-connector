<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Storage layer for OAuth clients, codes, and tokens.
 * - Clients & refresh tokens: WP options (durable, small).
 * - Authorization codes & access tokens: WP transients (auto-expire).
 */
class WPAIC_OAuth_Store {
	const CLIENTS_OPTION = 'wpaic_oauth_clients';
	const REFRESH_OPTION = 'wpaic_oauth_refresh_tokens';
	const CODE_PREFIX    = 'wpaic_oauth_code_';
	const TOKEN_PREFIX   = 'wpaic_oauth_token_';

	const CODE_TTL    = 600;     // 10 minutes
	const TOKEN_TTL   = 3600;    // 1 hour
	const REFRESH_TTL = 2592000; // 30 days

	/**
	 * Create a new client (Dynamic Client Registration).
	 * Returns the client metadata; client_secret is included plaintext only here.
	 */
	public function create_client( array $metadata ): array {
		$client_id        = wp_generate_uuid4();
		$auth_method      = (string) ( $metadata['token_endpoint_auth_method'] ?? 'none' );
		$is_confidential  = ( 'none' !== $auth_method );
		$client_secret    = $is_confidential ? bin2hex( random_bytes( 32 ) ) : null;

		$record = array(
			'client_id'                  => $client_id,
			'client_name'                => sanitize_text_field( (string) ( $metadata['client_name'] ?? 'MCP Client' ) ),
			'client_uri'                 => esc_url_raw( (string) ( $metadata['client_uri'] ?? '' ) ),
			'logo_uri'                   => esc_url_raw( (string) ( $metadata['logo_uri'] ?? '' ) ),
			'redirect_uris'              => array_values( array_filter( array_map( 'esc_url_raw', (array) ( $metadata['redirect_uris'] ?? array() ) ) ) ),
			'grant_types'                => array_values( (array) ( $metadata['grant_types'] ?? array( 'authorization_code', 'refresh_token' ) ) ),
			'response_types'             => array_values( (array) ( $metadata['response_types'] ?? array( 'code' ) ) ),
			'token_endpoint_auth_method' => $auth_method,
			'scope'                      => (string) ( $metadata['scope'] ?? 'mcp' ),
			'client_secret_hash'         => $client_secret ? hash( 'sha256', $client_secret ) : null,
			'created_at'                 => time(),
		);

		if ( empty( $record['redirect_uris'] ) ) {
			throw new RuntimeException( 'redirect_uris is required' );
		}

		$all                  = $this->get_clients();
		$all[ $client_id ]    = $record;
		update_option( self::CLIENTS_OPTION, $all, false );

		// Return with plaintext secret (only available at registration time).
		$response = $record;
		unset( $response['client_secret_hash'] );
		if ( $client_secret ) {
			$response['client_secret'] = $client_secret;
		}
		return $response;
	}

	public function get_client( string $client_id ): ?array {
		$all = $this->get_clients();
		return $all[ $client_id ] ?? null;
	}

	public function get_clients(): array {
		$all = get_option( self::CLIENTS_OPTION, array() );
		return is_array( $all ) ? $all : array();
	}

	public function delete_client( string $client_id ): bool {
		$all = $this->get_clients();
		if ( ! isset( $all[ $client_id ] ) ) {
			return false;
		}
		unset( $all[ $client_id ] );
		update_option( self::CLIENTS_OPTION, $all, false );
		$this->revoke_refresh_tokens_for_client( $client_id );
		return true;
	}

	public function verify_client_secret( string $client_id, string $secret ): bool {
		$client = $this->get_client( $client_id );
		if ( ! $client || empty( $client['client_secret_hash'] ) ) {
			return false;
		}
		return hash_equals( $client['client_secret_hash'], hash( 'sha256', $secret ) );
	}

	/* ---------------- Authorization codes ---------------- */

	public function save_code( array $data ): string {
		$code = bin2hex( random_bytes( 32 ) );
		set_transient( self::CODE_PREFIX . $code, $data, self::CODE_TTL );
		return $code;
	}

	public function consume_code( string $code ): ?array {
		$key  = self::CODE_PREFIX . $code;
		$data = get_transient( $key );
		if ( false === $data ) {
			return null;
		}
		delete_transient( $key );
		return $data;
	}

	/* ---------------- Access tokens (hashed) ---------------- */

	public function save_access_token( array $data ): string {
		$token = bin2hex( random_bytes( 32 ) );
		$hash  = hash( 'sha256', $token );
		set_transient( self::TOKEN_PREFIX . $hash, $data, self::TOKEN_TTL );
		return $token;
	}

	public function get_access_token_data( string $token ): ?array {
		$hash = hash( 'sha256', $token );
		$data = get_transient( self::TOKEN_PREFIX . $hash );
		return false === $data ? null : $data;
	}

	public function revoke_access_token( string $token ): void {
		$hash = hash( 'sha256', $token );
		delete_transient( self::TOKEN_PREFIX . $hash );
	}

	/* ---------------- Refresh tokens ---------------- */

	public function save_refresh_token( array $data ): string {
		$token = bin2hex( random_bytes( 32 ) );
		$hash  = hash( 'sha256', $token );

		$record = array_merge(
			$data,
			array(
				'hash'       => $hash,
				'expires_at' => time() + self::REFRESH_TTL,
				'issued_at'  => time(),
			)
		);

		$all = $this->get_refresh_tokens();
		// Garbage-collect expired tokens.
		$now = time();
		$all = array_filter( $all, static function ( $r ) use ( $now ) {
			return isset( $r['expires_at'] ) && $r['expires_at'] > $now;
		} );
		$all[ $hash ] = $record;
		update_option( self::REFRESH_OPTION, $all, false );

		return $token;
	}

	public function consume_refresh_token( string $token ): ?array {
		$hash = hash( 'sha256', $token );
		$all  = $this->get_refresh_tokens();
		if ( ! isset( $all[ $hash ] ) ) {
			return null;
		}
		$data = $all[ $hash ];
		// Rotation: invalidate the consumed token whether or not it's expired.
		unset( $all[ $hash ] );
		update_option( self::REFRESH_OPTION, $all, false );

		if ( ! isset( $data['expires_at'] ) || $data['expires_at'] <= time() ) {
			return null;
		}
		return $data;
	}

	public function revoke_refresh_tokens_for_client( string $client_id ): int {
		$all     = $this->get_refresh_tokens();
		$before  = count( $all );
		$all     = array_filter( $all, static function ( $r ) use ( $client_id ) {
			return ( $r['client_id'] ?? null ) !== $client_id;
		} );
		update_option( self::REFRESH_OPTION, $all, false );
		return $before - count( $all );
	}

	public function revoke_refresh_tokens_for_user_client( int $user_id, string $client_id ): int {
		$all     = $this->get_refresh_tokens();
		$before  = count( $all );
		$all     = array_filter(
			$all,
			static function ( $r ) use ( $user_id, $client_id ) {
				return ! ( ( $r['user_id'] ?? 0 ) === $user_id && ( $r['client_id'] ?? null ) === $client_id );
			}
		);
		update_option( self::REFRESH_OPTION, $all, false );
		return $before - count( $all );
	}

	public function get_refresh_tokens(): array {
		$all = get_option( self::REFRESH_OPTION, array() );
		return is_array( $all ) ? $all : array();
	}

	/**
	 * Group refresh tokens by (client_id, user_id) for the admin "Connected apps" UI.
	 */
	public function list_authorizations(): array {
		$clients = $this->get_clients();
		$refresh = $this->get_refresh_tokens();
		$grouped = array();
		foreach ( $refresh as $r ) {
			$client_id = $r['client_id'] ?? '';
			$user_id   = (int) ( $r['user_id'] ?? 0 );
			$key       = $client_id . ':' . $user_id;
			if ( ! isset( $grouped[ $key ] ) ) {
				$grouped[ $key ] = array(
					'client_id'   => $client_id,
					'client_name' => $clients[ $client_id ]['client_name'] ?? '(deleted client)',
					'user_id'     => $user_id,
					'issued_at'   => $r['issued_at'] ?? null,
				);
			} else {
				// Take the most recent issued_at.
				$grouped[ $key ]['issued_at'] = max( $grouped[ $key ]['issued_at'] ?? 0, $r['issued_at'] ?? 0 );
			}
		}
		// Newest first.
		usort( $grouped, static function ( $a, $b ) {
			return ( $b['issued_at'] ?? 0 ) <=> ( $a['issued_at'] ?? 0 );
		} );
		return array_values( $grouped );
	}
}
