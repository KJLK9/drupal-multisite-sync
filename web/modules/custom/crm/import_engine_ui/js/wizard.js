/**
 * @file
 * Puts a path of the sample in the source that was last typed in, and shows
 * the value that path has in the sample next to the field.
 */

(function (Drupal, once) {
  'use strict';

  // The source field that had the focus last. A click on a path moves the
  // focus to the path, so the field has to be remembered.
  let lastSource = null;

  // Says under a source field what its path holds in the sample items.
  function showExample(input, settings) {
    const wrapper = input.closest('.form-item') || input.parentElement;
    let note = wrapper.querySelector('.import-wizard-example');
    if (!note) {
      note = document.createElement('small');
      note.className = 'import-wizard-example';
      note.setAttribute('aria-live', 'polite');
      wrapper.appendChild(note);
    }
    const path = input.value.trim();
    note.classList.remove('import-wizard-example--missing');
    if (path === '' || !settings.examples) {
      note.textContent = '';
      return;
    }
    if (Object.prototype.hasOwnProperty.call(settings.examples, path)) {
      note.textContent = settings.example + ' \u201c' + settings.examples[path] + '\u201d';
    }
    else {
      note.textContent = settings.missing;
      note.classList.add('import-wizard-example--missing');
    }
  }

  Drupal.behaviors.importWizardPaths = {
    attach(context, drupalSettings) {
      const settings = drupalSettings.importWizard || {};
      once('import-wizard-example', 'input.import-wizard-source', context).forEach((input) => {
        const update = () => showExample(input, settings);
        input.addEventListener('input', update);
        input.addEventListener('change', update);
        update();
      });

      once('import-wizard-source', 'input.import-wizard-source', context).forEach((input) => {
        input.addEventListener('focus', () => {
          lastSource = input;
        });
      });

      once('import-wizard-path', 'button.import-wizard-path', context).forEach((button) => {
        button.addEventListener('click', (event) => {
          event.preventDefault();
          // The field is gone when the table was replaced by an AJAX call.
          if (!lastSource || !document.body.contains(lastSource)) {
            return;
          }
          lastSource.value = button.dataset.path;
          lastSource.dispatchEvent(new Event('input', { bubbles: true }));
          lastSource.focus();
        });
      });
    },
  };
})(Drupal, once);
