<?php

declare(strict_types=1);

namespace Drupal\site_b_catalog\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Ensures an account has at most one agreement per item.
 *
 * Backed by a unique database key (see CatalogStorageSchema); this constraint
 * reports the problem before the database does.
 */
#[Constraint(
  id: 'UniqueAgreement',
  label: new TranslatableMarkup('Unique agreement', [], ['context' => 'Validation']),
)]
class UniqueAgreementConstraint extends SymfonyConstraint {

  /**
   * The violation message.
   */
  public string $message = 'This account already has an agreement for this item.';

}
