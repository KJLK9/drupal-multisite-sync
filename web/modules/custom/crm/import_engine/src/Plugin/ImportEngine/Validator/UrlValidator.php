<?php

declare(strict_types=1);

namespace Drupal\import_engine\Plugin\ImportEngine\Validator;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\import_engine\Attribute\ImportValidator;
use Drupal\import_engine\Validator\ValidationOutcome;
use Drupal\import_engine\Validator\ValidatorPluginBase;

/**
 * Accepts a web address: http or https, with a host.
 */
#[ImportValidator(
  id: 'url',
  label: new TranslatableMarkup('Web address'),
  mappers: ['string', 'join'],
  description: new TranslatableMarkup('Only an http or https address.'),
)]
final class UrlValidator extends ValidatorPluginBase {

  /**
   * {@inheritdoc}
   */
  public function validate(string|int|float $value): ValidationOutcome {
    $address = trim((string) $value);
    $parts = parse_url($address);
    $valid = strlen($address) <= 2048
      && filter_var($address, FILTER_VALIDATE_URL) !== FALSE
      && is_array($parts)
      && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], TRUE)
      && ($parts['host'] ?? '') !== '';
    return $valid ? ValidationOutcome::valid($address) : ValidationOutcome::invalid('is not a valid web address (http or https)');
  }

}
