<?php

declare(strict_types=1);

namespace Drupal\import_engine\Plugin\ImportEngine\Validator;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\import_engine\Attribute\ImportValidator;
use Drupal\import_engine\Validator\ValidationOutcome;
use Drupal\import_engine\Validator\ValidatorPluginBase;

/**
 * Accepts a telephone number that is plausible, and can write it one way.
 *
 * This is a check of shape, not of numbering plans: spaces, dashes, dots and
 * brackets are ignored, a leading 00 means +, and what is left must be an
 * optional + and 7 to 15 digits (the limit of E.164, the international format).
 * A 0 in brackets (+31 (0)20 ...) is left out.
 * It does not know which numbers exist in which country. A number written
 * nationally (06...) needs the calling code of the country, which is a setting;
 * without it a number must be written internationally.
 *
 * Whoever needs more than that (does this mobile number exist, is it of this
 * country) needs a library with the numbering plans of the world, such as
 * libphonenumber. That is not here on purpose: a dependency of that size for a
 * check of shape.
 */
#[ImportValidator(
  id: 'phone',
  label: new TranslatableMarkup('Telephone number'),
  mappers: ['string', 'join'],
  description: new TranslatableMarkup('A plausible telephone number: digits, 7 to 15, with an optional +.'),
)]
final class PhoneValidator extends ValidatorPluginBase {

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The default settings.
   */
  public function defaultConfiguration(): array {
    return ['normalize' => TRUE, 'default_country_code' => ''];
  }

  /**
   * {@inheritdoc}
   */
  public function validate(string|int|float $value): ValidationOutcome {
    // "+31 (0)20 ...": the 0 in brackets is the one that is dropped when the
    // country code is used, so it is not a digit of the number.
    $given = trim((string) preg_replace('/\(0\)/', '', (string) $value));
    // Only what a person writes into a number is allowed besides the digits.
    if (preg_match('/^\+?[0-9 .\-()\/]+$/', $given) !== 1) {
      return ValidationOutcome::invalid('is not a telephone number');
    }
    $international = str_starts_with($given, '+');
    $digits = (string) preg_replace('/\D/', '', $given);
    if (!$international && str_starts_with($digits, '00')) {
      $international = TRUE;
      $digits = substr($digits, 2);
    }
    $code = (string) $this->configuration['default_country_code'];
    if (!$international) {
      if ($code === '' || !str_starts_with($digits, '0')) {
        return ValidationOutcome::invalid('is not written with a country code');
      }
      $digits = $code . substr($digits, 1);
    }
    if (strlen($digits) < 7 || strlen($digits) > 15 || str_starts_with($digits, '0')) {
      return ValidationOutcome::invalid('is not a plausible telephone number');
    }
    return ValidationOutcome::valid($this->configuration['normalize'] ? '+' . $digits : $given);
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
    $form['normalize'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Write it as +31612345678'),
      '#description' => $this->t('Without spaces, dashes and brackets, with the country code. Off keeps the number as it was given.'),
      '#default_value' => $this->configuration['normalize'],
    ];
    $form['default_country_code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Country code of numbers without one'),
      '#description' => $this->t('For example 31 for the Netherlands, so that 06 12345678 becomes +31612345678. Empty: a number must be written with its country code.'),
      '#default_value' => $this->configuration['default_country_code'],
      '#size' => 4,
      '#maxlength' => 3,
    ];
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
    if (preg_match('/^[0-9]{0,3}$/', trim((string) $form_state->getValue('default_country_code'))) !== 1) {
      $form_state->setErrorByName('default_country_code', $this->t('A country code is one to three digits, without a + or zeros.'));
    }
  }

}
