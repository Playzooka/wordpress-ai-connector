<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAIC_Media_Tool {
	public function register( WPAIC_Tool_Registry $registry ): void {
		$registry->register_tool(
			'wp_list_media',
			array(
				'description' => 'List media library items.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'mime_type' => array( 'type' => 'string', 'description' => 'Filter by MIME type prefix, e.g. "image" or "image/png".' ),
						'per_page'  => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ),
						'page'      => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
					),
				),
			),
			array( $this, 'list_media' ),
			'upload_files'
		);

		$registry->register_tool(
			'wp_upload_media',
			array(
				'description' => 'Upload a file to the media library. Provide the file bytes as base64 in content_base64.',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'filename', 'content_base64' ),
					'properties' => array(
						'filename'       => array( 'type' => 'string' ),
						'content_base64' => array( 'type' => 'string', 'description' => 'Base64-encoded file contents.' ),
						'mime_type'      => array( 'type' => 'string', 'description' => 'Optional. Inferred from filename if omitted.' ),
						'title'          => array( 'type' => 'string' ),
						'alt_text'       => array( 'type' => 'string', 'description' => 'Alt text (images only).' ),
					),
				),
			),
			array( $this, 'upload_media' ),
			'upload_files'
		);
	}

	public function list_media( array $args ): array {
		$query_args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $args['per_page'] ?? 20,
			'paged'          => $args['page']     ?? 1,
		);
		if ( ! empty( $args['mime_type'] ) ) {
			$query_args['post_mime_type'] = $args['mime_type'];
		}

		$query = new WP_Query( $query_args );

		return array(
			'media'       => array_map( array( $this, 'format_attachment' ), $query->posts ),
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
		);
	}

	public function upload_media( array $args ): array {
		$filename = sanitize_file_name( (string) ( $args['filename'] ?? '' ) );
		$b64      = (string) ( $args['content_base64'] ?? '' );

		if ( '' === $filename ) {
			throw new WPAIC_Tool_Exception( 'filename is required.', -32602 );
		}
		if ( '' === $b64 ) {
			throw new WPAIC_Tool_Exception( 'content_base64 is required.', -32602 );
		}

		$bytes = base64_decode( $b64, true );
		if ( false === $bytes ) {
			throw new WPAIC_Tool_Exception( 'content_base64 is not valid base64.', -32602 );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$upload = wp_upload_bits( $filename, null, $bytes );
		if ( ! empty( $upload['error'] ) ) {
			throw new WPAIC_Tool_Exception( 'Upload failed: ' . $upload['error'], -32001 );
		}

		$filetype = wp_check_filetype( $upload['file'], null );
		$mime     = ! empty( $args['mime_type'] ) ? (string) $args['mime_type'] : ( $filetype['type'] ?: 'application/octet-stream' );

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $mime,
				'post_title'     => (string) ( $args['title'] ?? sanitize_title( pathinfo( $filename, PATHINFO_FILENAME ) ) ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$upload['file'],
			0,
			true
		);

		if ( is_wp_error( $attachment_id ) ) {
			throw new WPAIC_Tool_Exception( $attachment_id->get_error_message(), -32001 );
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		if ( ! empty( $args['alt_text'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $args['alt_text'] ) );
		}

		return $this->format_attachment( get_post( $attachment_id ) );
	}

	private function format_attachment( WP_Post $attachment ): array {
		return array(
			'id'        => $attachment->ID,
			'title'     => get_the_title( $attachment ),
			'mime_type' => $attachment->post_mime_type,
			'url'       => wp_get_attachment_url( $attachment->ID ),
			'date'      => $attachment->post_date_gmt,
			'alt_text'  => (string) get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
		);
	}
}
