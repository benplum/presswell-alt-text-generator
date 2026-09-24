(function() {
  const data = window.pwatgDebugData || {};
  const i18n = data.i18n || {};
  const status = document.getElementById('pwatg-debug-log-status');
  const output = document.getElementById('pwatg-debug-log-output');
  const refreshButton = document.getElementById('pwatg-debug-log-refresh');
  const clearButton = document.getElementById('pwatg-debug-log-clear');

  if (!status || !output || !data.ajaxUrl) {
    return;
  }

  function t(key, fallback) {
    return Object.prototype.hasOwnProperty.call(i18n, key) ? i18n[key] : fallback;
  }

  function request(action, method) {
    const body = new URLSearchParams({ action: action, nonce: data.nonce || '' });
    const options = { method: method, credentials: 'same-origin' };
    let url = data.ajaxUrl;

    if ('POST' === method) {
      options.body = body;
    } else {
      url += (url.indexOf('?') === -1 ? '?' : '&') + body.toString();
    }

    return fetch(url, options).then(function(response) {
      return response.json();
    });
  }

  function setBusy(busy) {
    refreshButton.disabled = busy;
    clearButton.disabled = busy;
  }

  function readLog() {
    setBusy(true);
    status.textContent = t('loading', 'Loading...');

    request(data.actions.readLog, 'GET').then(function(response) {
      if (!response || !response.success) {
        status.textContent = t('requestFailed', 'Request failed.');
        return;
      }

      const log = response.data;
      let text = log.enabled && log.expires_at
        ? t('logEnabledUntil', 'Logging is on until %s.').replace('%s', new Date(log.expires_at * 1000).toLocaleString())
        : t('logDisabled', 'Logging is off. Turn it on in Settings.');

      if (log.truncated) {
        text += ' ' + t('logTruncated', 'Showing the most recent lines; download for the full log.');
      }

      status.textContent = text;
      output.textContent = log.exists && log.contents ? log.contents : t('logEmpty', 'The log is empty.');
      output.scrollTop = output.scrollHeight;
    }).catch(function() {
      status.textContent = t('requestFailed', 'Request failed.');
    }).finally(function() {
      setBusy(false);
    });
  }

  function clearLog() {
    if (!window.confirm(t('confirmClear', 'Delete the debug log?'))) {
      return;
    }

    setBusy(true);

    request(data.actions.clearLog, 'POST').then(function(response) {
      if (!response || !response.success) {
        status.textContent = t('requestFailed', 'Request failed.');
        setBusy(false);
        return;
      }

      readLog();
    }).catch(function() {
      status.textContent = t('requestFailed', 'Request failed.');
      setBusy(false);
    });
  }

  refreshButton.addEventListener('click', readLog);
  clearButton.addEventListener('click', clearLog);
  readLog();
})();
