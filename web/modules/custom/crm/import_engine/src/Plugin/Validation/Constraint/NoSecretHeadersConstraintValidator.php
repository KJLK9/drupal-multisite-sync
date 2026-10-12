<?php

declare(strict_types=1);

namespace Drupal\import_engine\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates NoSecretHeadersConstraint.
 */
final class NoSecretHeadersConstraintValidator extends ConstraintValidator {

  /**
   * The names that are credentials, or look like one.
   */
  private const PATTERN = '/^(authorization|proxy-authorization|cookie|set-cookie)$|api[-_]?key|token|secret|passw|credential|auth/i';

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof NoSecretHeadersConstraint) {
      throw new UnexpectedTypeException($constraint, NoSecretHeadersConstraint::class);
    }
    if (!is_iterable($value)) {
      return;
    }
    foreach ($value as $name => $header) {
      if (is_string($name) && preg_match(self::PATTERN, $name) === 1) {
        $this->context->buildViolation($constraint->message, ['@name' => $name])
          ->atPath($name)
          ->addViolation();
      }
    }
  }

}
