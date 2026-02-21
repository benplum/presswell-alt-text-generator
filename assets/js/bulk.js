jQuery(function($) {
	const data = window.pwatgBulkData || {};
	const nonce = data.nonce || '';
	const i18n = data.i18n || {};
	const startButton = $('#pwatg_start_bulk');
	const progressWrap = $('#pwatg_progress_wrap');
	const progressBar = $('#pwatg_progress_bar');
	const progressText = $('#pwatg_progress_text');
	const regenerateInput = $('#pwatg_regenerate_existing');
	const resultsTable = $('#pwatg_results_table');
	const resultsBody = $('#pwatg_results_body');

	let ids = [];
	let offset = 0;
	let total = 0;
	let processed = 0;
	let updated = 0;
	let failed = 0;
	const batchSize = 5;

	function t(key, fallback) {
		return Object.prototype.hasOwnProperty.call(i18n, key) ? i18n[key] : fallback;
	}

	function updateProgress() {
		const percent = total > 0 ? Math.round((processed / total) * 100) : 0;
		progressBar.css('width', Math.min(percent, 100) + '%');
		progressText.text('Processed ' + processed + ' of ' + total + ' · Updated: ' + updated + ' · Failed: ' + failed);
	}

	function appendRows(items) {
		if (!Array.isArray(items) || !items.length) {
			return;
		}

		resultsTable.show();
		items.forEach(function(item) {
			const thumb = item.thumb ? '<img src="' + String(item.thumb) + '" alt="" class="pwatg-thumb-image" />' : '';
			const mediaId = item.id ? String(item.id) : '';
			const status = item.status ? String(item.status) : '';
			const safeStatus = $('<div>').text(status || 'skipped').html();
			const statusClass = status === 'updated' ? 'pwatg-status-updated' : (status === 'failed' ? 'pwatg-status-failed' : 'pwatg-status-skipped');
			const statusBadge = '<span class="pwatg-status-badge ' + statusClass + '">' + safeStatus + '</span>';
			const altText = item.alt ? String(item.alt) : (item.status === 'failed' ? t('failedAlt', '[Failed to generate]') : '');
			resultsBody.append('<tr><td>' + thumb + '</td><td>' + mediaId + '</td><td>' + statusBadge + '</td><td>' + $('<div>').text(altText).html() + '</td></tr>');
		});
	}

	function finish(message) {
		startButton.prop('disabled', false).text(t('runBulk', 'Run Bulk Generation'));
		progressText.text(message + ' Processed: ' + processed + ', Updated: ' + updated + ', Failed: ' + failed + '.');
	}

	function runBatch() {
		if (offset >= total) {
			finish(t('bulkComplete', 'Bulk generation complete.'));
			return;
		}

		$.post(ajaxurl, {
			action: 'pwatg_bulk_generate',
			nonce: nonce,
			ids: ids,
			offset: offset,
			batch_size: batchSize,
			regenerate_existing: regenerateInput.is(':checked') ? 1 : 0
		}).done(function(response) {
			if (!response || !response.success) {
				const message = response && response.data && response.data.message ? response.data.message : t('batchFailed', 'Batch request failed.');
				finish(message);
				return;
			}

			offset = response.data.next_offset;
			processed += response.data.processed;
			updated += response.data.updated;
			failed += response.data.failed;
			appendRows(response.data.items || []);
			updateProgress();

			if (response.data.done) {
				finish(t('bulkComplete', 'Bulk generation complete.'));
				return;
			}

			runBatch();
		}).fail(function() {
			finish(t('bulkFailed', 'Could not complete bulk generation.'));
		});
	}

	startButton.on('click', function() {
		startButton.prop('disabled', true).text(t('preparing', 'Preparing…'));
		progressWrap.show();
		progressBar.css('width', '0%');
		progressText.text(t('preparingList', 'Preparing image list…'));
		resultsBody.empty();
		resultsTable.hide();

		$.post(ajaxurl, {
			action: 'pwatg_bulk_init',
			nonce: nonce,
			regenerate_existing: regenerateInput.is(':checked') ? 1 : 0
		}).done(function(response) {
			if (!response || !response.success) {
				const message = response && response.data && response.data.message ? response.data.message : t('initFailed', 'Could not initialize bulk generation.');
				finish(message);
				return;
			}

			ids = response.data.ids || [];
			total = response.data.total || 0;
			offset = 0;
			processed = 0;
			updated = 0;
			failed = 0;
			updateProgress();

			if (!total) {
				finish(t('noImages', 'No matching images found for this run.'));
				return;
			}

			startButton.text(t('running', 'Running…'));
			runBatch();
		}).fail(function() {
			finish(t('initFailed', 'Could not initialize bulk generation.'));
		});
	});
});
