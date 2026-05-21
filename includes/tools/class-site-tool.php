<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAIC_Site_Tool {
	public function register( WPAIC_Tool_Registry $registry ): void {
		$registry->register_tool(
			'wp_get_site_info',
			array(
				'description' => 'Read-only information about this WordPress site (name, URL, version, active theme, active plugins).',
				// properties MUST be a JSON object; an empty PHP array would
				// encode to [] which is invalid JSON Schema and causes strict
				// tool-list validators (Claude) to reject the whole tool list.
				'inputSchema' => array( 'type' => 'object', 'properties' => (object) array() ),
			),
			array( $this, 'get_site_info' ),
			'edit_posts'
		);

		$registry->register_tool(
			'wp_list_users',
			array(
				'description' => 'List users on this site.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'role'     => array( 'type' => 'string', 'description' => 'Filter by role slug (e.g. "administrator", "editor").' ),
						'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ),
						'page'     => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
					),
				),
			),
			array( $this, 'list_users' ),
			'list_users'
		);
	}

	public function get_site_info( array $args ): array {
		global $wp_version;

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins    = get_plugins();
		$active_plugins = array();
		foreach ( (array) get_option( 'active_plugins', array() ) as $plugin_file ) {
			if ( isset( $all_plugins[ $plugin_file ] ) ) {
				$active_plugins[] = array(
					'file'    => $plugin_file,
					'name'    => $all_plugins[ $plugin_file ]['Name'],
					'version' => $all_plugins[ $plugin_file ]['Version'],
				);
			}
		}

		$theme = wp_get_theme();

		return array(
			'name'           => get_bloginfo( 'name' ),
			'description'    => get_bloginfo( 'description' ),
			'url'            => home_url(),
			'admin_url'      => admin_url(),
			'language'       => get_locale(),
			'timezone'       => wp_timezone_string(),
			'wp_version'     => $wp_version,
			'php_version'    => PHP_VERSION,
			'active_theme'   => array(
				'name'    => $theme->get( 'Name' ),
				'version' => $theme->get( 'Version' ),
				'stylesheet' => $theme->get_stylesheet(),
			),
			'active_plugins' => $active_plugins,
		);
	}

	public function list_users( array $args ): array {
		$query_args = array(
			'number' => (int) ( $args['per_page'] ?? 20 ),
			'paged'  => (int) ( $args['page']     ?? 1 ),
		);
		if ( ! empty( $args['role'] ) ) {
			$query_args['role'] = (string) $args['role'];
		}

		$query = new WP_User_Query( $query_args );

		$users = array_map(
			static function ( WP_User $user ) {
				return array(
					'id'           => $user->ID,
					'username'     => $user->user_login,
					'display_name' => $user->display_name,
					'email'        => $user->user_email,
					'roles'        => $user->roles,
					'registered'   => $user->user_registered,
				);
			},
			$query->get_results()
		);

		return array(
			'users' => $users,
			'total' => (int) $query->get_total(),
		);
	}
}
