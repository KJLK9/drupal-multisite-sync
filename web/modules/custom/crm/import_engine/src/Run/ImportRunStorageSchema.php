<?php

declare(strict_types=1);

namespace Drupal\import_engine\Run;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * Storage schema of runs: the indexes the interface and the purge need.
 */
final class ImportRunStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * {@inheritdoc}
   *
   * @return array<string, array<string, mixed>>
   *   The entity schema.
   */
  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE): array {
    $schema = parent::getEntitySchema($entity_type, $reset);
    $table = $this->storage->getBaseTable();
    // Runs of one import, newest first; runs by status; the purge by age.
    $schema[$table]['indexes']['import_run__definition'] = ['definition_id', 'created'];
    $schema[$table]['indexes']['import_run__status'] = ['status', 'finished'];
    $schema[$table]['indexes']['import_run__finished'] = ['finished'];
    return $schema;
  }

}
