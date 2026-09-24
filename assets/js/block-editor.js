(function(wp) {
  if (!wp || !wp.hooks || !wp.compose || !wp.element || !wp.blockEditor || !wp.components || !wp.apiFetch) {
    return;
  }

  const data = window.pwatgBlockEditorData || {};
  const el = wp.element.createElement;
  const Fragment = wp.element.Fragment;
  const useState = wp.element.useState;
  const InspectorControls = wp.blockEditor.InspectorControls;
  const PanelBody = wp.components.PanelBody;
  const Button = wp.components.Button;
  const Notice = wp.components.Notice;
  const __ = wp.i18n.__;
  const restPath = data.restPath || '/pwatg/v1/attachments/';

  function showSnackbar(message) {
    if (wp.data && wp.data.dispatch('core/notices')) {
      wp.data.dispatch('core/notices').createNotice('success', message, { type: 'snackbar', isDismissible: true });
    }
  }

  function GeneratePanel(props) {
    const attachmentId = props.attributes.id;
    const hasAlt = !!(props.attributes.alt && String(props.attributes.alt).trim());
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    function generate() {
      setBusy(true);
      setError('');

      wp.apiFetch({
        path: restPath + attachmentId + '/alt-text',
        method: 'POST',
        data: { force: true }
      }).then(function(response) {
        if (response && response.alt_text) {
          props.setAttributes({ alt: response.alt_text });
          showSnackbar(__('Alt text generated.', 'presswell-alt-text-generator'));
        }
      }).catch(function(response) {
        setError(response && response.message ? response.message : __('Could not generate alt text for this image.', 'presswell-alt-text-generator'));
      }).finally(function() {
        setBusy(false);
      });
    }

    return el(
      PanelBody,
      { title: __('Alt Text Generator', 'presswell-alt-text-generator'), initialOpen: true },
      el(
        Button,
        { variant: 'secondary', isBusy: busy, disabled: busy, onClick: generate },
        busy
          ? __('Generating...', 'presswell-alt-text-generator')
          : (hasAlt ? __('Regenerate Alt Text', 'presswell-alt-text-generator') : __('Generate Alt Text', 'presswell-alt-text-generator'))
      ),
      el('p', { className: 'components-base-control__help' }, __('Also updates the alt text saved with the image in the Media Library.', 'presswell-alt-text-generator')),
      error ? el(Notice, { status: 'error', isDismissible: false }, error) : null
    );
  }

  const withAltTextPanel = wp.compose.createHigherOrderComponent(function(BlockEdit) {
    return function(props) {
      // Only images from the Media Library have an attachment to describe.
      if ('core/image' !== props.name || !props.attributes.id || !props.isSelected) {
        return el(BlockEdit, props);
      }

      return el(
        Fragment,
        null,
        el(BlockEdit, props),
        el(InspectorControls, null, el(GeneratePanel, props))
      );
    };
  }, 'withAltTextPanel');

  wp.hooks.addFilter('editor.BlockEdit', 'presswell-alt-text-generator/image-alt-text', withAltTextPanel);
})(window.wp);
