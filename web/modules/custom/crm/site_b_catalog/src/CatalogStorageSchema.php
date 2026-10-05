<?php

declare(strict_types=1);

namespace Drupal\site_b_catalog;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * Storage schema of the catalog entities: the unique keys.
 *
 * What must be unique is guaranteed by the database, whatever way an entity is
 * saved; the constraints on the entities report the problem before the
 * database does.
 */
final class CatalogStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * The unique keys, by entity type: the name of a key and its columns.
   */
  private const UNIQUE_KEYS = [
    'account' => ['account__number' => ['number']],
    'agreement' => ['agreement__account_item' => ['account', 'item']],
  ];

  /**
   * {@inheritdoc}
   *
   * @return array<string, array<string, mixed>>
   *   The entity schema.
   */
  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE): array {
    $schema = parent::getEntitySchema($entity_type, $reset);
    $base_table = $this->storage->getBaseTable();
    foreach (self::UNIQUE_KEYS[$entity_type->id()] ?? [] as $name => $columns) {
      $schema[$base_table]['unique keys'][$name] = $columns;
    }
    return $schema;
  }

}
