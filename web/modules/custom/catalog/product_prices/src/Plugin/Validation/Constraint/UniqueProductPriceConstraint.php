<?php

declare(strict_types=1);

namespace Drupal\product_prices\Plugin\Validation\Constraint;

use Drupal\Core\Validation\Attribute\Constraint;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Ensures a customer has at most one price per product.
 *
 * Backed by a unique database key (see ProductPriceStorageSchema); this
 * constraint reports the problem before the database does.
 */
#[Constraint(
  id: 'UniqueProductPrice',
  label: new TranslatableMarkup('Unique product price', [], ['context' => 'Validation']),
)]
class UniqueProductPriceConstraint extends SymfonyConstraint {

  /**
   * The violation message.
   */
  public string $message = 'A price for this product and customer already exists.';

}
