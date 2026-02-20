<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Alt Text Bulk Generator', 'presswell-alt-text' ); ?></h1>
	<p><?php esc_html_e( 'Generate alt text for existing images. By default only images with missing alt text are processed.', 'presswell-alt-text' ); ?></p>
	<p>
		<label>
			<input type="checkbox" id="pwatg_regenerate_existing" value="1" />
			<?php esc_html_e( 'Regenerate existing alt text', 'presswell-alt-text' ); ?>
		</label>
	</p>
	<p>
		<label for="pwatg_limit"><strong><?php esc_html_e( 'Limit', 'presswell-alt-text' ); ?></strong></label><br />
		<input id="pwatg_limit" type="number" min="1" max="500" value="50" />
	</p>

	<p>
		<button type="button" class="button button-primary" id="pwatg_start_bulk"><?php esc_html_e( 'Run Bulk Generation', 'presswell-alt-text' ); ?></button>
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
				<th class="pwatg-col-thumb"><?php esc_html_e( 'Thumbnail', 'presswell-alt-text' ); ?></th>
				<th class="pwatg-col-id"><?php esc_html_e( 'Media ID', 'presswell-alt-text' ); ?></th>
				<th class="pwatg-col-status"><?php esc_html_e( 'Status', 'presswell-alt-text' ); ?></th>
				<th><?php esc_html_e( 'Alt Text Generated', 'presswell-alt-text' ); ?></th>
			</tr>
		</thead>
		<tbody id="pwatg_results_body"></tbody>
	</table>
</div>
