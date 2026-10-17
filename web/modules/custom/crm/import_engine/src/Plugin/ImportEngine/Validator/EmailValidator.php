<?php

declare(strict_types=1);

namespace Drupal\import_engine\Plugin\ImportEngine\Validator;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\import_engine\Attribute\ImportValidator;
use Drupal\import_engine\Validator\ValidationOutcome;
use Drupal\import_engine\Validator\ValidatorPluginBase;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Validation;

/**
 * Accepts an email address.
 *
 * The check is that of an HTML form field (what a browser accepts), with two
 * things added that a form does not ask: a dot in the domain, and the length
 * limit of an address, 254 characters. It says that an address is well formed,
 * not that the mailbox is there.
 */
#[ImportValidator(
  id: 'email',
  label: new TranslatableMarkup('Email address'),
  mappers: ['string', 'join'],
  description: new TranslatableMarkup('Only a well formed email address.'),
)]
final class EmailValidator extends ValidatorPluginBase {

  /**
   * {@inheritdoc}
   */
  public function validate(string|int|float $value): ValidationOutcome {
    $address = trim((string) $value);
    $violations = Validation::createValidator()->validate($address, new Email(mode: Email::VALIDATION_MODE_HTML5));
    $domain = substr(strrchr($address, '@') ?: '', 1);
    if (count($violations) > 0 || strlen($address) > 254 || !str_contains($domain, '.')) {
      return ValidationOutcome::invalid('is not a valid email address');
    }
    return ValidationOutcome::valid($address);
  }

}
