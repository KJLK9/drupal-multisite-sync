<?php

declare(strict_types=1);

namespace Drupal\site_b_catalog\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates UniqueAgreementConstraint.
 */
final class UniqueAgreementConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Constructs the validator.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('entity_type.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof UniqueAgreementConstraint) {
      throw new UnexpectedTypeException($constraint, UniqueAgreementConstraint::class);
    }
    if (!$value instanceof FieldableEntityInterface) {
      return;
    }

    $account = $value->get('account')->target_id;
    $item = $value->get('item')->target_id;
    // A missing reference is reported by the required constraint of its field.
    if ($account === NULL || $item === NULL) {
      return;
    }

    $query = $this->entityTypeManager->getStorage($value->getEntityTypeId())->getQuery()
      ->accessCheck(FALSE)
      ->condition('account', $account)
      ->condition('item', $item)
      ->range(0, 1);
    if (!$value->isNew()) {
      $query->condition('id', $value->id(), '<>');
    }
    if ($query->execute() !== []) {
      $this->context->buildViolation($constraint->message)->atPath('item')->addViolation();
    }
  }

}
