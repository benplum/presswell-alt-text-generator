jQuery(function($) {
	const data = window.pwatgMediaData || {};
	const strings = data.strings || {};
	const inlineFallback = {
		url: data.inlineUrl || '',
		last: data.inlineLast || ''
	};

	function t(key, fallback) {
		return Object.prototype.hasOwnProperty.call(strings, key) ? strings[key] : fallback;
	}

	function escapeHtml(value) {
		return $('<div>').text(String(value || '')).html();
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
			last: lastText || t('never', 'Never'),
			row: row
		};
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
			.attr('href', String(actionData.url))
			.text(t('generateButton', 'Generate Alt Text'))
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
});
