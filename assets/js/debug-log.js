/**
 * Debug tab log viewer. The same in every Presswell plugin, apart from its prefix.
 */
(function() {
  'use strict';

  const data = window.PWATG_DEBUG_LOG || {};
  const i18n = data.i18n || {};
  const id = 'pwatg-debug-log';
  const root = document.getElementById(id);

  if (!root || !data.ajaxUrl || !data.actions) {
    return;
  }

  const loading = root.querySelector('.' + id + '-loading');
  const body = root.querySelector('.' + id + '-body');
  const status = document.getElementById(id + '-status');
  const output = document.getElementById(id + '-output');
  const refreshButton = document.getElementById(id + '-refresh');
  const clearButton = document.getElementById(id + '-clear');
  const downloadButton = document.getElementById(id + '-download');

  function t(key, fallback) {
    return Object.prototype.hasOwnProperty.call(i18n, key) ? i18n[key] : fallback;
  }

  function request(action, method) {
    const params = new URLSearchParams({ action: action, nonce: data.nonce || '' });
    const options = { method: method, credentials: 'same-origin' };
    let url = data.ajaxUrl;

    if ('POST' === method) {
      options.body = params;
    } else {
      url += (url.indexOf('?') === -1 ? '?' : '&') + params.toString();
    }

    return fetch(url, options).then(function(response) {
      return response.json();
    });
  }

  // While loading, only the spinner shows.
  function setLoading(isLoading) {
    root.setAttribute('aria-busy', isLoading ? 'true' : 'false');
    loading.hidden = !isLoading;
    body.hidden = isLoading;
  }

  function showError() {
    status.textContent = t('requestFailed', 'Request failed.');
    setLoading(false);
  }

  function formatSize(bytes) {
    if (bytes >= 1048576) {
      return t('sizeMb', '%s MB').replace('%s', (bytes / 1048576).toFixed(1));
    }

    return t('sizeKb', '%s KB').replace('%s', String(Math.max(1, Math.round(bytes / 1024))));
  }

  // Log contents are always inserted as text, never parsed as HTML.
  function render(log) {
    let text = log.enabled && log.expires_at
      ? t('logEnabledUntil', 'Logging is on until %s.').replace('%s', new Date(log.expires_at * 1000).toLocaleString())
      : t('logDisabled', 'Logging is off. Turn it on in Settings.');

    if (log.exists) {
      text += ' ' + t('logSize', 'The log is %s.').replace('%s', formatSize(Number(log.size) || 0));

      if (log.truncated) {
        text += ' ' + t('logTruncated', 'Showing the most recent lines; download for the full log.');
      }
    }

    status.textContent = text;

    // Link to wherever logging is switched on, when this user can get there.
    if (!log.enabled && data.settingsUrl) {
      const link = document.createElement('a');
      link.href = data.settingsUrl;
      link.textContent = t('openSettings', 'Open settings');
      status.appendChild(document.createTextNode(' '));
      status.appendChild(link);
    }
    output.textContent = log.exists && log.contents ? log.contents : t('logEmpty', 'The log is empty.');
    clearButton.disabled = !log.exists;
    if (downloadButton) {
      downloadButton.disabled = !log.exists;
    }
  }

  function readLog() {
    setLoading(true);

    request(data.actions.readLog, 'GET').then(function(response) {
      if (!response || !response.success || !response.data) {
        showError();
        return;
      }

      render(response.data);
      setLoading(false);

      // Newest entries are at the bottom; scroll once the log is visible.
      output.scrollTop = output.scrollHeight;
    }).catch(showError);
  }

  function clearLog() {
    if (!window.confirm(t('confirmClear', 'Delete the debug log? This can\'t be undone.'))) {
      return;
    }

    setLoading(true);

    request(data.actions.clearLog, 'POST').then(function(response) {
      if (!response || !response.success) {
        showError();
        return;
      }

      readLog();
    }).catch(showError);
  }

  refreshButton.addEventListener('click', readLog);
  clearButton.addEventListener('click', clearLog);
  readLog();
})();
