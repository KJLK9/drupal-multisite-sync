<?php

declare(strict_types=1);

namespace Drupal\import_engine\Storage;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Condition;

/**
 * The work queue: items of a run, claimed and handled by workers.
 *
 * Items are plain table rows, not entities: a run can hold millions of them
 * and they are written, claimed and purged in bulk.
 *
 * Claiming follows the pattern of Drupal's database queue: select candidates
 * on an index, then update them under the same conditions with a unique claim
 * token and a lease. Workers cannot take the same item, a worker that dies
 * loses its lease and the item comes back, and a late worker cannot finish an
 * item that was claimed again, because every change checks the claim token.
 */
final class ItemStorage {

  use QueryHelpers;

  /**
   * How many rows go in one INSERT statement.
   */
  private const INSERT_CHUNK = 500;

  /**
   * Constructs the storage.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly PayloadCodec $codec,
    private readonly TimeInterface $time,
  ) {
  }

  /**
   * Adds items to the queue of a run.
   *
   * An item whose key is already in the run (an overlap between pages) is not
   * added again.
   *
   * @param int $runId
   *   The run.
   * @param string $pool
   *   The worker pool that will process the items.
   * @param list<array{key: string, payload: array<mixed>}> $items
   *   The items, each with its key and its decoded source item.
   * @param int $priority
   *   Higher is claimed first.
   * @param int|null $now
   *   The time, for tests.
   * @param int $page
   *   The position of the page the items came from, counting from 0.
   *
   * @return array{inserted: int, duplicates: int}
   *   How many items were added and how many were already in the run.
   */
  public function enqueue(int $runId, string $pool, array $items, int $priority = 0, ?int $now = NULL, int $page = 0): array {
    $now ??= $this->time->getRequestTime();
    $duplicates = 0;
    $new = [];
    foreach ($items as $item) {
      $key = (string) $item['key'];
      if (isset($new[$key])) {
        $duplicates++;
        continue;
      }
      $new[$key] = $item['payload'];
    }
    if ($new === []) {
      return ['inserted' => 0, 'duplicates' => $duplicates];
    }

    $existing = $this->column($this->database->select('import_item', 'i')
      ->fields('i', ['source_key'])
      ->condition('run_id', $runId)
      ->condition('source_key', array_map('strval', array_keys($new)), 'IN'));
    foreach ($existing as $key) {
      unset($new[(string) $key]);
      $duplicates++;
    }

    $inserted = 0;
    foreach (array_chunk($new, self::INSERT_CHUNK, TRUE) as $chunk) {
      $insert = $this->database->insert('import_item')->fields([
        'run_id', 'page', 'pool', 'source_key', 'state', 'priority', 'payload', 'created', 'changed',
      ]);
      foreach ($chunk as $key => $payload) {
        $insert->values([
          'run_id' => $runId,
          'page' => $page,
          'pool' => $pool,
          'source_key' => (string) $key,
          'state' => ItemState::Pending->value,
          'priority' => $priority,
          'payload' => $this->codec->encode($payload),
          'created' => $now,
          'changed' => $now,
        ]);
        $inserted++;
      }
      $insert->execute();
    }
    return ['inserted' => $inserted, 'duplicates' => $duplicates];
  }

  /**
   * Claims items for a worker.
   *
   * Takes pending items, retrying items that are due, and items whose lease
   * ran out because their worker died.
   *
   * @param string $worker
   *   The name of the worker: letters, digits, dots, dashes and underscores.
   * @param int $limit
   *   The most items to claim.
   * @param int $leaseSeconds
   *   How long the worker may hold the items before they come back.
   * @param string $pool
   *   Only items of this worker pool are claimed.
   * @param int|null $now
   *   The time, for tests.
   *
   * @return list<\Drupal\import_engine\Storage\ImportItem>
   *   The claimed items; fewer than the limit when others were quicker.
   */
  public function claim(string $worker, int $limit, int $leaseSeconds, string $pool = 'default', ?int $now = NULL): array {
    if (!preg_match('/^[A-Za-z0-9_.-]{1,40}$/', $worker)) {
      throw new \InvalidArgumentException('A worker name is up to 40 letters, digits, dots, dashes and underscores.');
    }
    $now ??= $this->time->getRequestTime();

    $ids = $this->column($this->database->select('import_item', 'i')
      ->fields('i', ['id'])
      ->condition('pool', $pool)
      ->condition($this->claimable($now))
      ->orderBy('priority', 'DESC')
      ->orderBy('id', 'ASC')
      ->range(0, $limit));
    if ($ids === []) {
      return [];
    }

    $token = $worker . ':' . bin2hex(random_bytes(6));
    $this->database->update('import_item')
      ->fields([
        'state' => ItemState::Processing->value,
        'claimed_by' => $token,
        'claimed_until' => $now + $leaseSeconds,
        'changed' => $now,
      ])
      ->expression('attempts', 'attempts + 1')
      ->condition('id', $ids, 'IN')
      ->condition($this->claimable($now))
      ->execute();

    $rows = $this->rows($this->database->select('import_item', 'i')
      ->fields('i')
      ->condition('claimed_by', $token)
      ->condition('state', ItemState::Processing->value)
      ->orderBy('priority', 'DESC')
      ->orderBy('id', 'ASC'));
    return array_map($this->hydrate(...), $rows);
  }

  /**
   * Marks a claimed item as handled; its payload is dropped.
   *
   * @param \Drupal\import_engine\Storage\ImportItem $item
   *   The claimed item.
   * @param \Drupal\import_engine\Storage\Outcome $outcome
   *   What handling it resulted in.
   * @param string|null $hash
   *   The hash of the mapped data in hex, if there is one.
   * @param int|null $now
   *   The time, for tests.
   *
   * @return bool
   *   FALSE when the claim was lost (the lease ran out and the item was
   *   claimed again); nothing was changed then.
   */
  public function complete(ImportItem $item, Outcome $outcome, ?string $hash = NULL, ?int $now = NULL): bool {
    if ($hash !== NULL && !preg_match('/^[0-9a-f]{32}$/', $hash)) {
      throw new \InvalidArgumentException('A hash is 32 hex characters.');
    }
    return $this->release($item, [
      'state' => ItemState::Done->value,
      'outcome' => $outcome->value,
      'hash' => $hash === NULL ? NULL : (string) hex2bin($hash),
      'payload' => NULL,
      'error' => NULL,
    ], $now);
  }

  /**
   * Puts a claimed item back to be tried again later.
   *
   * @return bool
   *   FALSE when the claim was lost.
   */
  public function retry(ImportItem $item, string $error, int $nextAttempt, ?int $now = NULL): bool {
    return $this->release($item, [
      'state' => ItemState::Retrying->value,
      'next_attempt' => $nextAttempt,
      'error' => mb_substr($error, 0, 255),
    ], $now);
  }

  /**
   * Gives up on a claimed item.
   *
   * With the dead letter queue the item stays, with its payload, so that it
   * can be edited and tried again. Without it the item is done as failed and
   * its payload is dropped.
   *
   * @return bool
   *   FALSE when the claim was lost.
   */
  public function fail(ImportItem $item, string $error, bool $deadLetter, ?int $now = NULL): bool {
    $fields = ['error' => mb_substr($error, 0, 255)];
    if ($deadLetter) {
      return $this->release($item, ['state' => ItemState::Dead->value] + $fields, $now);
    }
    return $this->release($item, [
      'state' => ItemState::Done->value,
      'outcome' => Outcome::Failed->value,
      'payload' => NULL,
    ] + $fields, $now);
  }

  /**
   * Puts a claimed item back without counting the attempt.
   *
   * For an item that could not run because something it depends on is down
   * (an open circuit breaker): that is not the item's fault.
   *
   * @return bool
   *   FALSE when the claim was lost.
   */
  public function defer(ImportItem $item, int $nextAttempt, ?int $now = NULL): bool {
    return $this->release($item, [
      'state' => ItemState::Retrying->value,
      'next_attempt' => $nextAttempt,
    ], $now, TRUE);
  }

  /**
   * Makes items that are retrying or dead pending again, with fresh attempts.
   *
   * @param list<int> $ids
   *   The item IDs.
   *
   * @return int
   *   How many items were requeued.
   */
  public function requeue(array $ids): int {
    if ($ids === []) {
      return 0;
    }
    return (int) $this->database->update('import_item')
      ->fields([
        'state' => ItemState::Pending->value,
        'attempts' => 0,
        'next_attempt' => 0,
        'error' => NULL,
        'claimed_by' => NULL,
        'claimed_until' => 0,
        'changed' => $this->time->getRequestTime(),
      ])
      ->condition('id', $ids, 'IN')
      ->condition('state', [ItemState::Dead->value, ItemState::Retrying->value], 'IN')
      ->execute();
  }

  /**
   * Deletes dead items.
   *
   * @param list<int> $ids
   *   The item IDs.
   *
   * @return int
   *   How many items were deleted.
   */
  public function discard(array $ids): int {
    if ($ids === []) {
      return 0;
    }
    return (int) $this->database->delete('import_item')
      ->condition('id', $ids, 'IN')
      ->condition('state', ItemState::Dead->value)
      ->execute();
  }

  /**
   * Replaces the payload of an item that is not being processed or done.
   *
   * @param int $id
   *   The item ID.
   * @param array<mixed> $payload
   *   The new decoded source item.
   *
   * @return bool
   *   Whether the payload was changed.
   */
  public function updatePayload(int $id, array $payload): bool {
    return (bool) $this->database->update('import_item')
      ->fields(['payload' => $this->codec->encode($payload), 'changed' => $this->time->getRequestTime()])
      ->condition('id', $id)
      ->condition('state', [ItemState::Pending->value, ItemState::Retrying->value, ItemState::Dead->value], 'IN')
      ->execute();
  }

  /**
   * Loads an item.
   */
  public function find(int $id): ?ImportItem {
    $rows = $this->rows($this->database->select('import_item', 'i')->fields('i')->condition('id', $id));
    return $rows === [] ? NULL : $this->hydrate($rows[0]);
  }

  /**
   * Returns the positions of the pages that had items that did not go well.
   *
   * These are pages with a dead item or an item that ended as failed. A page
   * with none of them was handled completely.
   *
   * @return list<int>
   *   The page positions, in order.
   */
  public function pagesWithFailures(int $runId): array {
    $pages = [];
    $dead = $this->database->select('import_item', 'i')
      ->fields('i', ['page'])
      ->distinct()
      ->condition('run_id', $runId)
      ->condition('state', ItemState::Dead->value);
    $failed = $this->database->select('import_item', 'i')
      ->fields('i', ['page'])
      ->distinct()
      ->condition('run_id', $runId)
      ->condition('state', ItemState::Done->value)
      ->condition('outcome', Outcome::Failed->value);
    foreach ([$dead, $failed] as $query) {
      foreach ($this->column($query) as $page) {
        $pages[(int) $page] = (int) $page;
      }
    }
    sort($pages);
    return $pages;
  }

  /**
   * Counts the items of a run by state.
   *
   * @return array<string, int>
   *   The count per state name (pending, processing, done, retrying, dead).
   */
  public function countByState(int $runId): array {
    $counts = [];
    foreach (ItemState::cases() as $state) {
      $counts[strtolower($state->name)] = 0;
    }
    $query = $this->database->select('import_item', 'i');
    $query->addField('i', 'state');
    $query->addExpression('COUNT(*)', 'total');
    $result = $this->statement($query->condition('run_id', $runId)->groupBy('state'));
    foreach ($result as $row) {
      $counts[strtolower(ItemState::from((int) $row->state)->name)] = (int) $row->total;
    }
    return $counts;
  }

  /**
   * Counts the handled items of a run by outcome.
   *
   * @return array<string, int>
   *   The count per outcome name (created, updated, unchanged, skipped,
   *   failed).
   */
  public function countByOutcome(int $runId): array {
    $counts = [];
    foreach (Outcome::cases() as $outcome) {
      $counts[strtolower($outcome->name)] = 0;
    }
    $query = $this->database->select('import_item', 'i');
    $query->addField('i', 'outcome');
    $query->addExpression('COUNT(*)', 'total');
    $result = $this->statement($query->condition('run_id', $runId)->isNotNull('outcome')->groupBy('outcome'));
    foreach ($result as $row) {
      $counts[strtolower(Outcome::from((int) $row->outcome)->name)] = (int) $row->total;
    }
    return $counts;
  }

  /**
   * Deletes items in a state that were last changed before a time.
   *
   * Call it repeatedly until it returns less than the limit; one call deletes
   * at most a batch, so a purge never holds a table for long.
   *
   * @param \Drupal\import_engine\Storage\ItemState $state
   *   The state, normally Done or Dead.
   * @param int $olderThan
   *   Delete items last changed before this timestamp.
   * @param int $limit
   *   The most items to delete.
   *
   * @return int
   *   How many items were deleted.
   */
  public function purge(ItemState $state, int $olderThan, int $limit): int {
    $ids = $this->column($this->database->select('import_item', 'i')
      ->fields('i', ['id'])
      ->condition('state', $state->value)
      ->condition('changed', $olderThan, '<')
      ->range(0, $limit));
    if ($ids === []) {
      return 0;
    }
    return (int) $this->database->delete('import_item')->condition('id', $ids, 'IN')->execute();
  }

  /**
   * Builds the condition that selects items a worker may claim now.
   */
  private function claimable(int $now): Condition {
    return $this->database->condition('OR')
      ->condition('state', ItemState::Pending->value)
      ->condition($this->database->condition('AND')
        ->condition('state', ItemState::Retrying->value)
        ->condition('next_attempt', $now, '<='))
      ->condition($this->database->condition('AND')
        ->condition('state', ItemState::Processing->value)
        ->condition('claimed_until', $now, '<'));
  }

  /**
   * Ends a claim: changes the item, but only if the claim is still ours.
   *
   * @param \Drupal\import_engine\Storage\ImportItem $item
   *   The claimed item.
   * @param array<string, mixed> $fields
   *   The fields to set.
   * @param int|null $now
   *   The time, for tests.
   * @param bool $giveBackAttempt
   *   Whether the attempt made by the claim is not counted.
   *
   * @return bool
   *   Whether the claim was still held.
   */
  private function release(ImportItem $item, array $fields, ?int $now, bool $giveBackAttempt = FALSE): bool {
    $update = $this->database->update('import_item')
      ->fields($fields + [
        'claimed_by' => NULL,
        'claimed_until' => 0,
        'changed' => $now ?? $this->time->getRequestTime(),
      ])
      ->condition('id', $item->id)
      ->condition('state', ItemState::Processing->value)
      ->condition('claimed_by', (string) $item->claimToken);
    if ($giveBackAttempt) {
      $update->expression('attempts', 'attempts - 1');
    }
    return $update->execute() === 1;
  }

  /**
   * Turns a table row into an item.
   */
  private function hydrate(\stdClass $row): ImportItem {
    return new ImportItem(
      (int) $row->id,
      (int) $row->run_id,
      (string) $row->pool,
      (string) $row->source_key,
      ItemState::from((int) $row->state),
      $row->outcome === NULL ? NULL : Outcome::from((int) $row->outcome),
      (int) $row->attempts,
      (int) $row->priority,
      (int) $row->next_attempt,
      $row->claimed_by === NULL ? NULL : (string) $row->claimed_by,
      $row->hash === NULL ? NULL : bin2hex((string) $row->hash),
      $row->error === NULL ? NULL : (string) $row->error,
      $row->payload === NULL ? NULL : $this->codec->decode((string) $row->payload),
    );
  }

}
