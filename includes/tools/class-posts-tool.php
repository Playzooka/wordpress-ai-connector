<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAIC_Posts_Tool {
	public function register( WPAIC_Tool_Registry $registry ): void {
		$registry->register_tool(
			'wp_list_posts',
			array(
				'description' => 'List posts with optional filters.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'status'   => array( 'type' => 'string', 'enum' => array( 'publish', 'draft', 'pending', 'private', 'future', 'any' ), 'default' => 'any' ),
						'search'   => array( 'type' => 'string' ),
						'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 10 ),
						'page'     => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
					),
				),
			),
			array( $this, 'list_posts' ),
			'edit_posts'
		);

		$registry->register_tool(
			'wp_get_post',
			array(
				'description' => 'Get a single post by ID.',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'id' ),
					'properties' => array(
						'id' => array( 'type' => 'integer' ),
					),
				),
			),
			array( $this, 'get_post' ),
			'edit_posts'
		);

		$registry->register_tool(
			'wp_create_post',
			array(
				'description' => 'Create a new post.',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'title' ),
					'properties' => array(
						'title'      => array( 'type' => 'string' ),
						'content'    => array( 'type' => 'string', 'description' => 'HTML or block content.' ),
						'excerpt'    => array( 'type' => 'string' ),
						'status'     => array( 'type' => 'string', 'enum' => array( 'publish', 'draft', 'pending', 'private', 'future' ), 'default' => 'draft' ),
						'categories' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
						'tags'       => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					),
				),
			),
			array( $this, 'create_post' ),
			'publish_posts'
		);

		$registry->register_tool(
			'wp_update_post',
			array(
				'description' => 'Update fields of an existing post.',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'id' ),
					'properties' => array(
						'id'      => array( 'type' => 'integer' ),
						'title'   => array( 'type' => 'string' ),
						'content' => array( 'type' => 'string' ),
						'excerpt' => array( 'type' => 'string' ),
						'status'  => array( 'type' => 'string', 'enum' => array( 'publish', 'draft', 'pending', 'private', 'future' ) ),
					),
				),
			),
			array( $this, 'update_post' ),
			'edit_posts'
		);

		$registry->register_tool(
			'wp_delete_post',
			array(
				'description' => 'Delete a post. By default moves to trash; pass force=true to permanently delete.',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'id' ),
					'properties' => array(
						'id'    => array( 'type' => 'integer' ),
						'force' => array( 'type' => 'boolean', 'default' => false ),
					),
				),
			),
			array( $this, 'delete_post' ),
			'delete_posts'
		);
	}

	public function list_posts( array $args ): array {
		$query = new WP_Query( array(
			'post_type'      => 'post',
			'post_status'    => $args['status']   ?? 'any',
			's'              => $args['search']   ?? '',
			'posts_per_page' => $args['per_page'] ?? 10,
			'paged'          => $args['page']     ?? 1,
		) );

		$posts = array_map( array( $this, 'format_post' ), $query->posts );

		return array(
			'posts'       => $posts,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
		);
	}

	public function get_post( array $args ): array {
		$id   = (int) ( $args['id'] ?? 0 );
		$post = get_post( $id );
		if ( ! $post || 'post' !== $post->post_type ) {
			throw new WPAIC_Tool_Exception( "Post {$id} not found.", -32004 );
		}
		return $this->format_post( $post, true );
	}

	public function create_post( array $args ): array {
		$post_id = wp_insert_post(
			array(
				'post_type'    => 'post',
				'post_title'   => (string) ( $args['title']   ?? '' ),
				'post_content' => (string) ( $args['content'] ?? '' ),
				'post_excerpt' => (string) ( $args['excerpt'] ?? '' ),
				'post_status'  => (string) ( $args['status']  ?? 'draft' ),
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			throw new WPAIC_Tool_Exception( $post_id->get_error_message(), -32001 );
		}

		if ( ! empty( $args['categories'] ) && is_array( $args['categories'] ) ) {
			wp_set_post_categories( $post_id, array_map( 'intval', $args['categories'] ) );
		}
		if ( ! empty( $args['tags'] ) && is_array( $args['tags'] ) ) {
			wp_set_post_tags( $post_id, $args['tags'] );
		}

		return $this->format_post( get_post( $post_id ), true );
	}

	public function update_post( array $args ): array {
		$id = (int) ( $args['id'] ?? 0 );
		if ( ! get_post( $id ) ) {
			throw new WPAIC_Tool_Exception( "Post {$id} not found.", -32004 );
		}

		$update = array( 'ID' => $id );
		foreach ( array( 'title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt', 'status' => 'post_status' ) as $arg => $field ) {
			if ( array_key_exists( $arg, $args ) ) {
				$update[ $field ] = (string) $args[ $arg ];
			}
		}

		$result = wp_update_post( $update, true );
		if ( is_wp_error( $result ) ) {
			throw new WPAIC_Tool_Exception( $result->get_error_message(), -32001 );
		}

		return $this->format_post( get_post( $id ), true );
	}

	public function delete_post( array $args ): array {
		$id    = (int) ( $args['id'] ?? 0 );
		$force = (bool) ( $args['force'] ?? false );
		if ( ! get_post( $id ) ) {
			throw new WPAIC_Tool_Exception( "Post {$id} not found.", -32004 );
		}
		$result = wp_delete_post( $id, $force );
		if ( ! $result ) {
			throw new WPAIC_Tool_Exception( "Failed to delete post {$id}.", -32001 );
		}
		return array(
			'id'      => $id,
			'deleted' => true,
			'forced'  => $force,
		);
	}

	private function format_post( WP_Post $post, bool $include_content = false ): array {
		$data = array(
			'id'      => $post->ID,
			'title'   => get_the_title( $post ),
			'status'  => $post->post_status,
			'slug'    => $post->post_name,
			'date'    => $post->post_date_gmt,
			'link'    => get_permalink( $post ),
			'author'  => (int) $post->post_author,
			'excerpt' => $post->post_excerpt,
		);
		if ( $include_content ) {
			$data['content'] = $post->post_content;
		}
		return $data;
	}
}
