<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAIC_Admin {
	private WPAIC_Tool_Registry $registry;

	public function __construct( WPAIC_Tool_Registry $registry ) {
		$this->registry = $registry;
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

		$endpoint    = rest_url( WPAIC_REST_NAMESPACE . '/mcp' );
		$user        = wp_get_current_user();
		$profile_url = get_edit_user_link( $user->ID ) . '#application-passwords-section';
		$tools       = $this->registry->list_tools();
		?>
		<div class="wrap wpaic-wrap">
			<h1><?php esc_html_e( 'WordPress AI Connector', 'wp-ai-connector' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Use the details below to connect this site to Claude, ChatGPT, or any MCP-compatible client.', 'wp-ai-connector' ); ?>
			</p>

			<h2><?php esc_html_e( 'Your MCP endpoint', 'wp-ai-connector' ); ?></h2>
			<p><?php esc_html_e( 'Paste this URL into your AI client when adding a custom MCP connector.', 'wp-ai-connector' ); ?></p>
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

			<h2><?php esc_html_e( 'Setup in 3 steps', 'wp-ai-connector' ); ?></h2>
			<ol class="wpaic-steps">
				<li>
					<strong><?php esc_html_e( 'Create an Application Password.', 'wp-ai-connector' ); ?></strong>
					<?php
					printf(
						/* translators: %s: link to the user's profile page. */
						' ' . esc_html__( 'Go to %s, scroll to "Application Passwords", give it a name like "claude" or "chatgpt", and click Add. Copy the generated password immediately — WordPress shows it only once.', 'wp-ai-connector' ),
						'<a href="' . esc_url( $profile_url ) . '">' . esc_html__( 'your profile', 'wp-ai-connector' ) . '</a>'
					);
					?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Add a custom connector in your AI client.', 'wp-ai-connector' ); ?></strong>
					<ul>
						<li>
							<strong><?php esc_html_e( 'URL:', 'wp-ai-connector' ); ?></strong>
							<code><?php echo esc_html( $endpoint ); ?></code>
						</li>
						<li>
							<strong><?php esc_html_e( 'Auth:', 'wp-ai-connector' ); ?></strong>
							<?php esc_html_e( 'HTTP Basic.', 'wp-ai-connector' ); ?>
							<?php esc_html_e( 'Username = your WordPress username.', 'wp-ai-connector' ); ?>
							<?php esc_html_e( 'Password = the Application Password from step 1 (with or without spaces, both work).', 'wp-ai-connector' ); ?>
						</li>
						<li>
							<?php
							printf(
								/* translators: 1: link to Claude docs, 2: link to ChatGPT docs */
								esc_html__( 'See client-specific instructions: %1$s · %2$s', 'wp-ai-connector' ),
								'<a href="https://support.claude.com/en/articles/11175166-getting-started-with-custom-connectors-using-remote-mcp" target="_blank" rel="noopener">Claude custom connectors</a>',
								'<a href="https://platform.openai.com/docs/guides/developer-mode" target="_blank" rel="noopener">ChatGPT custom connectors</a>'
							);
							?>
						</li>
					</ul>
				</li>
				<li>
					<strong><?php esc_html_e( 'Ask the AI to do something.', 'wp-ai-connector' ); ?></strong>
					<?php esc_html_e( 'Try "list my latest 5 draft posts" or "create a draft post titled Hello World".', 'wp-ai-connector' ); ?>
				</li>
			</ol>

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
			.wpaic-wrap .wpaic-steps > li { margin-bottom: 12px; }
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
}
