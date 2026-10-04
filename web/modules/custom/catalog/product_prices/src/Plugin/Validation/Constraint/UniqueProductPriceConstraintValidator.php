<?php

declare(strict_types=1);

namespace Drupal\product_prices\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates UniqueProductPriceConstraint.
 */
final class UniqueProductPriceConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Constructs the validator.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
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
    if (!$constraint instanceof UniqueProductPriceConstraint) {
      throw new UnexpectedTypeException($constraint, UniqueProductPriceConstraint::class);
    }
    if (!$value instanceof FieldableEntityInterface) {
      return;
    }

    $product_id = $value->get('product_id')->target_id;
    $customer_id = $value->get('customer')->target_id;
    // Missing references are reported by the fields' required constraints.
    if ($product_id === NULL || $customer_id === NULL) {
      return;
    }

    $storage = $this->entityTypeManager->getStorage($value->getEntityTypeId());
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('product_id', $product_id)
      ->condition('customer', $customer_id)
      ->range(0, 1);
    if (!$value->isNew()) {
      $query->condition('id', $value->id(), '<>');
    }

    if ($query->execute() !== []) {
      $this->context->buildViolation($constraint->message)
        ->atPath('customer')
        ->addViolation();
    }
  }

}
