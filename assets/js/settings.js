(function() {
  const data = window.pwatgSettingsData || {};
  const optionKey = data.optionKey || 'pwatg_settings';
  const serviceSelect = document.querySelector('select[name="' + optionKey + '[service]"]');
  const modelSelect = document.querySelector('select[name="' + optionKey + '[model]"]');
  const apiKeyWraps = document.querySelectorAll('.pwatg-api-key-wrap');
  const testForm = document.getElementById('pwatg-test-connection-form');
  const modelMap = data.modelMap || {};
  const currentModel = data.currentModel || '';

  if (!serviceSelect || !modelSelect) {
    return;
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
    const service = serviceSelect.value || 'openai';
    updateApiKeyField(service);
    updateModels(service);
  }

  function syncTestFormFields() {
    if (!testForm) {
      return;
    }

    const service = serviceSelect.value || 'openai';
    const model = modelSelect.value || '';
    const apiInput = document.querySelector('input[name="' + optionKey + '[api_keys][' + service + ']"]');
    const apiKey = apiInput ? apiInput.value : '';

    testForm.querySelector('input[name="service"]').value = service;
    testForm.querySelector('input[name="model"]').value = model;
    testForm.querySelector('input[name="api_key"]').value = apiKey;
  }

  serviceSelect.addEventListener('change', syncFields);
  if (testForm) {
    testForm.addEventListener('submit', syncTestFormFields);
  }
  syncFields();
})();
