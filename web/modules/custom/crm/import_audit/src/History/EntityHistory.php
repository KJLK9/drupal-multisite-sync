<?php

declare(strict_types=1);

namespace Drupal\import_audit\History;

use Drupal\import_engine\Storage\EventLog;
use Drupal\import_engine\Storage\EventRecord;
use Drupal\import_engine\Storage\MappingStore;

/**
 * Finds what the imports did to an entity, from the event log of the engine.
 *
 * Only what the log still holds: events are removed after the retention of the
 * engine, and a change made by hand was never logged.
 */
final class EntityHistory {

  /**
   * Constructs the history.
   */
  public function __construct(
    private readonly MappingStore $mapping,
    private readonly EventLog $events,
  ) {
  }

  /**
   * Whether an import ever wrote the entity.
   */
  public function isImported(string $entityTypeId, string $entityId): bool {
    return $this->mapping->forTarget($entityTypeId, $entityId, 1) !== [];
  }

  /**
   * Returns the events of the imports that wrote the entity, newest first.
   *
   * @return list<\Drupal\import_engine\Storage\EventRecord>
   *   The events.
   */
  public function forEntity(string $entityTypeId, string $entityId, int $limit = 100): array {
    $entries = [];
    foreach ($this->mapping->forTarget($entityTypeId, $entityId) as $record) {
      foreach ($this->events->history($record->definitionId, $record->key, $limit) as $event) {
        $entries[] = $event;
      }
    }
    usort($entries, static fn (EventRecord $a, EventRecord $b): int => [$b->occurred, $b->id] <=> [$a->occurred, $a->id]);
    return array_slice($entries, 0, $limit);
  }

}
