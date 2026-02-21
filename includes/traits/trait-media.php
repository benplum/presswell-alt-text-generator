<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait PWATG_Media_Trait {
	public function add_media_row_action( $actions, $post ) {
		$text_domain = $this->get_text_domain();

		if ( ! wp_attachment_is_image( $post->ID ) ) {
			return $actions;
		}

		if ( current_user_can( 'upload_files' ) && current_user_can( 'edit_post', $post->ID ) ) {
			$url         = $this->get_single_action_url( $post->ID );
			$current_alt = (string) get_post_meta( $post->ID, Presswell_Alt_Text_Generator::ALT_TEXT_META_KEY, true );
			$has_alt     = '' !== trim( $current_alt );
			$actions['pwatg_generate_alt'] = $this->render_view_to_string(
				'media-row-action.php',
				[
					'url' => $url,
					'attachment_id' => (int) $post->ID,
					'has_alt' => $has_alt,
					'button_label' => $has_alt ? __( 'Regenerate Alt Text', $text_domain ) : __( 'Generate Alt Text', $text_domain ),
					'text_domain' => $text_domain,
				]
			);
		}

		return $actions;
	}

	public function add_media_modal_action_field( $form_fields, $post ) {
		$text_domain = $this->get_text_domain();

		if ( ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $form_fields;
		}

		if ( ! wp_attachment_is_image( $post->ID ) ) {
			return $form_fields;
		}

		$url            = $this->get_single_action_url( $post->ID );
		$last_generated = $this->get_last_generated_label( $post->ID );
		$current_alt    = (string) get_post_meta( $post->ID, Presswell_Alt_Text_Generator::ALT_TEXT_META_KEY, true );
		$has_alt        = '' !== trim( $current_alt );

		$form_fields['pwatg_generate_alt'] = [
			'label' => __( 'Alt Text Generator', $text_domain ),
			'input' => 'html',
			'html'  => $this->render_view_to_string(
				'media-modal-action.php',
				[
					'url'            => $url,
					'attachment_id'  => (int) $post->ID,
					'has_alt'        => $has_alt,
					'button_label'   => $has_alt ? __( 'Regenerate Alt Text', $text_domain ) : __( 'Generate Alt Text', $text_domain ),
					'last_generated' => $last_generated,
					'text_domain'    => $text_domain,
				]
			),
			'helps' => __( 'Runs AI alt text generation for this image.', $text_domain ),
		];

		if ( isset( $form_fields['image_alt'] ) ) {
			$ordered = [];
			foreach ( $form_fields as $key => $field ) {
				if ( 'pwatg_generate_alt' === $key ) {
					continue;
				}

				$ordered[ $key ] = $field;

				if ( 'image_alt' === $key ) {
					$ordered['pwatg_generate_alt'] = $form_fields['pwatg_generate_alt'];
				}
			}

			return $ordered;
		}

		return $form_fields;
	}

	public function render_alt_field_proximity_script() {
		return;
	}

	public function maybe_generate_on_upload_from_metadata( $metadata, $attachment_id ) {
		$this->maybe_generate_on_upload( $attachment_id );

		return $metadata;
	}

	public function maybe_generate_on_upload( $attachment_id ) {
		$settings = $this->get_settings();
		if ( empty( $settings['auto_generate'] ) ) {
			return;
		}

		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return;
		}

		$current_alt = get_post_meta( $attachment_id, Presswell_Alt_Text_Generator::ALT_TEXT_META_KEY, true );
		if ( ! empty( $current_alt ) ) {
			return;
		}

		$this->generate_alt_text_for_attachment( $attachment_id, false );
	}

	public function handle_single_generation() {
		$text_domain = $this->get_text_domain();

		$attachment_id = isset( $_REQUEST['attachment_id'] ) ? absint( $_REQUEST['attachment_id'] ) : 0;

		if ( ! $attachment_id || ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $attachment_id ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', $text_domain ) );
		}

		check_admin_referer( 'pwtag_generate_single_' . $attachment_id );

		$result = $this->generate_alt_text_for_attachment( $attachment_id, true );
		$status = 'error';

		if ( true === $result ) {
			$status = 'updated';
		} elseif ( false === $result ) {
			$status = 'skipped';
		} elseif ( is_wp_error( $result ) && 'pwatg_missing_api_key' === $result->get_error_code() ) {
			$status = 'missing_key';
		}

		$redirect_url = wp_get_referer();
		if ( ! $redirect_url ) {
			$redirect_url = admin_url( 'upload.php' );
		}

		$redirect_url = add_query_arg(
			[
				'pwatg_single'     => $status,
				'pwatg_attachment' => $attachment_id,
			],
			$redirect_url
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	public function handle_single_generation_ajax() {
		$text_domain = $this->get_text_domain();

		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( wp_unslash( $_POST['attachment_id'] ) ) : 0;

		if ( ! $attachment_id || ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $attachment_id ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to do that.', $text_domain ) ], 403 );
		}

		check_ajax_referer( 'pwatg_generate_single_' . $attachment_id, 'nonce' );

		$result = $this->generate_alt_text_for_attachment( $attachment_id, true );
		$status = 'error';

		if ( true === $result ) {
			$status = 'updated';
		} elseif ( false === $result ) {
			$status = 'skipped';
		} elseif ( is_wp_error( $result ) && 'pwatg_missing_api_key' === $result->get_error_code() ) {
			$status = 'missing_key';
		}

		$messages = [
			'updated'     => __( 'Alt text generated successfully.', $text_domain ),
			'skipped'     => __( 'No changes were needed for this image.', $text_domain ),
			'missing_key' => __( 'Missing API key. Add it in Alt Text Generator settings.', $text_domain ),
			'error'       => __( 'Could not generate alt text for this image.', $text_domain ),
		];

		$alt_text       = (string) get_post_meta( $attachment_id, Presswell_Alt_Text_Generator::ALT_TEXT_META_KEY, true );
		$last_generated = $this->get_last_generated_label( $attachment_id );

		if ( in_array( $status, [ 'error', 'missing_key' ], true ) ) {
			wp_send_json_error(
				[
					'status'         => $status,
					'message'        => isset( $messages[ $status ] ) ? $messages[ $status ] : __( 'Could not generate alt text for this image.', $text_domain ),
					'attachment_id'  => $attachment_id,
					'alt_text'       => $alt_text,
					'last_generated' => $last_generated,
				],
				200
			);
		}

		wp_send_json_success(
			[
				'status'         => $status,
				'message'        => isset( $messages[ $status ] ) ? $messages[ $status ] : '',
				'attachment_id'  => $attachment_id,
				'alt_text'       => $alt_text,
				'last_generated' => $last_generated,
			]
		);
	}

	public function get_single_action_url( $attachment_id ) {
		$attachment_id = absint( $attachment_id );

		return wp_nonce_url(
			admin_url( 'admin-post.php?action=pwatg_generate_single&attachment_id=' . $attachment_id ),
			'pwatg_generate_single_' . $attachment_id
		);
	}

	public function get_last_generated_label( $attachment_id ) {
		$text_domain = $this->get_text_domain();

		$raw = (string) get_post_meta( $attachment_id, Presswell_Alt_Text_Generator::LAST_GENERATED_META_KEY, true );

		if ( '' === trim( $raw ) ) {
			return __( 'Never', $text_domain );
		}

		$raw       = trim( $raw );
		$timestamp = false;

		if ( ctype_digit( $raw ) ) {
			$timestamp = (int) $raw;
		} else {
			$dt = date_create_from_format( 'Y-m-d H:i:s', $raw, wp_timezone() );
			if ( false !== $dt ) {
				$timestamp = $dt->getTimestamp();
			} else {
				$fallback = strtotime( $raw );
				if ( false !== $fallback ) {
					$timestamp = $fallback;
				}
			}
		}

		if ( false === $timestamp || $timestamp <= 0 ) {
			return __( 'Unknown', $text_domain );
		}

		return sprintf(
			/* translators: %s: localized datetime */
			__( '%s', $text_domain ),
			wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp )
		);
	}

	public function render_admin_notices() {
		$text_domain = $this->get_text_domain();

		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			if ( ! current_user_can( 'upload_files' ) ) {
				return;
			}
		}

		if ( isset( $_GET['pwatg_single'] ) ) {
			$status     = sanitize_key( wp_unslash( $_GET['pwatg_single'] ) );
			$attachment = isset( $_GET['pwatg_attachment'] ) ? absint( $_GET['pwatg_attachment'] ) : 0;

			$messages = [
				'updated'     => __( 'Alt text generated successfully.', $text_domain ),
				'skipped'     => __( 'No changes were needed for this image.', $text_domain ),
				'missing_key' => __( 'Missing API key. Add it in Alt Text Generator settings.', $text_domain ),
				'error'       => __( 'Could not generate alt text for this image.', $text_domain ),
			];

			if ( isset( $messages[ $status ] ) ) {
				$class = in_array( $status, [ 'error', 'missing_key' ], true ) ? 'notice notice-error is-dismissible' : 'notice notice-success is-dismissible';
				$text  = $messages[ $status ];

				if ( $attachment > 0 ) {
					$text .= ' ' . sprintf(
						/* translators: %d: attachment ID */
						__( 'Attachment ID: %d.', $text_domain ),
						$attachment
					);
				}
				echo $this->render_view_to_string(
					'admin-notice.php',
					[
						'class' => $class,
						'text'  => $text,
					]
				);
			}
		}

		if ( ! empty( $_GET['page'] ) && $this->get_settings_page_slug() === $_GET['page'] ) {
			$test_notice = get_transient( Presswell_Alt_Text_Generator::TEST_NOTICE_KEY );
			if ( is_array( $test_notice ) && isset( $test_notice['message'] ) ) {
				delete_transient( Presswell_Alt_Text_Generator::TEST_NOTICE_KEY );
				$class = ( isset( $test_notice['type'] ) && 'success' === $test_notice['type'] ) ? 'notice notice-success is-dismissible' : 'notice notice-error is-dismissible';
				echo $this->render_view_to_string(
					'admin-notice.php',
					[
						'class' => $class,
						'text'  => $test_notice['message'],
					]
				);
			}
		}

		if ( empty( $_GET['page'] ) || $this->get_bulk_page_slug() !== $_GET['page'] ) {
			return;
		}

		$notice = get_transient( Presswell_Alt_Text_Generator::NOTICE_KEY );
		if ( ! is_array( $notice ) ) {
			return;
		}

		delete_transient( Presswell_Alt_Text_Generator::NOTICE_KEY );

		$message = sprintf(
			/* translators: 1: processed count, 2: updated count, 3: failed count */
			esc_html__( 'Bulk generation complete. Processed: %1$d, Updated: %2$d, Failed: %3$d.', $text_domain ),
			intval( $notice['processed'] ),
			intval( $notice['updated'] ),
			intval( $notice['failed'] )
		);
		echo $this->render_view_to_string(
			'admin-notice.php',
			[
				'class' => 'notice notice-info is-dismissible',
				'text'  => $message,
			]
		);
	}
}
