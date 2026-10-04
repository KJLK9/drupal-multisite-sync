<?php

declare(strict_types=1);

namespace Drupal\import_engine\Storage;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * An append-only log of what changed or went wrong, item by item.
 *
 * It answers "what happened to this item, and when". Only changes and problems
 * are logged, never an item that stayed the same, so the log grows with what
 * happens and not with the size of the dataset times the number of runs.
 *
 * The primary key of the table includes the time, so a DBA can partition it by
 * date and drop old partitions instead of deleting rows, without a code change.
 */
final class EventLog {

  use QueryHelpers;

  /**
   * How many rows go in one INSERT statement.
   */
  private const INSERT_CHUNK = 500;

  /**
   * Constructs the log.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {
  }

  /**
   * Returns the identifier under which the history of an item is kept.
   *
   * The history of an item is looked up by this hash, a small fixed-size index
   * value, instead of by the key, which can be long.
   */
  public static function itemHash(string $definitionId, string $key): string {
    return (string) hex2bin(hash('xxh128', $definitionId . "\0" . $key));
  }

  /**
   * Logs one event.
   *
   * @param int $runId
   *   The run.
   * @param string $definitionId
   *   The import definition.
   * @param \Drupal\import_engine\Storage\EventType $event
   *   What happened.
   * @param string $key
   *   The key of the source item.
   * @param string|null $target
   *   The target entity as "type:id".
   * @param string|null $message
   *   A message, for example the error.
   * @param int|null $now
   *   The time, for tests.
   */
  public function record(int $runId, string $definitionId, EventType $event, string $key, ?string $target = NULL, ?string $message = NULL, ?int $now = NULL): void {
    $this->recordMany([[
      'run_id' => $runId,
      'definition_id' => $definitionId,
      'event' => $event,
      'key' => $key,
      'target' => $target,
      'message' => $message,
    ],
    ], $now);
  }

  /**
   * Logs several events in as few statements as possible.
   *
   * @param list<array{run_id: int, definition_id: string, event: \Drupal\import_engine\Storage\EventType, key: string, target?: string|null, message?: string|null}> $events
   *   The events.
   * @param int|null $now
   *   The time, for tests.
   */
  public function recordMany(array $events, ?int $now = NULL): void {
    $now ??= $this->time->getRequestTime();
    foreach (array_chunk($events, self::INSERT_CHUNK) as $chunk) {
      $insert = $this->database->insert('import_event')->fields([
        'occurred', 'run_id', 'definition_id', 'event', 'item_hash', 'source_key', 'target', 'message',
      ]);
      foreach ($chunk as $event) {
        $insert->values([
          'occurred' => $now,
          'run_id' => $event['run_id'],
          'definition_id' => $event['definition_id'],
          'event' => $event['event']->value,
          'item_hash' => self::itemHash($event['definition_id'], $event['key']),
          'source_key' => $event['key'],
          'target' => $event['target'] ?? NULL,
          'message' => isset($event['message']) ? mb_substr($event['message'], 0, 255) : NULL,
        ]);
      }
      $insert->execute();
    }
  }

  /**
   * Returns what happened to an item, newest first.
   *
   * @return list<\Drupal\import_engine\Storage\EventRecord>
   *   The events.
   */
  public function history(string $definitionId, string $key, int $limit = 100): array {
    $rows = $this->rows($this->database->select('import_event', 'e')
      ->fields('e')
      ->condition('item_hash', self::itemHash($definitionId, $key))
      ->orderBy('occurred', 'DESC')
      ->orderBy('id', 'DESC')
      ->range(0, $limit));
    return array_map($this->hydrate(...), $rows);
  }

  /**
   * Returns the failed and dead events of a run, oldest first.
   *
   * @return list<\Drupal\import_engine\Storage\EventRecord>
   *   The events.
   */
  public function problems(int $runId, int $limit = 20): array {
    $rows = $this->rows($this->database->select('import_event', 'e')
      ->fields('e')
      ->condition('run_id', $runId)
      ->condition('event', [EventType::Failed->value, EventType::Dead->value], 'IN')
      ->orderBy('id')
      ->range(0, $limit));
    return array_map($this->hydrate(...), $rows);
  }

  /**
   * Returns the events of a run, oldest first.
   *
   * @return list<\Drupal\import_engine\Storage\EventRecord>
   *   The events.
   */
  public function forRun(int $runId, int $limit = 100, int $offset = 0): array {
    $rows = $this->rows($this->database->select('import_event', 'e')
      ->fields('e')
      ->condition('run_id', $runId)
      ->orderBy('id')
      ->range($offset, $limit));
    return array_map($this->hydrate(...), $rows);
  }

  /**
   * Counts the events of a run.
   */
  public function countForRun(int $runId): int {
    $query = $this->database->select('import_event', 'e')->condition('run_id', $runId);
    return (int) $this->statement($query->countQuery())->fetchField();
  }

  /**
   * Counts the events of a run by type.
   *
   * @return array<string, int>
   *   The count per event name.
   */
  public function countByEvent(int $runId): array {
    $counts = [];
    foreach (EventType::cases() as $event) {
      $counts[strtolower($event->name)] = 0;
    }
    $query = $this->database->select('import_event', 'e');
    $query->addField('e', 'event');
    $query->addExpression('COUNT(*)', 'total');
    $result = $this->statement($query->condition('run_id', $runId)->groupBy('event'));
    foreach ($result as $row) {
      $counts[strtolower(EventType::from((int) $row->event)->name)] = (int) $row->total;
    }
    return $counts;
  }

  /**
   * Deletes events older than a time, one batch at most.
   *
   * Call it repeatedly until it returns less than the limit.
   *
   * @return int
   *   How many events were deleted.
   */
  public function purge(int $olderThan, int $limit): int {
    $ids = $this->column($this->database->select('import_event', 'e')
      ->fields('e', ['id'])
      ->condition('occurred', $olderThan, '<')
      ->orderBy('occurred')
      ->range(0, $limit));
    if ($ids === []) {
      return 0;
    }
    return (int) $this->database->delete('import_event')
      ->condition('occurred', $olderThan, '<')
      ->condition('id', $ids, 'IN')
      ->execute();
  }

  /**
   * Turns a table row into an event record.
   */
  private function hydrate(\stdClass $row): EventRecord {
    return new EventRecord(
      (int) $row->id,
      (int) $row->occurred,
      (int) $row->run_id,
      (string) $row->definition_id,
      EventType::from((int) $row->event),
      (string) $row->source_key,
      $row->target === NULL ? NULL : (string) $row->target,
      $row->message === NULL ? NULL : (string) $row->message,
    );
  }

}
