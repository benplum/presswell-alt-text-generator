<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1><?php echo esc_html__( 'Alt Text Bulk Generator', PWATG::TEXT_DOMAIN ); ?></h1>
	<p><?php echo esc_html__( 'Generate alt text for existing images. By default only images with missing alt text are processed.', PWATG::TEXT_DOMAIN ); ?></p>
	<p>
		<label>
			<input type="checkbox" id="pwatg_regenerate_existing" value="1" />
			<?php echo esc_html__( 'Regenerate existing alt text', PWATG::TEXT_DOMAIN ); ?>
		</label>
	</p>

	<p>
		<button type="button" class="button button-primary" id="pwatg_start_bulk"><?php echo esc_html__( 'Run Bulk Generation', PWATG::TEXT_DOMAIN ); ?></button>
	</p>

	<div id="pwatg_progress_wrap" class="pwatg-progress-wrap">
		<div class="pwatg-progress-track">
			<div id="pwatg_progress_bar" class="pwatg-progress-bar"></div>
		</div>
		<p id="pwatg_progress_text" class="pwatg-progress-text"></p>
	</div>

	<table id="pwatg_results_table" class="wp-list-table widefat fixed striped pwatg-results-table">
		<thead>
			<tr>
				<th class="pwatg-col-thumb"><?php echo esc_html__( 'Thumbnail', PWATG::TEXT_DOMAIN ); ?></th>
				<th class="pwatg-col-id"><?php echo esc_html__( 'Media ID', PWATG::TEXT_DOMAIN ); ?></th>
				<th class="pwatg-col-status"><?php echo esc_html__( 'Status', PWATG::TEXT_DOMAIN ); ?></th>
				<th><?php echo esc_html__( 'Alt Text Generated', PWATG::TEXT_DOMAIN ); ?></th>
			</tr>
		</thead>
		<tbody id="pwatg_results_body"></tbody>
	</table>
</div>
