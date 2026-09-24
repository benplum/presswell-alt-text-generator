<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Generate button in the block editor's Image block sidebar, backed by a REST route.
 */
trait PWATG_Block_Editor_Trait {
  /** Wire up the REST route and editor script. */
  protected function construct_block_editor_trait() {
    add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
    add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_block_editor_assets' ] );
  }

  /** Register POST /pwatg/v1/attachments/<id>/alt-text. */
  public function register_rest_routes() {
    register_rest_route(
      PWATG::REST_NAMESPACE,
      '/attachments/(?P<id>\d+)/alt-text',
      [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => [ $this, 'rest_generate_alt_text' ],
        'permission_callback' => [ $this, 'rest_can_generate_alt_text' ],
        'args'                => [
          'id'    => [
            'type'              => 'integer',
            'required'          => true,
            'sanitize_callback' => 'absint',
          ],
          'force' => [
            'type'    => 'boolean',
            'default' => true,
          ],
        ],
      ]
    );
  }

  /**
   * The same check as the Media Library actions: upload_files plus edit_post on the image.
   *
   * @param WP_REST_Request $request Request.
   *
   * @return bool|WP_Error
   */
  public function rest_can_generate_alt_text( $request ) {
    $attachment_id = absint( $request['id'] );

    if ( ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $attachment_id ) ) {
      return new WP_Error( 'rest_forbidden', __( 'You do not have permission to do that.', 'presswell-alt-text-generator' ), [ 'status' => rest_authorization_required_code() ] );
    }

    return true;
  }

  /**
   * Generate alt text for an image and return it for the block.
   *
   * @param WP_REST_Request $request Request.
   *
   * @return WP_REST_Response|WP_Error
   */
  public function rest_generate_alt_text( $request ) {
    $attachment_id = absint( $request['id'] );

    $this->debug_log( 'Single generation request received.', [ 'attachment_id' => $attachment_id, 'transport' => 'rest' ] );

    $result = $this->generate_alt_text_for_attachment( $attachment_id, (bool) $request['force'] );

    if ( is_wp_error( $result ) ) {
      $code   = $result->get_error_code();
      $status = in_array( $code, [ 'pwatg_rate_limited', 'pwatg_quota_exceeded' ], true ) ? 429 : 400;

      if ( 'pwatg_invalid_attachment' === $code ) {
        $status = 404;
      }

      return new WP_Error( $code, $result->get_error_message(), [ 'status' => $status ] );
    }

    return rest_ensure_response(
      [
        'status'       => $result ? 'updated' : 'skipped',
        'alt_text'     => (string) get_post_meta( $attachment_id, PWATG::META_KEY_ALT_TEXT, true ),
        'has_previous' => $this->has_previous_alt( $attachment_id ),
      ]
    );
  }

  /** Load the Image block panel for users who can generate alt text. */
  public function enqueue_block_editor_assets() {
    if ( ! current_user_can( 'upload_files' ) ) {
      return;
    }

    wp_enqueue_script(
      PWATG::ASSET_HANDLE_BLOCK_EDITOR_JS,
      $this->get_asset_url( 'js/block-editor.js' ),
      [ 'wp-hooks', 'wp-compose', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-api-fetch', 'wp-data', 'wp-notices' ],
      PWATG::VERSION,
      true
    );

    wp_localize_script(
      PWATG::ASSET_HANDLE_BLOCK_EDITOR_JS,
      PWATG::JS_OBJECT_BLOCK_EDITOR,
      [
        'restPath' => '/' . PWATG::REST_NAMESPACE . '/attachments/',
      ]
    );

    wp_set_script_translations( PWATG::ASSET_HANDLE_BLOCK_EDITOR_JS, 'presswell-alt-text-generator' );
  }
}
