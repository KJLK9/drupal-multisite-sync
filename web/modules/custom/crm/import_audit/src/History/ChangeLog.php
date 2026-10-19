<?php

declare(strict_types=1);

namespace Drupal\import_audit\History;

use Drupal\Core\Database\Connection;
use Drupal\import_engine\Event\ItemWritten;

/**
 * The changes the imports made, field by field, as long as they are kept.
 */
final class ChangeLog {

  /**
   * The rows removed per statement.
   */
  public const BATCH = 5000;

  /**
   * Constructs the log.
   */
  public function __construct(
    private readonly Connection $database,
  ) {
  }

  /**
   * Keeps the changes of a write; a write that changed no field adds nothing.
   */
  public function record(ItemWritten $event): void {
    if ($event->changes === []) {
      return;
    }
    $insert = $this->database->insert('import_audit_change')->fields([
      'occurred', 'run_id', 'definition_id', 'source_key', 'target_type', 'target_id', 'field_name', 'old_value', 'new_value',
    ]);
    foreach ($event->changes as $field => $change) {
      $insert->values([
        $event->occurred,
        $event->runId,
        $event->definitionId,
        $event->key,
        $event->targetType,
        $event->targetId,
        $field,
        $change['old'],
        $change['new'],
      ]);
    }
    $insert->execute();
  }

  /**
   * Returns the changes made to an entity, grouped by the write they belong to.
   *
   * @return array<string, list<array{field: string, old: string, new: string}>>
   *   The newest changes by "run:import:key", in the order of the fields as
   *   written.
   */
  public function forEntity(string $targetType, string $targetId, int $limit = 1000): array {
    // The module can be on before the update that makes the table has run.
    if (!$this->database->schema()->tableExists('import_audit_change')) {
      return [];
    }
    $rows = $this->database->select('import_audit_change', 'c')
      ->fields('c')
      ->condition('target_type', $targetType)
      ->condition('target_id', $targetId)
      ->orderBy('id', 'DESC')
      ->range(0, $limit)
      ->execute();
    $grouped = [];
    foreach (array_reverse($rows ? $rows->fetchAll() : []) as $row) {
      $grouped[self::group((int) $row->run_id, (string) $row->definition_id, (string) $row->source_key)][] = [
        'field' => (string) $row->field_name,
        'old' => (string) $row->old_value,
        'new' => (string) $row->new_value,
      ];
    }
    return $grouped;
  }

  /**
   * Returns the name under which the changes of one write are grouped.
   */
  public static function group(int $runId, string $definitionId, string $key): string {
    return $runId . ':' . $definitionId . ':' . $key;
  }

  /**
   * Removes the changes older than a time, a batch at a time.
   *
   * @return int
   *   How many rows were removed.
   */
  public function purge(int $olderThan, int $limit = self::BATCH): int {
    $ids = $this->database->select('import_audit_change', 'c')
      ->fields('c', ['id'])
      ->condition('occurred', $olderThan, '<')
      ->range(0, $limit)
      ->execute()
      ?->fetchCol() ?? [];
    if ($ids === []) {
      return 0;
    }
    return (int) $this->database->delete('import_audit_change')->condition('id', $ids, 'IN')->execute();
  }

}
