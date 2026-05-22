<?php
/**
 * Plugin Name:       WordPress AI Connector
 * Description:       Exposes this WordPress site as an MCP server so AI clients (Claude, ChatGPT) can manage content via a remote connector.
 * Version:           0.4.2
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            Fausto Fonseca
 * License:           GPL-2.0-or-later
 * Text Domain:       wp-ai-connector
 * Update URI:        https://github.com/Playzooka/wordpress-ai-connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPAIC_VERSION', '0.4.2' );
define( 'WPAIC_PLUGIN_FILE', __FILE__ );
define( 'WPAIC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPAIC_REST_NAMESPACE', 'wp-ai-connector/v1' );
define( 'WPAIC_MCP_PROTOCOL_VERSION', '2025-11-25' );

require_once WPAIC_PLUGIN_DIR . 'includes/class-mcp-tool-registry.php';
require_once WPAIC_PLUGIN_DIR . 'includes/class-request-log.php';
require_once WPAIC_PLUGIN_DIR . 'includes/oauth/class-oauth-store.php';
require_once WPAIC_PLUGIN_DIR . 'includes/oauth/class-oauth-server.php';
require_once WPAIC_PLUGIN_DIR . 'includes/class-mcp-server.php';
require_once WPAIC_PLUGIN_DIR . 'includes/class-root-router.php';
require_once WPAIC_PLUGIN_DIR . 'includes/class-admin.php';
require_once WPAIC_PLUGIN_DIR . 'includes/tools/class-posts-tool.php';
require_once WPAIC_PLUGIN_DIR . 'includes/tools/class-pages-tool.php';
require_once WPAIC_PLUGIN_DIR . 'includes/tools/class-media-tool.php';
require_once WPAIC_PLUGIN_DIR . 'includes/tools/class-site-tool.php';

// Self-updating from the GitHub repo via Plugin Update Checker (YahnisElsts).
require_once WPAIC_PLUGIN_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';

$wpaic_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	'https://github.com/Playzooka/wordpress-ai-connector/',
	__FILE__,
	'wordpress-ai-connector'
);
$wpaic_update_checker->setBranch( 'main' );
$wpaic_update_checker->getVcsApi()->enableReleaseAssets();

/**
 * Build and return the shared tool registry. Lazy + memoized so both the
 * REST handler and the admin page see the same set of tools.
 */
function wpaic_registry(): WPAIC_Tool_Registry {
	static $registry = null;
	if ( null === $registry ) {
		$registry = new WPAIC_Tool_Registry();
		( new WPAIC_Posts_Tool() )->register( $registry );
		( new WPAIC_Pages_Tool() )->register( $registry );
		( new WPAIC_Media_Tool() )->register( $registry );
		( new WPAIC_Site_Tool() )->register( $registry );
	}
	return $registry;
}

function wpaic_oauth_store(): WPAIC_OAuth_Store {
	static $store = null;
	if ( null === $store ) {
		$store = new WPAIC_OAuth_Store();
	}
	return $store;
}

function wpaic_oauth_server(): WPAIC_OAuth_Server {
	static $server = null;
	if ( null === $server ) {
		$server = new WPAIC_OAuth_Server( wpaic_oauth_store() );
	}
	return $server;
}

function wpaic_request_log(): WPAIC_Request_Log {
	static $log = null;
	if ( null === $log ) {
		$log = new WPAIC_Request_Log();
	}
	return $log;
}

// OAuth wires its own hooks (well-known + REST routes).
wpaic_oauth_server()->register();
wpaic_request_log()->register();

function wpaic_mcp_server(): WPAIC_MCP_Server {
	static $server = null;
	if ( null === $server ) {
		$server = new WPAIC_MCP_Server( wpaic_registry(), wpaic_oauth_server() );
	}
	return $server;
}

// Root-path router (/mcp, /oauth/*). Sidesteps hosts that block /wp-json/*
// from datacenter IPs. The REST API routes below stay registered too as a
// fallback for tooling that already targets the /wp-json/ URL.
( new WPAIC_Root_Router( wpaic_mcp_server(), wpaic_oauth_server() ) )->register();

add_action( 'rest_api_init', static function () {
	wpaic_mcp_server()->register_routes();
} );

if ( is_admin() ) {
	( new WPAIC_Admin( wpaic_registry(), wpaic_oauth_store(), wpaic_request_log() ) )->register();
}

/**
 * Send permissive CORS headers for all routes in this plugin's namespace and
 * expose WWW-Authenticate so MCP clients running in a browser can read the
 * discovery hint on the 401 response from /mcp. The well-known root paths
 * already set their own CORS headers (see WPAIC_OAuth_Server::emit_cors_headers()).
 */
add_filter( 'rest_pre_dispatch', static function ( $result, $server, $request ) {
	$route = (string) $request->get_route();
	if ( 0 !== strpos( $route, '/' . WPAIC_REST_NAMESPACE ) ) {
		return $result;
	}
	header( 'Access-Control-Allow-Origin: *' );
	header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS, DELETE' );
	header( 'Access-Control-Allow-Headers: Authorization, Content-Type, Mcp-Session-Id, MCP-Protocol-Version' );
	header( 'Access-Control-Expose-Headers: WWW-Authenticate, Mcp-Session-Id' );
	header( 'Access-Control-Max-Age: 3600' );
	// Defeat upstream caching layers (LiteSpeed/CDNs) caching auth challenges
	// and per-token responses. These endpoints must never be cached.
	header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
	header( 'Pragma: no-cache' );
	header( 'X-LiteSpeed-Cache-Control: no-cache' );
	return $result;
}, 10, 3 );
