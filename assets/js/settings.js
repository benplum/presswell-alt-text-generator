(function() {
  const data = window.pwatgSettingsData || {};
  const optionKey = data.optionKey || 'pwatg_settings';
  const serviceSelect = document.querySelector('select[name="' + optionKey + '[service]"]');
  const modelSelect = document.querySelector('select[name="' + optionKey + '[model]"]');
  const connectorSourceInputs = document.querySelectorAll('input[name="' + optionKey + '[connector_source]"]');
  const coreConnectorSelect = document.querySelector('select[name="' + optionKey + '[core_connector]"]');
  const pluginApiKeyGroup = document.querySelector('.pwatg-plugin-api-key-group');
  const coreConnectorWrap = document.querySelector('.pwatg-core-connector-wrap');
  if (!serviceSelect || !modelSelect) {
    return;
  }

  const serviceRow = serviceSelect.closest('tr');
  const apiKeyRow = pluginApiKeyGroup ? pluginApiKeyGroup.closest('tr') : null;
  const coreConnectorRow = coreConnectorWrap ? coreConnectorWrap.closest('tr') : null;
  const apiKeyWraps = document.querySelectorAll('.pwatg-api-key-wrap');
  const deprecationNotice = document.querySelector('.pwatg-plugin-source-deprecated');
  const modelMap = data.modelMap || {};
  const currentModel = data.currentModel || '';
  const hasCoreConnectors = !!data.hasCoreConnectors;
  const coreConnectorServiceMap = data.coreConnectorServiceMap || {};

  function getSelectedConnectorSource() {
    if (!connectorSourceInputs.length) {
      return 'plugin';
    }

    let selected = 'plugin';
    connectorSourceInputs.forEach(function(input) {
      if (input.checked) {
        selected = input.value || 'plugin';
      }
    });

    return selected;
  }

  function getServiceForCoreConnector() {
    if (!coreConnectorSelect) {
      return '';
    }

    const connectorId = coreConnectorSelect.value || '';
    if (!connectorId || !Object.prototype.hasOwnProperty.call(coreConnectorServiceMap, connectorId)) {
      return '';
    }

    return coreConnectorServiceMap[connectorId] || '';
  }

  function updateApiKeyField(service) {
    apiKeyWraps.forEach(function(wrap) {
      const isMatch = wrap.getAttribute('data-service') === service;
      wrap.classList.toggle('is-hidden', !isMatch);
    });
  }

  function updateModels(service) {
    const models = modelMap[service] || {};
    const previousValue = modelSelect.value || currentModel || '';
    modelSelect.innerHTML = '';

    Object.keys(models).forEach(function(value) {
      const option = document.createElement('option');
      option.value = value;
      option.textContent = models[value];
      if (value === previousValue) {
        option.selected = true;
      }
      modelSelect.appendChild(option);
    });

    if (!Object.prototype.hasOwnProperty.call(models, previousValue)) {
      const firstValue = Object.keys(models)[0] || '';
      if (firstValue) {
        modelSelect.value = firstValue;
      }
    }
  }

  function syncFields() {
    const source = getSelectedConnectorSource();
    let service = serviceSelect.value || 'openai';
    const isCoreMode = hasCoreConnectors && source === 'core';

    if (isCoreMode) {
      const coreService = getServiceForCoreConnector();
      if (coreService && modelMap[coreService]) {
        service = coreService;
        serviceSelect.value = coreService;
      }
      serviceSelect.setAttribute('disabled', 'disabled');
    } else {
      serviceSelect.removeAttribute('disabled');
    }

    if (serviceRow) {
      serviceRow.classList.toggle('is-hidden', isCoreMode);
    }

    if (apiKeyRow) {
      apiKeyRow.classList.toggle('is-hidden', isCoreMode);
    }

    if (coreConnectorRow) {
      coreConnectorRow.classList.toggle('is-hidden', !isCoreMode);
    }

    if (pluginApiKeyGroup) {
      pluginApiKeyGroup.classList.toggle('is-hidden', isCoreMode);
    }

    if (coreConnectorWrap) {
      coreConnectorWrap.classList.toggle('is-hidden', !isCoreMode);
    }

    // Plugin API keys are deprecated; warn while that source is selected.
    if (deprecationNotice) {
      deprecationNotice.hidden = source !== 'plugin';
    }

    updateApiKeyField(service);
    updateModels(service);
  }

  serviceSelect.addEventListener('change', syncFields);
  if (coreConnectorSelect) {
    coreConnectorSelect.addEventListener('change', syncFields);
  }
  if (connectorSourceInputs.length) {
    connectorSourceInputs.forEach(function(input) {
      input.addEventListener('change', syncFields);
    });
  }
  syncFields();
})();
