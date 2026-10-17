<?php

declare(strict_types=1);

namespace Drupal\import_engine\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Checks that the validators of a mapping row can check what its mapper makes.
 *
 * An email validator on the value of a reference, or a range on a text, would
 * never be right; the validators declare which mappers they are for.
 */
#[Constraint(
  id: 'ValidatorsFitMapper',
  label: new TranslatableMarkup('Validators fit the mapper', [], ['context' => 'Validation']),
)]
class ValidatorsFitMapperConstraint extends SymfonyConstraint {

  /**
   * The message when a validator does not fit.
   */
  public string $message = 'The validator "@validator" cannot check the value of the mapper "@mapper".';

}
