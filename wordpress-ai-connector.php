<?php
/**
 * Plugin Name:       WordPress AI Connector
 * Description:       Exposes this WordPress site as an MCP server so AI clients (Claude, ChatGPT) can manage content via a remote connector.
 * Version:           0.1.0
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            Fausto Fonseca
 * License:           GPL-2.0-or-later
 * Text Domain:       wp-ai-connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPAIC_VERSION', '0.1.0' );
define( 'WPAIC_PLUGIN_FILE', __FILE__ );
define( 'WPAIC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPAIC_REST_NAMESPACE', 'wp-ai-connector/v1' );
define( 'WPAIC_MCP_PROTOCOL_VERSION', '2025-06-18' );

require_once WPAIC_PLUGIN_DIR . 'includes/class-mcp-tool-registry.php';
require_once WPAIC_PLUGIN_DIR . 'includes/class-mcp-server.php';
require_once WPAIC_PLUGIN_DIR . 'includes/tools/class-posts-tool.php';
require_once WPAIC_PLUGIN_DIR . 'includes/tools/class-pages-tool.php';
require_once WPAIC_PLUGIN_DIR . 'includes/tools/class-media-tool.php';
require_once WPAIC_PLUGIN_DIR . 'includes/tools/class-site-tool.php';

add_action( 'rest_api_init', static function () {
	$registry = new WPAIC_Tool_Registry();
	( new WPAIC_Posts_Tool() )->register( $registry );
	( new WPAIC_Pages_Tool() )->register( $registry );
	( new WPAIC_Media_Tool() )->register( $registry );
	( new WPAIC_Site_Tool() )->register( $registry );

	( new WPAIC_MCP_Server( $registry ) )->register_routes();
} );
