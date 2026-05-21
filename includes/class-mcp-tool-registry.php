<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAIC_Tool_Registry {
	/** @var array<string, array{schema: array, handler: callable, capability: string}> */
	private $tools = array();

	public function register_tool( string $name, array $schema, callable $handler, string $capability = 'edit_posts' ): void {
		$this->tools[ $name ] = array(
			'schema'     => $schema,
			'handler'    => $handler,
			'capability' => $capability,
		);
	}

	public function list_tools(): array {
		$list = array();
		foreach ( $this->tools as $name => $tool ) {
			$entry = array_merge( array( 'name' => $name ), $tool['schema'] );
			// Force inputSchema.properties to encode as a JSON object even
			// when empty. PHP would otherwise serialise an empty array as []
			// which is invalid JSON Schema and breaks strict client parsers.
			if ( isset( $entry['inputSchema']['properties'] ) && is_array( $entry['inputSchema']['properties'] ) && empty( $entry['inputSchema']['properties'] ) ) {
				$entry['inputSchema']['properties'] = (object) array();
			}
			$list[] = $entry;
		}
		return $list;
	}

	public function has_tool( string $name ): bool {
		return isset( $this->tools[ $name ] );
	}

	public function get_capability( string $name ): ?string {
		return $this->tools[ $name ]['capability'] ?? null;
	}

	/**
	 * @return mixed Tool result (will be JSON-encoded into MCP content).
	 * @throws WPAIC_Tool_Exception
	 */
	public function call( string $name, array $arguments ) {
		if ( ! isset( $this->tools[ $name ] ) ) {
			throw new WPAIC_Tool_Exception( "Unknown tool: {$name}", -32601 );
		}
		return call_user_func( $this->tools[ $name ]['handler'], $arguments );
	}
}

class WPAIC_Tool_Exception extends Exception {
	public function __construct( string $message, int $code = -32000 ) {
		parent::__construct( $message, $code );
	}
}
