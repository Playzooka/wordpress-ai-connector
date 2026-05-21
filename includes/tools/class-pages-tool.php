<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAIC_Pages_Tool {
	public function register( WPAIC_Tool_Registry $registry ): void {
		$registry->register_tool(
			'wp_list_pages',
			array(
				'description' => 'List pages with optional filters.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'status'   => array( 'type' => 'string', 'enum' => array( 'publish', 'draft', 'pending', 'private', 'any' ), 'default' => 'any' ),
						'search'   => array( 'type' => 'string' ),
						'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 10 ),
						'page'     => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
					),
				),
			),
			array( $this, 'list_pages' ),
			'edit_pages'
		);

		$registry->register_tool(
			'wp_get_page',
			array(
				'description' => 'Get a single page by ID.',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'id' ),
					'properties' => array( 'id' => array( 'type' => 'integer' ) ),
				),
			),
			array( $this, 'get_page' ),
			'edit_pages'
		);

		$registry->register_tool(
			'wp_create_page',
			array(
				'description' => 'Create a new page.',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'title' ),
					'properties' => array(
						'title'   => array( 'type' => 'string' ),
						'content' => array( 'type' => 'string' ),
						'excerpt' => array( 'type' => 'string' ),
						'status'  => array( 'type' => 'string', 'enum' => array( 'publish', 'draft', 'pending', 'private' ), 'default' => 'draft' ),
						'parent'  => array( 'type' => 'integer', 'description' => 'Parent page ID for hierarchy.' ),
					),
				),
			),
			array( $this, 'create_page' ),
			'publish_pages'
		);

		$registry->register_tool(
			'wp_update_page',
			array(
				'description' => 'Update fields of an existing page.',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'id' ),
					'properties' => array(
						'id'      => array( 'type' => 'integer' ),
						'title'   => array( 'type' => 'string' ),
						'content' => array( 'type' => 'string' ),
						'excerpt' => array( 'type' => 'string' ),
						'status'  => array( 'type' => 'string', 'enum' => array( 'publish', 'draft', 'pending', 'private' ) ),
						'parent'  => array( 'type' => 'integer' ),
					),
				),
			),
			array( $this, 'update_page' ),
			'edit_pages'
		);

		$registry->register_tool(
			'wp_delete_page',
			array(
				'description' => 'Delete a page. Moves to trash by default; pass force=true to permanently delete.',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'id' ),
					'properties' => array(
						'id'    => array( 'type' => 'integer' ),
						'force' => array( 'type' => 'boolean', 'default' => false ),
					),
				),
			),
			array( $this, 'delete_page' ),
			'delete_pages'
		);
	}

	public function list_pages( array $args ): array {
		$query = new WP_Query( array(
			'post_type'      => 'page',
			'post_status'    => $args['status']   ?? 'any',
			's'              => $args['search']   ?? '',
			'posts_per_page' => $args['per_page'] ?? 10,
			'paged'          => $args['page']     ?? 1,
		) );

		return array(
			'pages'       => array_map( array( $this, 'format_page' ), $query->posts ),
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
		);
	}

	public function get_page( array $args ): array {
		$id   = (int) ( $args['id'] ?? 0 );
		$page = get_post( $id );
		if ( ! $page || 'page' !== $page->post_type ) {
			throw new WPAIC_Tool_Exception( "Page {$id} not found.", -32004 );
		}
		return $this->format_page( $page, true );
	}

	public function create_page( array $args ): array {
		$insert = array(
			'post_type'    => 'page',
			'post_title'   => (string) ( $args['title']   ?? '' ),
			'post_content' => (string) ( $args['content'] ?? '' ),
			'post_excerpt' => (string) ( $args['excerpt'] ?? '' ),
			'post_status'  => (string) ( $args['status']  ?? 'draft' ),
		);
		if ( ! empty( $args['parent'] ) ) {
			$insert['post_parent'] = (int) $args['parent'];
		}

		$page_id = wp_insert_post( $insert, true );
		if ( is_wp_error( $page_id ) ) {
			throw new WPAIC_Tool_Exception( $page_id->get_error_message(), -32001 );
		}
		return $this->format_page( get_post( $page_id ), true );
	}

	public function update_page( array $args ): array {
		$id = (int) ( $args['id'] ?? 0 );
		$existing = get_post( $id );
		if ( ! $existing || 'page' !== $existing->post_type ) {
			throw new WPAIC_Tool_Exception( "Page {$id} not found.", -32004 );
		}

		$update = array( 'ID' => $id );
		$map = array(
			'title'   => 'post_title',
			'content' => 'post_content',
			'excerpt' => 'post_excerpt',
			'status'  => 'post_status',
			'parent'  => 'post_parent',
		);
		foreach ( $map as $arg => $field ) {
			if ( array_key_exists( $arg, $args ) ) {
				$update[ $field ] = 'parent' === $arg ? (int) $args[ $arg ] : (string) $args[ $arg ];
			}
		}

		$result = wp_update_post( $update, true );
		if ( is_wp_error( $result ) ) {
			throw new WPAIC_Tool_Exception( $result->get_error_message(), -32001 );
		}
		return $this->format_page( get_post( $id ), true );
	}

	public function delete_page( array $args ): array {
		$id    = (int) ( $args['id'] ?? 0 );
		$force = (bool) ( $args['force'] ?? false );
		$existing = get_post( $id );
		if ( ! $existing || 'page' !== $existing->post_type ) {
			throw new WPAIC_Tool_Exception( "Page {$id} not found.", -32004 );
		}
		if ( ! wp_delete_post( $id, $force ) ) {
			throw new WPAIC_Tool_Exception( "Failed to delete page {$id}.", -32001 );
		}
		return array(
			'id'      => $id,
			'deleted' => true,
			'forced'  => $force,
		);
	}

	private function format_page( WP_Post $page, bool $include_content = false ): array {
		$data = array(
			'id'     => $page->ID,
			'title'  => get_the_title( $page ),
			'status' => $page->post_status,
			'slug'   => $page->post_name,
			'parent' => (int) $page->post_parent,
			'date'   => $page->post_date_gmt,
			'link'   => get_permalink( $page ),
		);
		if ( $include_content ) {
			$data['content'] = $page->post_content;
		}
		return $data;
	}
}
