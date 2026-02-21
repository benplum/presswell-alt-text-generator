jQuery(function($) {
	const data = window.pwatgMediaData || {};
	const strings = data.strings || {};
	const ajaxAction = data.ajaxAction || '';
	const inlineFallback = {
		url: data.inlineUrl || '',
		last: data.inlineLast || '',
		hasAlt: !!data.inlineHasAlt
	};

	function t(key, fallback) {
		return Object.prototype.hasOwnProperty.call(strings, key) ? strings[key] : fallback;
	}

	function escapeHtml(value) {
		return $('<div>').text(String(value || '')).html();
	}

	function hasAltTextValue(value) {
		return String(value || '').trim().length > 0;
	}

	function getActionLabel(hasAlt) {
		return hasAlt ? t('regenerateButton', 'Regenerate Alt Text') : t('generateButton', 'Generate Alt Text');
	}

	function readHasAltFromButton($button) {
		return String($button.attr('data-has-alt') || '') === '1';
	}

	function writeHasAltToButton($button, hasAlt) {
		$button.attr('data-has-alt', hasAlt ? '1' : '0');
	}

	function getActionDataFromCompat(scope) {
		const compat = scope && scope.length ? scope : $(document.body);
		const row = compat.find('tr.compat-field-pwatg_generate_alt:visible, tr.compat-field-pwatg_generate_alt').first();
		if (!row.length) {
			return null;
		}

		const link = row.find('a.button, a.button-secondary, a').first();
		if (!link.length) {
			return null;
		}

		const href = link.attr('href') || '';
		if (!href) {
			return null;
		}

		const lastTextNode = row.find('p strong').first().parent();
		let lastText = '';
		if (lastTextNode.length) {
			lastText = $.trim(lastTextNode.text().replace(t('lastGeneratedLabel', 'Last generated:'), ''));
		}

		return {
			url: href,
			attachmentId: link.attr('data-attachment-id') || '',
			hasAlt: String(link.attr('data-has-alt') || '') === '1',
			last: lastText || t('never', 'Never'),
			row: row
		};
	}

	function parseActionUrl(actionUrl) {
		const parsed = {
			attachmentId: 0,
			nonce: ''
		};

		if (!actionUrl) {
			return parsed;
		}

		try {
			const url = new URL(String(actionUrl), window.location.origin);
			const attachmentId = parseInt(url.searchParams.get('attachment_id') || '0', 10);
			parsed.attachmentId = Number.isNaN(attachmentId) ? 0 : attachmentId;
			parsed.nonce = url.searchParams.get('_wpnonce') || '';
		} catch (error) {
			return parsed;
		}

		return parsed;
	}

	function getAltInputForButton($button) {
		const modalPanel = $button.closest('.media-modal .attachment-details, .media-modal .attachment-info');
		if (modalPanel.length) {
			const modalAlt = modalPanel.find('input[data-setting="alt"], textarea[data-setting="alt"]').first();
			if (modalAlt.length) {
				return modalAlt;
			}
		}

		const attachmentEditAlt = $('input[name="_wp_attachment_image_alt"], textarea[name="_wp_attachment_image_alt"]').first();
		return attachmentEditAlt.length ? attachmentEditAlt : $();
	}

	function getStatusContainerForButton($button) {
		const inlineAction = $button.closest('.pwatg-inline-action');
		if (inlineAction.length) {
			let status = inlineAction.find('.pwatg-inline-action-status').first();
			if (!status.length) {
				status = $('<p class="pwatg-inline-action-status" aria-live="polite"></p>');
				inlineAction.append(status);
			}
			return status;
		}

		const rowAction = $button.closest('.pwatg-row-action');
		if (rowAction.length) {
			let status = rowAction.find('.pwatg-inline-action-status').first();
			if (!status.length) {
				status = $('<span class="pwatg-inline-action-status" aria-live="polite"></span>');
				rowAction.append(status);
			}
			return status;
		}

		const parent = $button.parent();
		if (!parent.length) {
			return $();
		}

		let status = parent.find('.pwatg-inline-action-status').first();
		if (!status.length) {
			status = $('<p class="pwatg-inline-action-status" aria-live="polite"></p>');
			parent.append(status);
		}

		return status;
	}

	function setLastGeneratedForButton($button, lastGenerated) {
		const label = t('lastGeneratedLabel', 'Last generated:');
		const value = String(lastGenerated || t('never', 'Never'));
		let target = $();

		const inlineAction = $button.closest('.pwatg-inline-action');
		if (inlineAction.length) {
			target = inlineAction.find('.pwatg-last-generated, .pwatg-inline-action-meta').first();
		}

		if (!target.length) {
			const rowAction = $button.closest('.pwatg-row-action');
			if (rowAction.length) {
				target = rowAction.find('.pwatg-last-generated').first();
			}
		}

		if (!target.length) {
			const fieldScope = $button.closest('.setting, td, .compat-field-pwatg_generate_alt');
			if (fieldScope.length) {
				target = fieldScope.find('.pwatg-last-generated, .pwatg-inline-action-meta').first();
			}
		}

		if (!target.length) {
			return;
		}

		if (target.hasClass('pwatg-last-generated')) {
			target.html('<strong>' + escapeHtml(label) + '</strong> ' + escapeHtml(value));
			return;
		}

		target.html('<strong>' + escapeHtml(label) + '</strong> ' + escapeHtml(value));
	}

	function setButtonBusy($button, busy) {
		$button.toggleClass('disabled', !!busy);
		$button.attr('aria-disabled', busy ? 'true' : 'false');
		$button.data('pwatgBusy', !!busy);
		if (busy) {
			$button.text(t('generatingButton', 'Generating...'));
			return;
		}

		$button.text(getActionLabel(readHasAltFromButton($button)));
	}

	function handleGenerateClick(event) {
		const $button = $(event.currentTarget);
		const href = $button.attr('href') || '';

		if (!href || !ajaxAction) {
			return;
		}

		event.preventDefault();

		if ($button.data('pwatgBusy')) {
			return;
		}

		const parsed = parseActionUrl(href);
		const attachmentFromData = parseInt($button.attr('data-attachment-id') || '0', 10);
		const attachmentId = parsed.attachmentId || (Number.isNaN(attachmentFromData) ? 0 : attachmentFromData);
		const nonce = parsed.nonce;
		const $status = getStatusContainerForButton($button);

		if (!attachmentId || !nonce) {
			if ($status.length) {
				$status.text(t('error', 'Could not generate alt text for this image.'));
			}
			return;
		}

		setButtonBusy($button, true);

		$.post(window.ajaxurl, {
			action: ajaxAction,
			attachment_id: attachmentId,
			nonce: nonce
		}).done(function(response) {
			const payload = response && response.data ? response.data : {};
			const statusKey = payload.status || (response && response.success ? 'updated' : 'error');
			const message = payload.message || t(statusKey, t('error', 'Could not generate alt text for this image.'));
			let hasAlt = readHasAltFromButton($button);

			if ($status.length) {
				$status.text(message);
			}

			if (typeof payload.alt_text === 'string') {
				hasAlt = hasAltTextValue(payload.alt_text);
				const $altInput = getAltInputForButton($button);
				if ($altInput.length) {
					$altInput.val(payload.alt_text);
					$altInput.trigger('change');
				}
			}

			writeHasAltToButton($button, hasAlt);

			setLastGeneratedForButton($button, payload.last_generated || t('never', 'Never'));
		}).fail(function(xhr) {
			let message = t('error', 'Could not generate alt text for this image.');
			if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
				message = xhr.responseJSON.data.message;
			}

			if ($status.length) {
				$status.text(message);
			}
		}).always(function() {
			setButtonBusy($button, false);
		});
	}

	function renderInlineAction($target, actionData, cssClass) {
		if (!$target.length || !actionData || !actionData.url) {
			return;
		}

		const className = cssClass || 'pwatg-inline-manual-action';
		const existing = $target.find('.' + className).first();
		const $wrapper = $('<div>')
			.addClass(className)
			.addClass('pwatg-inline-action');

		$('<a>')
			.addClass('button button-secondary')
			.addClass('pwatg-generate-alt-action')
			.attr('href', String(actionData.url))
			.attr('data-attachment-id', String(actionData.attachmentId || ''))
			.attr('data-has-alt', actionData.hasAlt ? '1' : '0')
			.text(getActionLabel(!!actionData.hasAlt))
			.appendTo($wrapper);

		const $meta = $('<p>').addClass('pwatg-inline-action-meta');
		$('<strong>').text(t('lastGeneratedLabel', 'Last generated:')).appendTo($meta);
		$meta.append(document.createTextNode(' ' + String(actionData.last || t('never', 'Never'))));
		$wrapper.append($meta);

		if (existing.length) {
			existing.replaceWith($wrapper);
		} else {
			$target.append($wrapper);
		}
	}

	function placeForAttachmentEditScreen() {
		const altInput = $('input[name="_wp_attachment_image_alt"], textarea[name="_wp_attachment_image_alt"]').first();
		if (!altInput.length) {
			return;
		}

		let target = altInput.closest('td').first();
		if (!target.length) {
			target = altInput.parent();
		}

		const fromCompat = getActionDataFromCompat($(document.body));
		const actionData = fromCompat && fromCompat.url ? fromCompat : inlineFallback;
		actionData.hasAlt = hasAltTextValue(altInput.val());
		renderInlineAction(target, actionData, 'pwatg-inline-manual-action');

		if (fromCompat && fromCompat.row) {
			fromCompat.row.hide();
		}
	}

	function placeForMediaModal() {
		$('.media-modal:visible .attachment-details, .media-modal:visible .attachment-info').each(function() {
			const panel = $(this);
			const altField = panel.find('.setting[data-setting="alt"], .setting.alt-text, .attachment-alt-text').first();
			if (!altField.length) {
				return;
			}

			const scope = panel.closest('.media-modal-content, .media-modal, body');
			const actionData = getActionDataFromCompat(scope);
			if (!actionData || !actionData.url) {
				return;
			}

			renderInlineAction(altField, actionData, 'pwatg-inline-modal-action');
			if (actionData.row) {
				actionData.row.hide();
			}
		});
	}

	function hideDuplicateCompatRows() {
		const rows = $('tr.compat-field-pwatg_generate_alt');
		rows.not(':first').hide();
	}

	function refreshPlacement() {
		hideDuplicateCompatRows();
		placeForAttachmentEditScreen();
		placeForMediaModal();
	}

	refreshPlacement();
	setTimeout(refreshPlacement, 250);
	setTimeout(refreshPlacement, 700);
	setTimeout(refreshPlacement, 1200);

	$(document).on('keyup change', function() {
		refreshPlacement();
	});

	$(document).on('attachment:compat:ready wp-mediaelement-loaded', function() {
		refreshPlacement();
	});

	$(document).on('click', 'a.pwatg-generate-alt-action, .pwatg-inline-action a.button, .pwatg-inline-action a.button-secondary', handleGenerateClick);
});
