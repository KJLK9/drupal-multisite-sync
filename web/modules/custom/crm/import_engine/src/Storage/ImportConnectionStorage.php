<?php

declare(strict_types=1);

namespace Drupal\import_engine\Storage;

use Drupal\Core\Config\Entity\ConfigEntityStorage;
use Drupal\Core\Entity\EntityStorageException;

/**
 * Storage of import connections.
 *
 * A connection that imports use cannot be deleted: they would have nothing to
 * read from. This is checked here, before anything else happens, because core
 * deletes the config that depends on a deleted entity (in preDelete, before
 * hook_predelete): here that would silently delete the imports.
 */
class ImportConnectionStorage extends ConfigEntityStorage {

  /**
   * {@inheritdoc}
   *
   * @param array<int|string, \Drupal\Core\Entity\EntityInterface> $entities
   *   The connections to delete.
   */
  public function delete(array $entities): void {
    foreach ($entities as $entity) {
      $users = $this->usedBy((string) $entity->id());
      if ($users !== []) {
        throw new EntityStorageException(sprintf('The connection "%s" cannot be deleted: it is used by the imports %s.', $entity->id(), implode(', ', $users)));
      }
    }
    parent::delete($entities);
  }

  /**
   * Returns the IDs of the imports that use a connection.
   *
   * @param string $id
   *   The ID of the connection.
   *
   * @return list<string>
   *   The IDs, sorted.
   */
  public function usedBy(string $id): array {
    $prefix = 'import_engine.import_definition.';
    $users = [];
    foreach ($this->configFactory->listAll($prefix) as $name) {
      if ($this->configFactory->get($name)->get('connection') === $id) {
        $users[] = substr($name, strlen($prefix));
      }
    }
    sort($users);
    return $users;
  }

}
