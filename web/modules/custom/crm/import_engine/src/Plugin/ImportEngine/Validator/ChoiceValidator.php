<?php

declare(strict_types=1);

namespace Drupal\import_engine\Plugin\ImportEngine\Validator;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\import_engine\Attribute\ImportValidator;
use Drupal\import_engine\Form\TextLists;
use Drupal\import_engine\Validator\ValidationOutcome;
use Drupal\import_engine\Validator\ValidatorPluginBase;

/**
 * Accepts one of a list of values.
 */
#[ImportValidator(
  id: 'choice',
  label: new TranslatableMarkup('One of'),
  mappers: ['string', 'join'],
  description: new TranslatableMarkup('The text must be one of a list of values.'),
)]
final class ChoiceValidator extends ValidatorPluginBase {

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The default settings.
   */
  public function defaultConfiguration(): array {
    return ['allowed' => [], 'case_sensitive' => FALSE];
  }

  /**
   * {@inheritdoc}
   */
  public function validate(string|int|float $value): ValidationOutcome {
    $given = (string) $value;
    $sensitive = (bool) $this->configuration['case_sensitive'];
    foreach ((array) $this->configuration['allowed'] as $allowed) {
      if ($sensitive ? $allowed === $given : strcasecmp((string) $allowed, $given) === 0) {
        return ValidationOutcome::valid($allowed);
      }
    }
    return ValidationOutcome::invalid('is not one of the allowed values');
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $values
   *   The submitted values.
   *
   * @return array<string, mixed>
   *   The values with the list read from its text.
   */
  protected function normalizeFormValues(array $values): array {
    $values['allowed'] = TextLists::lines((string) ($values['allowed'] ?? ''));
    return $values;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<string, mixed>
   *   The form.
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['allowed'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Allowed values'),
      '#description' => $this->t('One per line. The value that is kept is the one written here.'),
      '#default_value' => TextLists::formatLines((array) $this->configuration['allowed']),
      '#rows' => 4,
      '#required' => TRUE,
    ];
    $form['case_sensitive'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Capitals must match'),
      '#default_value' => $this->configuration['case_sensitive'],
    ];
    return $form;
  }

}
