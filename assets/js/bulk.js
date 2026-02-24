jQuery(function($) {
  const data = window.pwatgBulkData || {};
  const nonceField = document.getElementById('pwatg_bulk_nonce');
  const ajaxAction = data.ajaxAction || 'pwatg_generate_bulk';
  const ajaxInitAction = data.ajaxInitAction || 'pwatg_bulk_init';
  const ajaxScanAction = data.ajaxScanAction || 'pwatg_scan_missing_alt';
  const i18n = data.i18n || {};
  const startButton = $('#pwatg_start_bulk');
  const progressWrap = $('#pwatg_progress_wrap');
  const progressBar = $('#pwatg_progress_bar');
  const progressText = $('#pwatg_progress_text');
  const regenerateInput = $('#pwatg_regenerate_existing');
  const resultsTable = $('#pwatg_results_table');
  const resultsBody = $('#pwatg_results_body');
  const missingCount = $('#pwatg_missing_count');
  const refreshLink = $('#pwatg_refresh_missing');
  const refreshStatus = $('#pwatg_refresh_status');
  const refreshDefaultText = refreshLink.length ? refreshLink.text().trim() : '';

  let ids = [];
  let offset = 0;
  let total = 0;
  let processed = 0;
  let updated = 0;
  let failed = 0;
  let batchSize = 5;
  let missing = 0;

  function t(key, fallback) {
    return Object.prototype.hasOwnProperty.call(i18n, key) ? i18n[key] : fallback;
  }

  function getAjaxErrorMessage(jqXHR, fallback) {
    if (
      jqXHR &&
      jqXHR.responseJSON &&
      jqXHR.responseJSON.data &&
      jqXHR.responseJSON.data.message
    ) {
      return String(jqXHR.responseJSON.data.message);
    }

    return fallback;
  }

  function getNonce() {
    if (nonceField && nonceField.value) {
      return nonceField.value;
    }

    return data.nonce || '';
  }

  function formatNumber(value) {
    const intVal = parseInt(value, 10) || 0;
    if (window.Intl && typeof Intl.NumberFormat === 'function') {
      return new Intl.NumberFormat().format(intVal);
    }
    return String(intVal);
  }

  function setMissingCount(value) {
    missing = Math.max(0, parseInt(value, 10) || 0);
    if (missingCount.length) {
      missingCount.text(formatNumber(missing));
    }
  }

  function updateMissingCountFromResponse(response, fallbackDecrease) {
    if (response && typeof response.data !== 'undefined' && typeof response.data.missing !== 'undefined') {
      setMissingCount(response.data.missing);
      return;
    }

    if (fallbackDecrease > 0) {
      setMissingCount(Math.max(0, missing - fallbackDecrease));
    }
  }

  (function initMissingCount() {
    if (!missingCount.length) {
      return;
    }

    const initial = parseInt(missingCount.data('initial'), 10);
    if (!isNaN(initial)) {
      setMissingCount(initial);
      return;
    }

    if (typeof data.missing !== 'undefined') {
      setMissingCount(data.missing);
    }
  })();

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
      const altHtml = $('<div>').text(altText).html();
      const errorMessage = item.error_message ? String(item.error_message) : '';
      const errorCode = item.error_code ? String(item.error_code) : '';
      let detailHtml = altHtml;

      if (status === 'failed' && errorMessage) {
        const safeError = $('<div>').text(errorMessage).html();
        const safeCode = errorCode ? $('<div>').text(errorCode).html() : '';
        detailHtml += '<div class="pwatg-result-error">';
        if (safeCode) {
          detailHtml += '<span class="pwatg-result-error-code">' + safeCode + '</span>';
        }
        detailHtml += '<span class="pwatg-result-error-message">' + safeError + '</span>';

        if (item.error_retry_after) {
          const retrySeconds = parseInt(item.error_retry_after, 10);
          if (!isNaN(retrySeconds) && retrySeconds > 0) {
            const minutes = Math.ceil(retrySeconds / 60);
            detailHtml += '<span class="pwatg-result-error-hint"> · ' + minutes + 'm</span>';
          }
        }

        detailHtml += '</div>';
      }

      resultsBody.append('<tr><td>' + thumb + '</td><td>' + mediaId + '</td><td>' + statusBadge + '</td><td>' + detailHtml + '</td></tr>');
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
      action: ajaxAction,
      nonce: getNonce(),
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
      updateMissingCountFromResponse(response, response.data.updated);

      if (response.data.halted) {
        const haltCode = response.data.halt_code ? String(response.data.halt_code) : '';
        const fallback = haltCode === 'pwatg_quota_exceeded'
          ? t('quotaExceeded', 'Bulk paused because the provider quota was exceeded.')
          : t('rateLimited', 'Bulk paused due to provider limits. Try again shortly.');
        let haltMessage = response.data.halt_reason ? String(response.data.halt_reason) : fallback;

        if (response.data.halt_retry_after) {
          const haltSeconds = parseInt(response.data.halt_retry_after, 10);
          if (!isNaN(haltSeconds) && haltSeconds > 0) {
            const minutes = Math.ceil(haltSeconds / 60);
            haltMessage += ' (' + minutes + 'm)';
          }
        }

        haltMessage += ' ' + t('seeDetails', 'See failed rows for details.');
        finish(haltMessage);
        return;
      }

      if (response.data.done) {
        finish(t('bulkComplete', 'Bulk generation complete.'));
        return;
      }

      runBatch();
    }).fail(function(jqXHR) {
      const message = getAjaxErrorMessage(jqXHR, t('bulkFailed', 'Could not complete bulk generation.'));
      finish(message);
    });
  }

  startButton.on('click', function() {
    startButton.prop('disabled', true).text(t('preparing', 'Preparing...'));
    progressWrap.show();
    progressBar.css('width', '0%');
    progressText.text(t('preparingList', 'Preparing image list...'));
    resultsBody.empty();
    resultsTable.hide();

    $.post(ajaxurl, {
      action: ajaxInitAction,
      nonce: getNonce(),
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

      startButton.text(t('running', 'Running...'));
      runBatch();
    }).fail(function(jqXHR) {
      const message = getAjaxErrorMessage(jqXHR, t('initFailed', 'Could not initialize bulk generation.'));
      finish(message);
    });
  });

  refreshLink.on('click', function(event) {
    event.preventDefault();

    if (!refreshLink.length || refreshLink.attr('aria-disabled') === 'true') {
      return;
    }

    refreshLink.attr('aria-disabled', 'true').text(t('checking', 'Checking...'));
    refreshStatus.text('');

    $.post(ajaxurl, {
      action: ajaxScanAction,
      nonce: getNonce(),
    }).done(function(response) {
      if (!response || !response.success) {
        const message = response && response.data && response.data.message ? response.data.message : t('checkFailed', 'Could not refresh the count.');
        refreshStatus.text(message);
        return;
      }

      const newCount = typeof response.data.count !== 'undefined' ? response.data.count : 0;
      setMissingCount(newCount);
      const statusText = newCount ? t('countUpdated', 'Count updated.') : t('countZero', 'No images without alt text were found.');
      refreshStatus.text(statusText);
    }).fail(function(jqXHR) {
      const message = getAjaxErrorMessage(jqXHR, t('checkFailed', 'Could not refresh the count.'));
      refreshStatus.text(message);
    }).always(function() {
      const resetText = refreshDefaultText || t('checkAgain', 'Check again');
      refreshLink.attr('aria-disabled', 'false').text(resetText);
    });
  });
});
