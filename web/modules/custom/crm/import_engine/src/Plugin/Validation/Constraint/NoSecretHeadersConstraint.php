<?php

declare(strict_types=1);

namespace Drupal\import_engine\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Checks that the headers of a source do not carry credentials.
 *
 * Headers are stored in configuration, which is exported and committed. A key
 * belongs in an environment variable, sent by the authentication plugin.
 */
#[Constraint(
  id: 'NoSecretHeaders',
  label: new TranslatableMarkup('No credentials in headers', [], ['context' => 'Validation']),
)]
class NoSecretHeadersConstraint extends SymfonyConstraint {

  /**
   * The message when a header looks like a credential.
   */
  public string $message = 'The header "@name" looks like a credential. Keys do not belong in the configuration: use the authentication, which reads them from the environment.';

}
