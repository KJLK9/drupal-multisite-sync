<?php

declare(strict_types=1);

namespace Drupal\import_engine\Storage;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Removes everything the engine keeps for an import that no longer exists.
 *
 * The retention settings age data out, but data of a definition that is gone
 * would not be missed by anything: its mapping would stay for ever (and a new
 * import with the same ID would take over the mapping of the old one, with the
 * entities it wrote), and so would its pages and queue. Runs and events go as
 * well: there is no import left for them to be the history of.
 */
final class DefinitionCleaner {

  /**
   * How many runs are handled at a time.
   */
  private const RUNS = 100;

  /**
   * Constructs the cleaner.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  /**
   * Removes the data of an import.
   *
   * @param string $definitionId
   *   The ID of the import that was deleted.
   *
   * @return array{runs: int, items: int, events: int, mappings: int, pages: int}
   *   How many rows of each kind were removed.
   */
  public function forget(string $definitionId): array {
    $removed = ['runs' => 0, 'items' => 0, 'events' => 0, 'mappings' => 0, 'pages' => 0];
    // The tables are there once the module is installed; a definition can be
    // deleted before that (a configuration import, a test with no tables).
    if (!$this->database->schema()->tableExists('import_run')) {
      return $removed;
    }
    $storage = $this->entityTypeManager->getStorage('import_run');

    while (($ids = $this->runIds($definitionId)) !== []) {
      // The queue and the events of a run are found by the ID of the run.
      $removed['items'] += (int) $this->database->delete('import_item')->condition('run_id', $ids, 'IN')->execute();
      $removed['events'] += (int) $this->database->delete('import_event')->condition('run_id', $ids, 'IN')->execute();
      $storage->delete($storage->loadMultiple($ids));
      $removed['runs'] += count($ids);
    }
    // What is left of events that name the import without a run.
    $removed['events'] += (int) $this->database->delete('import_event')->condition('definition_id', $definitionId)->execute();
    $removed['mappings'] = (int) $this->database->delete('import_mapping')->condition('definition_id', $definitionId)->execute();
    $removed['pages'] = (int) $this->database->delete('import_page')->condition('definition_id', $definitionId)->execute();
    return $removed;
  }

  /**
   * Returns the IDs of some runs of an import.
   *
   * @return list<int>
   *   The IDs.
   */
  private function runIds(string $definitionId): array {
    $ids = $this->database->select('import_run', 'r')
      ->fields('r', ['id'])
      ->condition('definition_id', $definitionId)
      ->range(0, self::RUNS)
      ->execute()
      ?->fetchCol() ?? [];
    return array_values(array_map(intval(...), $ids));
  }

}
