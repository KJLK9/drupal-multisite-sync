<?php

declare(strict_types=1);

namespace Drupal\import_engine\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\import_engine\Validator\ValidatorPluginManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates ValidatorsFitMapperConstraint.
 */
final class ValidatorsFitMapperConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Constructs the validator.
   */
  public function __construct(private readonly ValidatorPluginManager $validators) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('plugin.manager.import_engine_validator'));
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof ValidatorsFitMapperConstraint) {
      throw new UnexpectedTypeException($constraint, ValidatorsFitMapperConstraint::class);
    }
    if (!is_array($value) || !is_array($value['validators'] ?? NULL)) {
      return;
    }
    $mapper = (string) ($value['mapper']['plugin'] ?? '');
    foreach ($value['validators'] as $delta => $validator) {
      $id = (string) ($validator['plugin'] ?? '');
      // A validator that does not exist is reported by its own field.
      $definition = $this->validators->getDefinition($id, FALSE);
      if ($definition !== NULL && !in_array($mapper, $definition['mappers'], TRUE)) {
        $this->context->buildViolation($constraint->message, ['@validator' => $id, '@mapper' => $mapper])
          ->atPath('validators.' . $delta . '.plugin')
          ->addViolation();
      }
    }
  }

}
