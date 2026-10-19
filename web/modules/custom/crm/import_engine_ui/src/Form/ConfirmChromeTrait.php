<?php

declare(strict_types=1);

namespace Drupal\import_engine_ui\Form;

use Drupal\Component\Utility\Html;
use Drupal\import_engine_ui\Help\Chrome;

/**
 * Gives a confirmation form the look of the other screens of the engine.
 *
 * The question is shown in the card, so that it is not hidden by the bar that
 * stays in view; what the form says about the consequences moves into a panel
 * at its side; and the button that confirms has the colour of what it does: red
 * for what cannot be undone, the accent for the rest.
 *
 * The form that uses it extends \Drupal\Core\Form\ConfirmFormBase.
 */
trait ConfirmChromeTrait {

  /**
   * Dresses the form that ConfirmFormBase made.
   *
   * @param array<string, mixed> $form
   *   The form, as the parent built it.
   * @param string $kind
   *   One of: danger, for what cannot be undone; careful, for what has
   *   consequences that can be undone; or plain.
   * @param list<string> $more
   *   More sentences for the panel, made by t() so that what is put in them is
   *   escaped.
   *
   * @return array<string, mixed>
   *   The form.
   */
  protected function chromeConfirm(array $form, string $kind = 'plain', array $more = []): array {
    $form['#attributes']['class'][] = 'import-engine-form';
    $form['#attached']['library'][] = 'import_engine_ui/wizard';
    $pills = match ($kind) {
      'danger' => [['failed', (string) $this->t('Cannot be undone')]],
      'careful' => [['template', (string) $this->t('Take care')]],
      default => [],
    };
    // The question was made by t(), which has escaped what it holds; the header
    // escapes again, so it gets the plain text.
    $form['confirm_header'] = Chrome::header(
      Html::decodeEntities(strip_tags((string) $this->getQuestion())),
      $pills,
    );
    $sentences = [(string) $this->getDescription(), ...$more];
    unset($form['description']);
    $form['summary_plain'] = Chrome::panel(
      'confirm-summary-plain',
      (string) $this->t('What happens'),
      (string) $this->t('Read this before you confirm.'),
      $sentences,
    );
    if (isset($form['actions']['submit'])) {
      $form['actions']['submit']['#button_type'] = $kind === 'danger' ? 'danger' : 'primary';
    }
    if (isset($form['actions']['cancel'])) {
      $form['actions']['cancel']['#attributes']['class'] = ['dialog-cancel'];
    }
    return $form;
  }

  /**
   * Builds a line that says why a confirmation cannot be given.
   *
   * @param string $text
   *   The text, made by t() so that what is put in it is escaped.
   *
   * @return array<string, mixed>
   *   The element.
   */
  protected function blockedNotice(string $text): array {
    return [
      '#markup' => '<p class="import-callout">' . $text . '</p>',
      '#weight' => -20,
    ];
  }

}
