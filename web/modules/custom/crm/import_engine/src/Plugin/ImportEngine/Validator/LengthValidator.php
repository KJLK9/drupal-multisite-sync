<?php

declare(strict_types=1);

namespace Drupal\import_engine\Plugin\ImportEngine\Validator;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\import_engine\Attribute\ImportValidator;
use Drupal\import_engine\Validator\ValidationOutcome;
use Drupal\import_engine\Validator\ValidatorPluginBase;

/**
 * Accepts a text of a length between a minimum and a maximum, in characters.
 */
#[ImportValidator(
  id: 'length',
  label: new TranslatableMarkup('Length'),
  mappers: ['string', 'join', 'text'],
  description: new TranslatableMarkup('A shortest and a longest length, in characters.'),
)]
final class LengthValidator extends ValidatorPluginBase {

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The default settings: 0 means no limit.
   */
  public function defaultConfiguration(): array {
    return ['min' => 0, 'max' => 0];
  }

  /**
   * {@inheritdoc}
   */
  public function validate(string|int|float $value): ValidationOutcome {
    $length = mb_strlen((string) $value);
    $min = (int) $this->configuration['min'];
    $max = (int) $this->configuration['max'];
    if ($min > 0 && $length < $min) {
      return ValidationOutcome::invalid(sprintf('is shorter than %d characters', $min));
    }
    if ($max > 0 && $length > $max) {
      return ValidationOutcome::invalid(sprintf('is longer than %d characters', $max));
    }
    return ValidationOutcome::valid($value);
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
    foreach (['min' => $this->t('Shortest, in characters'), 'max' => $this->t('Longest, in characters')] as $key => $title) {
      $form[$key] = [
        '#type' => 'number',
        '#title' => $title,
        '#default_value' => $this->configuration[$key],
        '#min' => 0,
        '#description' => $this->t('0 for no limit.'),
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
    $min = (int) $form_state->getValue('min');
    $max = (int) $form_state->getValue('max');
    if ($max > 0 && $min > $max) {
      $form_state->setErrorByName('min', $this->t('The shortest length cannot be more than the longest.'));
    }
  }

}
