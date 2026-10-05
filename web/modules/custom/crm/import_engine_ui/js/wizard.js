/**
 * @file
 * Puts a path of the sample in the source that was last typed in.
 */

(function (Drupal, once) {
  'use strict';

  // The source field that had the focus last. A click on a path moves the
  // focus to the path, so the field has to be remembered.
  let lastSource = null;

  Drupal.behaviors.importWizardPaths = {
    attach(context) {
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
