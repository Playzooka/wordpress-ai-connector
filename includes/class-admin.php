<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAIC_Admin {
	private WPAIC_Tool_Registry $registry;
	private WPAIC_OAuth_Store $oauth_store;
	private WPAIC_Request_Log $request_log;

	public function __construct( WPAIC_Tool_Registry $registry, WPAIC_OAuth_Store $oauth_store, WPAIC_Request_Log $request_log ) {
		$this->registry    = $registry;
		$this->oauth_store = $oauth_store;
		$this->request_log = $request_log;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WPAIC_PLUGIN_FILE ), array( $this, 'plugin_action_links' ) );
	}

	public function add_menu(): void {
		add_menu_page(
			__( 'AI Connector', 'wp-ai-connector' ),
			__( 'AI Connector', 'wp-ai-connector' ),
			'manage_options',
			'wp-ai-connector',
			array( $this, 'render_page' ),
			'dashicons-rest-api',
			80
		);
	}

	public function plugin_action_links( array $links ): array {
		$url = admin_url( 'admin.php?page=wp-ai-connector' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'wp-ai-connector' ) . '</a>' );
		return $links;
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-ai-connector' ) );
		}

		$notice = $this->handle_admin_post();

		$endpoint       = rest_url( WPAIC_REST_NAMESPACE . '/mcp' );
		$user           = wp_get_current_user();
		$profile_url    = get_edit_user_link( $user->ID ) . '#application-passwords-section';
		$tools          = $this->registry->list_tools();
		$authorizations = $this->oauth_store->list_authorizations();
		?>
		<div class="wrap wpaic-wrap">
			<h1><?php esc_html_e( 'WordPress AI Connector', 'wp-ai-connector' ); ?></h1>

			<?php if ( '' !== $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<p class="description">
				<?php esc_html_e( 'Use the details below to connect this site to Claude, ChatGPT, or any MCP-compatible client.', 'wp-ai-connector' ); ?>
			</p>

			<h2><?php esc_html_e( 'Your MCP endpoint', 'wp-ai-connector' ); ?></h2>
			<p><?php esc_html_e( 'Paste this URL into your AI client when adding a custom MCP connector. ChatGPT and Claude will discover the OAuth flow automatically — no extra credentials to paste.', 'wp-ai-connector' ); ?></p>
			<div class="wpaic-copy-row">
				<input
					type="text"
					id="wpaic-endpoint"
					class="regular-text code"
					readonly
					value="<?php echo esc_attr( $endpoint ); ?>"
					onfocus="this.select();"
				/>
				<button type="button" class="button" data-wpaic-copy="#wpaic-endpoint">
					<?php esc_html_e( 'Copy', 'wp-ai-connector' ); ?>
				</button>
			</div>

			<h2><?php esc_html_e( 'How to connect', 'wp-ai-connector' ); ?></h2>
			<p><?php esc_html_e( 'This plugin supports two authentication methods. Pick the one your AI client offers:', 'wp-ai-connector' ); ?></p>

			<h3><?php esc_html_e( 'OAuth (recommended — required for ChatGPT)', 'wp-ai-connector' ); ?></h3>
			<ol class="wpaic-steps">
				<li><?php esc_html_e( 'In your AI client, add a custom MCP connector and paste the endpoint URL above.', 'wp-ai-connector' ); ?></li>
				<li><?php esc_html_e( 'The client will open this site in a browser. Log in if needed and click "Approve" on the consent screen.', 'wp-ai-connector' ); ?></li>
				<li><?php esc_html_e( 'You\'re done. The connection appears under "Connected apps" below.', 'wp-ai-connector' ); ?></li>
			</ol>

			<h3><?php esc_html_e( 'Application Password (works with Claude, MCP CLIs, scripts)', 'wp-ai-connector' ); ?></h3>
			<ol class="wpaic-steps">
				<li>
					<?php
					printf(
						/* translators: %s: link to the user's profile page. */
						esc_html__( 'Open %s, scroll to "Application Passwords", add one named e.g. "claude", and copy the generated password (shown only once).', 'wp-ai-connector' ),
						'<a href="' . esc_url( $profile_url ) . '">' . esc_html__( 'your profile', 'wp-ai-connector' ) . '</a>'
					);
					?>
				</li>
				<li><?php esc_html_e( 'In the AI client, use HTTP Basic auth: username = your WordPress username, password = the generated Application Password.', 'wp-ai-connector' ); ?></li>
			</ol>

			<p>
				<?php
				printf(
					/* translators: 1: Claude docs link, 2: ChatGPT docs link */
					esc_html__( 'Client-specific docs: %1$s · %2$s', 'wp-ai-connector' ),
					'<a href="https://support.claude.com/en/articles/11175166-getting-started-with-custom-connectors-using-remote-mcp" target="_blank" rel="noopener">Claude custom connectors</a>',
					'<a href="https://platform.openai.com/docs/guides/developer-mode" target="_blank" rel="noopener">ChatGPT custom connectors</a>'
				);
				?>
			</p>

			<?php
			$clients      = $this->oauth_store->get_clients();
			$fresh_secret = get_transient( 'wpaic_last_client_secret' );
			if ( $fresh_secret ) {
				delete_transient( 'wpaic_last_client_secret' );
			}
			?>
			<h2><?php esc_html_e( 'Pre-registered OAuth clients (advanced)', 'wp-ai-connector' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'For clients that don\'t auto-register via DCR (notably Claude when "start_error" appears, or ChatGPT). Create a client here, then paste the Client ID / Secret into the AI client\'s Advanced OAuth settings.', 'wp-ai-connector' ); ?>
			</p>

			<?php if ( $fresh_secret && ! empty( $fresh_secret['client_secret'] ) ) : ?>
				<div class="notice notice-warning inline" style="padding:12px;border-left-width:4px">
					<p><strong><?php esc_html_e( 'Copy these credentials now — the Client Secret will not be shown again.', 'wp-ai-connector' ); ?></strong></p>
					<table class="form-table" style="margin-top:8px">
						<tr>
							<th style="width:160px"><?php esc_html_e( 'Client ID', 'wp-ai-connector' ); ?></th>
							<td><code style="user-select:all"><?php echo esc_html( $fresh_secret['client_id'] ); ?></code></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Client Secret', 'wp-ai-connector' ); ?></th>
							<td><code style="user-select:all;word-break:break-all"><?php echo esc_html( $fresh_secret['client_secret'] ); ?></code></td>
						</tr>
					</table>
				</div>
			<?php endif; ?>

			<form method="post" style="background:#f6f7f7;padding:16px;border-radius:6px;max-width:720px">
				<input type="hidden" name="wpaic_action" value="register_client" />
				<?php wp_nonce_field( 'wpaic_register_client' ); ?>
				<table class="form-table">
					<tr>
						<th style="width:160px"><label for="wpaic-client-name"><?php esc_html_e( 'Client name', 'wp-ai-connector' ); ?></label></th>
						<td><input type="text" id="wpaic-client-name" name="client_name" class="regular-text" placeholder="claude-manual" required /></td>
					</tr>
					<tr>
						<th><label for="wpaic-client-redirects"><?php esc_html_e( 'Redirect URIs', 'wp-ai-connector' ); ?></label></th>
						<td>
							<textarea id="wpaic-client-redirects" name="redirect_uris" class="large-text code" rows="3" placeholder="https://claude.ai/api/mcp/auth_callback"></textarea>
							<p class="description">
								<?php esc_html_e( 'One per line. Common values:', 'wp-ai-connector' ); ?><br />
								<code>https://claude.ai/api/mcp/auth_callback</code><br />
								<code>https://chatgpt.com/connector_platform_oauth_redirect</code><br />
								<code>http://localhost:6274/oauth/callback/debug</code> (<?php esc_html_e( 'MCP Inspector', 'wp-ai-connector' ); ?>)
							</p>
						</td>
					</tr>
				</table>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Create client', 'wp-ai-connector' ); ?></button></p>
			</form>

			<?php if ( ! empty( $clients ) ) : ?>
				<table class="widefat striped" style="margin-top:16px">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Client', 'wp-ai-connector' ); ?></th>
							<th><?php esc_html_e( 'Client ID', 'wp-ai-connector' ); ?></th>
							<th><?php esc_html_e( 'Redirect URIs', 'wp-ai-connector' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $clients as $c ) : ?>
						<tr>
							<td><?php echo esc_html( $c['client_name'] ?? '' ); ?></td>
							<td><code style="user-select:all"><?php echo esc_html( $c['client_id'] ); ?></code></td>
							<td style="font-size:11px">
								<?php foreach ( (array) ( $c['redirect_uris'] ?? array() ) as $uri ) : ?>
									<div><code><?php echo esc_html( $uri ); ?></code></div>
								<?php endforeach; ?>
							</td>
							<td>
								<form method="post" style="margin:0" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this client? All tokens issued to it will be revoked.', 'wp-ai-connector' ) ); ?>')">
									<input type="hidden" name="wpaic_action" value="delete_client" />
									<input type="hidden" name="client_id" value="<?php echo esc_attr( $c['client_id'] ); ?>" />
									<?php wp_nonce_field( 'wpaic_delete_client_' . $c['client_id'] ); ?>
									<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Delete', 'wp-ai-connector' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Connected apps', 'wp-ai-connector' ); ?></h2>
			<?php if ( empty( $authorizations ) ) : ?>
				<p class="description"><?php esc_html_e( 'No apps are connected yet. Add this site as an MCP connector in Claude or ChatGPT to authorize one.', 'wp-ai-connector' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'App', 'wp-ai-connector' ); ?></th>
							<th><?php esc_html_e( 'Authorized by', 'wp-ai-connector' ); ?></th>
							<th><?php esc_html_e( 'Authorized', 'wp-ai-connector' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $authorizations as $auth ) :
						$auth_user      = get_userdata( (int) $auth['user_id'] );
						$auth_user_name = $auth_user ? $auth_user->display_name : '(deleted user)';
						$issued_label   = $auth['issued_at']
							? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $auth['issued_at'] )
							: '—';
						?>
						<tr>
							<td><strong><?php echo esc_html( $auth['client_name'] ); ?></strong></td>
							<td><?php echo esc_html( $auth_user_name ); ?></td>
							<td><?php echo esc_html( $issued_label ); ?></td>
							<td>
								<form method="post" style="margin:0">
									<input type="hidden" name="wpaic_action" value="revoke" />
									<input type="hidden" name="client_id" value="<?php echo esc_attr( $auth['client_id'] ); ?>" />
									<input type="hidden" name="user_id"   value="<?php echo esc_attr( (string) $auth['user_id'] ); ?>" />
									<?php wp_nonce_field( 'wpaic_revoke_' . $auth['client_id'] . '_' . $auth['user_id'] ); ?>
									<button type="submit" class="button button-link-delete">
										<?php esc_html_e( 'Revoke', 'wp-ai-connector' ); ?>
									</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description">
					<?php esc_html_e( 'Revoking removes the refresh token. Any access tokens already issued expire within one hour.', 'wp-ai-connector' ); ?>
				</p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Quick test from a terminal', 'wp-ai-connector' ); ?></h2>
			<p><?php esc_html_e( 'Confirm the endpoint works before configuring an AI client. Replace USER and APP_PASSWORD, then run:', 'wp-ai-connector' ); ?></p>
			<pre class="wpaic-pre"><code><?php
				$curl = sprintf(
					"curl -u 'USER:APP_PASSWORD' \\\n  -H 'Content-Type: application/json' \\\n  -d '{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/list\"}' \\\n  %s",
					$endpoint
				);
				echo esc_html( $curl );
			?></code></pre>
			<p class="description">
				<?php esc_html_e( 'A successful response is a JSON object whose "result.tools" array lists the tools below.', 'wp-ai-connector' ); ?>
			</p>

			<h2><?php
				/* translators: %d: number of tools available. */
				printf( esc_html__( 'Available tools (%d)', 'wp-ai-connector' ), count( $tools ) );
			?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Tool', 'wp-ai-connector' ); ?></th>
						<th><?php esc_html_e( 'Description', 'wp-ai-connector' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $tools as $tool ) : ?>
					<tr>
						<td><code><?php echo esc_html( $tool['name'] ); ?></code></td>
						<td><?php echo esc_html( $tool['description'] ?? '' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php $entries = array_reverse( $this->request_log->all() ); ?>
			<h2 style="display:flex;align-items:center;gap:12px">
				<?php
				/* translators: %d: number of entries in the request log */
				printf( esc_html__( 'Request log (%d)', 'wp-ai-connector' ), count( $entries ) );
				?>
				<?php if ( ! empty( $entries ) ) : ?>
					<form method="post" style="margin:0">
						<input type="hidden" name="wpaic_action" value="clear_log" />
						<?php wp_nonce_field( 'wpaic_clear_log' ); ?>
						<button type="submit" class="button button-small"><?php esc_html_e( 'Clear', 'wp-ai-connector' ); ?></button>
					</form>
				<?php endif; ?>
			</h2>
			<p class="description">
				<?php esc_html_e( 'Recent requests to the plugin\'s endpoints. Useful for diagnosing MCP client discovery flows. Disable by adding define(\'WPAIC_DEBUG_REQUESTS\', false); to wp-config.php.', 'wp-ai-connector' ); ?>
			</p>
			<?php if ( empty( $entries ) ) : ?>
				<p class="description"><?php esc_html_e( 'No requests recorded yet.', 'wp-ai-connector' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th style="width:80px"><?php esc_html_e( 'Time', 'wp-ai-connector' ); ?></th>
							<th style="width:60px"><?php esc_html_e( 'Method', 'wp-ai-connector' ); ?></th>
							<th><?php esc_html_e( 'Path', 'wp-ai-connector' ); ?></th>
							<th style="width:60px"><?php esc_html_e( 'Status', 'wp-ai-connector' ); ?></th>
							<th><?php esc_html_e( 'Details', 'wp-ai-connector' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $entries as $entry ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( 'H:i:s', (int) $entry['time'] ) ); ?></td>
							<td><code><?php echo esc_html( $entry['method'] ); ?></code></td>
							<td style="word-break:break-all"><code><?php echo esc_html( $entry['path'] ); ?></code></td>
							<td><?php echo esc_html( null === $entry['status'] ? '—' : (string) $entry['status'] ); ?></td>
							<td>
								<?php if ( ! empty( $entry['ua'] ) ) : ?>
									<div style="color:#1d2327;font-size:12px"><strong>UA:</strong> <?php echo esc_html( $entry['ua'] ); ?></div>
								<?php endif; ?>
								<?php if ( ! empty( $entry['origin'] ) ) : ?>
									<div style="color:#646970;font-size:11px"><strong>Origin:</strong> <?php echo esc_html( $entry['origin'] ); ?></div>
								<?php endif; ?>
								<?php if ( ! empty( $entry['headers'] ) && is_array( $entry['headers'] ) ) : ?>
									<div style="color:#646970;font-size:11px;margin-top:4px">
										<?php foreach ( $entry['headers'] as $name => $value ) : ?>
											<div><strong><?php echo esc_html( $name ); ?>:</strong> <code><?php echo esc_html( $value ); ?></code></div>
										<?php endforeach; ?>
									</div>
								<?php endif; ?>
								<?php if ( ! empty( $entry['body'] ) ) : ?>
									<details style="margin-top:4px">
										<summary style="cursor:pointer;color:#2271b1;font-size:11px"><?php esc_html_e( 'Body', 'wp-ai-connector' ); ?></summary>
										<pre style="background:#f0f0f1;padding:6px;border-radius:3px;font-size:11px;overflow-x:auto;margin:4px 0 0;white-space:pre-wrap;word-break:break-all"><?php echo esc_html( $entry['body'] ); ?></pre>
									</details>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'About', 'wp-ai-connector' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: 1: plugin version, 2: GitHub repo link */
					esc_html__( 'WordPress AI Connector v%1$s — source: %2$s', 'wp-ai-connector' ),
					esc_html( WPAIC_VERSION ),
					'<a href="https://github.com/Playzooka/wordpress-ai-connector" target="_blank" rel="noopener">github.com/Playzooka/wordpress-ai-connector</a>'
				);
				?>
			</p>
		</div>

		<style>
			.wpaic-wrap .wpaic-copy-row { display: flex; gap: 8px; align-items: center; max-width: 720px; }
			.wpaic-wrap .wpaic-copy-row input { flex: 1; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
			.wpaic-wrap .wpaic-steps > li { margin-bottom: 8px; }
			.wpaic-wrap .wpaic-pre { background: #1d2327; color: #f0f0f1; padding: 12px 16px; border-radius: 4px; overflow-x: auto; max-width: 100%; }
			.wpaic-wrap .wpaic-pre code { background: transparent; color: inherit; font-size: 12px; line-height: 1.5; }
		</style>
		<script>
			(function () {
				document.querySelectorAll('[data-wpaic-copy]').forEach(function (btn) {
					btn.addEventListener('click', function () {
						var target = document.querySelector(btn.getAttribute('data-wpaic-copy'));
						if (!target) return;
						target.select();
						navigator.clipboard.writeText(target.value).then(function () {
							var original = btn.textContent;
							btn.textContent = '<?php echo esc_js( __( 'Copied!', 'wp-ai-connector' ) ); ?>';
							setTimeout(function () { btn.textContent = original; }, 1500);
						});
					});
				});
			})();
		</script>
		<?php
	}

	/**
	 * Handle admin-page POST actions. Returns a notice string for success.
	 */
	private function handle_admin_post(): string {
		if ( ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) !== 'POST' ) {
			return '';
		}
		$action = sanitize_text_field( wp_unslash( $_POST['wpaic_action'] ?? '' ) );

		if ( 'clear_log' === $action ) {
			check_admin_referer( 'wpaic_clear_log' );
			$this->request_log->clear();
			return __( 'Request log cleared.', 'wp-ai-connector' );
		}

		if ( 'revoke' === $action ) {
			$client_id = sanitize_text_field( wp_unslash( $_POST['client_id'] ?? '' ) );
			$user_id   = (int) ( $_POST['user_id'] ?? 0 );
			check_admin_referer( 'wpaic_revoke_' . $client_id . '_' . $user_id );

			$count = $this->oauth_store->revoke_refresh_tokens_for_user_client( $user_id, $client_id );
			return sprintf(
				/* translators: %d: number of revoked authorizations */
				_n(
					'Revoked %d authorization. Access tokens already issued will expire within one hour.',
					'Revoked %d authorizations. Access tokens already issued will expire within one hour.',
					$count,
					'wp-ai-connector'
				),
				$count
			);
		}

		if ( 'register_client' === $action ) {
			check_admin_referer( 'wpaic_register_client' );
			$name         = sanitize_text_field( wp_unslash( $_POST['client_name'] ?? 'Manual client' ) );
			$redirect_raw = (string) wp_unslash( $_POST['redirect_uris'] ?? '' );
			$redirects    = array_values( array_filter( array_map( 'trim', preg_split( '/\s+/', $redirect_raw ) ?: array() ) ) );
			if ( empty( $redirects ) ) {
				return __( 'At least one redirect URI is required.', 'wp-ai-connector' );
			}
			try {
				$client = $this->oauth_store->create_client( array(
					'client_name'                => $name,
					'redirect_uris'              => $redirects,
					'token_endpoint_auth_method' => 'client_secret_basic',
					'grant_types'                => array( 'authorization_code', 'refresh_token' ),
					'response_types'             => array( 'code' ),
				) );
			} catch ( Throwable $e ) {
				return __( 'Failed to create client: ', 'wp-ai-connector' ) . $e->getMessage();
			}
			// Stash the credentials in a one-shot transient so we can show
			// the secret on the next page load. After that the secret is gone.
			set_transient( 'wpaic_last_client_secret', array(
				'client_id'     => $client['client_id'],
				'client_secret' => $client['client_secret'] ?? '',
			), 5 * MINUTE_IN_SECONDS );
			return __( 'Client created. The Client Secret is shown once below — copy it now.', 'wp-ai-connector' );
		}

		if ( 'delete_client' === $action ) {
			$client_id = sanitize_text_field( wp_unslash( $_POST['client_id'] ?? '' ) );
			check_admin_referer( 'wpaic_delete_client_' . $client_id );
			$this->oauth_store->delete_client( $client_id );
			return __( 'Client deleted. All tokens issued to it have been revoked.', 'wp-ai-connector' );
		}

		return '';
	}
}
