<?php

declare(strict_types=1);

namespace Drupal\product_prices;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * Storage schema for product prices.
 *
 * Adds the unique key that guarantees one price per product and customer,
 * whatever way a price is saved.
 */
final class ProductPriceStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * The name of the unique key.
   */
  public const UNIQUE_KEY = 'product_price__product_customer';

  /**
   * {@inheritdoc}
   *
   * @return array<string, array<string, mixed>>
   *   The entity schema.
   */
  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE): array {
    $schema = parent::getEntitySchema($entity_type, $reset);
    $base_table = $this->storage->getBaseTable();
    $schema[$base_table]['unique keys'][self::UNIQUE_KEY] = ['product_id', 'customer'];
    return $schema;
  }

}
