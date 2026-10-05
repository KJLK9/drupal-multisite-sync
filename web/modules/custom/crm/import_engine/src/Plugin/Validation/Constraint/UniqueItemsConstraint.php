<?php

declare(strict_types=1);

namespace Drupal\import_engine\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Checks that no value occurs twice in a list.
 */
#[Constraint(
  id: 'UniqueItems',
  label: new TranslatableMarkup('Unique items', [], ['context' => 'Validation']),
)]
class UniqueItemsConstraint extends SymfonyConstraint {

  /**
   * The message when a value occurs twice.
   */
  public string $message = '"@value" is in the list more than once.';

}
