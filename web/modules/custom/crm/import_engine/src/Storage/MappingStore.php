<?php

declare(strict_types=1);

namespace Drupal\import_engine\Storage;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * The identity of source items: which target entity each one became.
 *
 * One row per known item and import, so the table follows the size of the
 * dataset and never the number of runs. It answers "is this item new or do we
 * already have it", and "last seen" makes the sweep for deleted items one
 * indexed query: everything not seen in the latest run.
 */
final class MappingStore {

  use QueryHelpers;

  /**
   * How many keys go in one statement.
   */
  private const CHUNK = 500;

  /**
   * Constructs the store.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {
  }

  /**
   * Returns the mapping of a source item, if it is known.
   */
  public function find(string $definitionId, string $key): ?MappingRecord {
    $rows = $this->rows($this->database->select('import_mapping', 'm')
      ->fields('m')
      ->condition('definition_id', $definitionId)
      ->condition('source_key', $key));
    return $rows === [] ? NULL : $this->hydrate($rows[0]);
  }

  /**
   * Records which target entity a source item became.
   *
   * @param string $definitionId
   *   The import definition.
   * @param string $key
   *   The key of the source item.
   * @param string $targetType
   *   The entity type of the target entity.
   * @param string $targetId
   *   The ID of the target entity.
   * @param string|null $hash
   *   The hash of the mapped data in hex.
   * @param int $runId
   *   The run that handled the item.
   * @param bool $changed
   *   Whether the target was created or changed by this run.
   * @param int|null $now
   *   The time, for tests.
   */
  public function record(string $definitionId, string $key, string $targetType, string $targetId, ?string $hash, int $runId, bool $changed, ?int $now = NULL): void {
    $now ??= $this->time->getRequestTime();
    $binary = $hash === NULL ? NULL : (string) hex2bin($hash);
    $update = [
      'target_type' => $targetType,
      'target_id' => $targetId,
      'hash' => $binary,
      'last_seen_run' => $runId,
    ];
    if ($changed) {
      $update += ['last_changed_run' => $runId, 'changed' => $now];
    }
    $this->database->merge('import_mapping')
      ->keys(['definition_id' => $definitionId, 'source_key' => $key])
      ->insertFields([
        'definition_id' => $definitionId,
        'source_key' => $key,
        'target_type' => $targetType,
        'target_id' => $targetId,
        'hash' => $binary,
        'last_seen_run' => $runId,
        'first_seen_run' => $runId,
        'last_changed_run' => $runId,
        'changed' => $now,
      ])
      ->updateFields($update)
      ->execute();
  }

  /**
   * Marks keys as seen by a run.
   *
   * Used for the items on a page that is skipped because it did not change:
   * they are still in the source, so the sweep must not treat them as gone.
   * Keys that are not known yet are ignored; there is nothing to sweep.
   *
   * @param string $definitionId
   *   The import definition.
   * @param list<string> $keys
   *   The keys of the items.
   * @param int $runId
   *   The run that saw them.
   *
   * @return int
   *   How many known items were marked.
   */
  public function markSeen(string $definitionId, array $keys, int $runId): int {
    $marked = 0;
    foreach (array_chunk($keys, self::CHUNK) as $chunk) {
      $marked += (int) $this->database->update('import_mapping')
        ->fields(['last_seen_run' => $runId])
        ->condition('definition_id', $definitionId)
        ->condition('source_key', $chunk, 'IN')
        ->condition('last_seen_run', $runId, '<')
        ->execute();
    }
    return $marked;
  }

  /**
   * Returns the keys of items last seen before a run, for the sweep.
   *
   * @param string $definitionId
   *   The import definition.
   * @param int $runId
   *   The run: items last seen before it are gone from the source.
   * @param int $limit
   *   The most records to return.
   *
   * @return list<\Drupal\import_engine\Storage\MappingRecord>
   *   The records.
   */
  public function notSeenSince(string $definitionId, int $runId, int $limit): array {
    $rows = $this->rows($this->database->select('import_mapping', 'm')
      ->fields('m')
      ->condition('definition_id', $definitionId)
      ->condition('last_seen_run', $runId, '<')
      ->orderBy('source_key')
      ->range(0, $limit));
    return array_map($this->hydrate(...), $rows);
  }

  /**
   * Counts the known items of an import.
   */
  public function count(string $definitionId): int {
    $query = $this->database->select('import_mapping', 'm')->condition('definition_id', $definitionId);
    return (int) $this->statement($query->countQuery())->fetchField();
  }

  /**
   * Turns a table row into a record.
   */
  private function hydrate(\stdClass $row): MappingRecord {
    return new MappingRecord(
      (string) $row->source_key,
      $row->target_type === NULL ? NULL : (string) $row->target_type,
      $row->target_id === NULL ? NULL : (string) $row->target_id,
      $row->hash === NULL ? NULL : bin2hex((string) $row->hash),
      (int) $row->first_seen_run,
      (int) $row->last_seen_run,
      (int) $row->last_changed_run,
    );
  }

}
