<?php

declare(strict_types=1);

namespace Drupal\import_engine\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates UniqueItemsConstraint.
 */
final class UniqueItemsConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof UniqueItemsConstraint) {
      throw new UnexpectedTypeException($constraint, UniqueItemsConstraint::class);
    }
    if (!is_iterable($value)) {
      return;
    }
    $seen = [];
    foreach ($value as $delta => $item) {
      $item = is_object($item) && method_exists($item, 'getValue') ? $item->getValue() : $item;
      if (!is_scalar($item)) {
        continue;
      }
      if (isset($seen[(string) $item])) {
        $this->context->buildViolation($constraint->message, ['@value' => (string) $item])
          ->atPath((string) $delta)
          ->addViolation();
      }
      $seen[(string) $item] = TRUE;
    }
  }

}
