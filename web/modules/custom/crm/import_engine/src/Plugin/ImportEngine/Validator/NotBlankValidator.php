<?php

declare(strict_types=1);

namespace Drupal\import_engine\Plugin\ImportEngine\Validator;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\import_engine\Attribute\ImportValidator;
use Drupal\import_engine\Validator\ValidationOutcome;
use Drupal\import_engine\Validator\ValidatorPluginBase;

/**
 * Requires a value: not missing, not empty, not only white space.
 *
 * The only validator that judges an empty value; the others leave it alone, so
 * that a field that may be empty can still have a format.
 */
#[ImportValidator(
  id: 'not_blank',
  label: new TranslatableMarkup('Has a value'),
  mappers: ['string', 'join', 'text', 'number', 'timestamp'],
  description: new TranslatableMarkup('The value must be there.'),
  weight: -10,
)]
final class NotBlankValidator extends ValidatorPluginBase {

  /**
   * {@inheritdoc}
   */
  public function validatesEmpty(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function validate(string|int|float $value): ValidationOutcome {
    return trim((string) $value) === '' ? ValidationOutcome::invalid('has no value') : ValidationOutcome::valid($value);
  }

}
