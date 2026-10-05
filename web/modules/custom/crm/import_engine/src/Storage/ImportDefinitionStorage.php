<?php

declare(strict_types=1);

namespace Drupal\import_engine\Storage;

use Drupal\Core\Config\Entity\ConfigEntityStorage;
use Drupal\Core\Entity\EntityStorageException;

/**
 * Storage of import definitions.
 *
 * An import that a run set lists cannot be deleted: the set would stop at it
 * every time. It has to come out of the set first.
 */
class ImportDefinitionStorage extends ConfigEntityStorage {

  /**
   * {@inheritdoc}
   *
   * @param array<int|string, \Drupal\Core\Entity\EntityInterface> $entities
   *   The imports to delete.
   */
  public function delete(array $entities): void {
    foreach ($entities as $entity) {
      $sets = $this->setsOf((string) $entity->id());
      if ($sets !== []) {
        throw new EntityStorageException(sprintf('The import "%s" cannot be deleted: it is in the run sets %s.', $entity->id(), implode(', ', $sets)));
      }
    }
    parent::delete($entities);
  }

  /**
   * Returns the IDs of the run sets that list an import.
   *
   * @param string $id
   *   The ID of the import.
   *
   * @return list<string>
   *   The IDs, sorted.
   */
  public function setsOf(string $id): array {
    $prefix = 'import_engine.import_run_set.';
    $sets = [];
    foreach ($this->configFactory->listAll($prefix) as $name) {
      if (in_array($id, (array) $this->configFactory->get($name)->get('imports'), TRUE)) {
        $sets[] = substr($name, strlen($prefix));
      }
    }
    sort($sets);
    return $sets;
  }

}
