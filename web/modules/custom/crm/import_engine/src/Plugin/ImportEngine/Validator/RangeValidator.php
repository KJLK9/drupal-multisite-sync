<?php

declare(strict_types=1);

namespace Drupal\import_engine\Plugin\ImportEngine\Validator;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\import_engine\Attribute\ImportValidator;
use Drupal\import_engine\Validator\ValidationOutcome;
use Drupal\import_engine\Validator\ValidatorPluginBase;

/**
 * Accepts a number between a minimum and a maximum, both optional.
 */
#[ImportValidator(
  id: 'range',
  label: new TranslatableMarkup('Number between'),
  mappers: ['number'],
  description: new TranslatableMarkup('A smallest and a largest number.'),
)]
final class RangeValidator extends ValidatorPluginBase {

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The default settings: NULL means no limit.
   */
  public function defaultConfiguration(): array {
    return ['min' => NULL, 'max' => NULL];
  }

  /**
   * {@inheritdoc}
   */
  public function validate(string|int|float $value): ValidationOutcome {
    if (!is_numeric($value)) {
      return ValidationOutcome::invalid('is not a number');
    }
    $number = (float) $value;
    $min = $this->configuration['min'];
    $max = $this->configuration['max'];
    if (is_numeric($min) && $number < (float) $min) {
      return ValidationOutcome::invalid(sprintf('is less than %s', (string) $min));
    }
    if (is_numeric($max) && $number > (float) $max) {
      return ValidationOutcome::invalid(sprintf('is more than %s', (string) $max));
    }
    return ValidationOutcome::valid($value);
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $values
   *   The submitted values.
   *
   * @return array<string, mixed>
   *   The values: an empty field is no limit, otherwise a number.
   */
  protected function normalizeFormValues(array $values): array {
    foreach (['min', 'max'] as $key) {
      $given = trim((string) ($values[$key] ?? ''));
      $values[$key] = $given === '' ? NULL : (float) $given;
    }
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
    foreach (['min' => $this->t('Smallest'), 'max' => $this->t('Largest')] as $key => $title) {
      $form[$key] = [
        '#type' => 'number',
        '#title' => $title,
        '#step' => 'any',
        '#default_value' => $this->configuration[$key],
        '#description' => $this->t('Empty for no limit.'),
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $min = trim((string) $form_state->getValue('min'));
    $max = trim((string) $form_state->getValue('max'));
    if ($min !== '' && $max !== '' && (float) $min > (float) $max) {
      $form_state->setErrorByName('min', $this->t('The smallest number cannot be more than the largest.'));
    }
  }

}
