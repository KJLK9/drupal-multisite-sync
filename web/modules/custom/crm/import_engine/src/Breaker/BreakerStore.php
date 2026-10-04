<?php

declare(strict_types=1);

namespace Drupal\import_engine\Breaker;

use Drupal\Core\Database\Connection;
use Drupal\import_engine\Storage\QueryHelpers;

/**
 * Keeps the circuit breakers, one row per server.
 *
 * Every change is a single conditional UPDATE, so workers in different
 * processes never need a lock and never overwrite each other: whoever wins the
 * UPDATE changes the state, the others see that it was already changed.
 */
final class BreakerStore {

  use QueryHelpers;

  /**
   * The longest cooldown, in seconds, however often the breaker opened.
   */
  public const MAX_COOLDOWN = 900;

  /**
   * How long a process may hold the probe, in seconds.
   */
  public const PROBE_LEASE = 60;

  /**
   * Constructs the store.
   */
  public function __construct(
    private readonly Connection $database,
  ) {
  }

  /**
   * Returns the breaker of a server; a closed one when it has none yet.
   */
  public function get(string $endpoint): BreakerRecord {
    $rows = $this->rows($this->database->select('import_breaker', 'b')->fields('b')->condition('endpoint', $endpoint));
    return $rows === [] ? new BreakerRecord($endpoint) : $this->hydrate($rows[0]);
  }

  /**
   * Returns all breakers, by server.
   *
   * @return list<\Drupal\import_engine\Breaker\BreakerRecord>
   *   The records.
   */
  public function all(): array {
    $rows = $this->rows($this->database->select('import_breaker', 'b')->fields('b')->orderBy('endpoint'));
    return array_map($this->hydrate(...), $rows);
  }

  /**
   * Counts a transient failure and opens the breaker when it is too many.
   *
   * A failure while half open opens it again at once, for longer.
   *
   * @param string $endpoint
   *   The server.
   * @param int $threshold
   *   The failures in a row that open the breaker.
   * @param int $cooldown
   *   The base cooldown in seconds.
   * @param int $now
   *   The current time.
   *
   * @return bool
   *   Whether this call opened the breaker.
   */
  public function recordFailure(string $endpoint, int $threshold, int $cooldown, int $now): bool {
    $this->ensure($endpoint, $now);
    $this->database->update('import_breaker')
      ->expression('failures', 'failures + 1')
      ->fields(['changed' => $now])
      ->condition('endpoint', $endpoint)
      ->execute();
    $record = $this->get($endpoint);

    if ($record->state === BreakerState::Closed && $record->failures >= $threshold) {
      return $this->open($endpoint, BreakerState::Closed, 1, $cooldown, $now);
    }
    if ($record->state === BreakerState::HalfOpen) {
      return $this->open($endpoint, BreakerState::HalfOpen, $record->trips + 1, $cooldown, $now);
    }
    return FALSE;
  }

  /**
   * Counts a success: failures start over, and a half open breaker closes.
   *
   * @return bool
   *   Whether this call closed the breaker.
   */
  public function recordSuccess(string $endpoint, int $now): bool {
    $closed = $this->database->update('import_breaker')
      ->fields([
        'state' => BreakerState::Closed->value,
        'failures' => 0,
        'trips' => 0,
        'probe_until' => 0,
        'changed' => $now,
      ])
      ->condition('endpoint', $endpoint)
      ->condition('state', BreakerState::HalfOpen->value)
      ->execute();
    $this->database->update('import_breaker')
      ->fields(['failures' => 0, 'changed' => $now])
      ->condition('endpoint', $endpoint)
      ->condition('state', BreakerState::Closed->value)
      ->condition('failures', 0, '>')
      ->execute();
    return $closed === 1;
  }

  /**
   * Claims the right to probe an open breaker.
   *
   * @return bool
   *   TRUE for exactly one process once the probe is due; the others wait.
   */
  public function claimProbe(string $endpoint, int $now): bool {
    return $this->database->update('import_breaker')
      ->fields(['probe_until' => $now + self::PROBE_LEASE])
      ->condition('endpoint', $endpoint)
      ->condition('state', BreakerState::Open->value)
      ->condition('manual', 0)
      ->condition('next_probe', $now, '<=')
      ->condition('probe_until', $now, '<')
      ->execute() === 1;
  }

  /**
   * Records that the probe found the server back: calls are allowed again.
   */
  public function probeSucceeded(string $endpoint, int $now): void {
    $this->database->update('import_breaker')
      ->fields(['state' => BreakerState::HalfOpen->value, 'probe_until' => 0, 'changed' => $now])
      ->condition('endpoint', $endpoint)
      ->condition('state', BreakerState::Open->value)
      ->execute();
  }

  /**
   * Records that the probe found the server still down: wait longer.
   */
  public function probeFailed(string $endpoint, int $cooldown, int $now): void {
    $record = $this->get($endpoint);
    $trips = $record->trips + 1;
    $this->database->update('import_breaker')
      ->fields([
        'trips' => $trips,
        'next_probe' => $now + self::cooldown($cooldown, $trips),
        'probe_until' => 0,
        'changed' => $now,
      ])
      ->condition('endpoint', $endpoint)
      ->condition('state', BreakerState::Open->value)
      ->execute();
  }

  /**
   * Opens a breaker by hand; only a reset closes it again.
   */
  public function trip(string $endpoint, int $now): void {
    $this->ensure($endpoint, $now);
    $this->database->update('import_breaker')
      ->fields([
        'state' => BreakerState::Open->value,
        'manual' => 1,
        'opened' => $now,
        'probe_until' => 0,
        'changed' => $now,
      ])
      ->condition('endpoint', $endpoint)
      ->execute();
  }

  /**
   * Closes a breaker, whatever its state, and forgets its failures.
   *
   * @return bool
   *   Whether there was a breaker that was not closed already.
   */
  public function reset(string $endpoint, int $now): bool {
    $record = $this->get($endpoint);
    $this->database->update('import_breaker')
      ->fields([
        'state' => BreakerState::Closed->value,
        'failures' => 0,
        'trips' => 0,
        'manual' => 0,
        'probe_until' => 0,
        'changed' => $now,
      ])
      ->condition('endpoint', $endpoint)
      ->execute();
    return $record->state !== BreakerState::Closed;
  }

  /**
   * Returns the cooldown after a number of trips in a row.
   *
   * It doubles each time, up to MAX_COOLDOWN.
   */
  public static function cooldown(int $base, int $trips): int {
    return min($base * (2 ** min(max($trips, 1) - 1, 20)), self::MAX_COOLDOWN);
  }

  /**
   * Opens a breaker that is in the given state, if it still is.
   */
  private function open(string $endpoint, BreakerState $from, int $trips, int $cooldown, int $now): bool {
    return $this->database->update('import_breaker')
      ->fields([
        'state' => BreakerState::Open->value,
        'trips' => $trips,
        'opened' => $now,
        'next_probe' => $now + self::cooldown($cooldown, $trips),
        'probe_until' => 0,
        'changed' => $now,
      ])
      ->condition('endpoint', $endpoint)
      ->condition('state', $from->value)
      ->execute() === 1;
  }

  /**
   * Makes sure a row exists for the server.
   */
  private function ensure(string $endpoint, int $now): void {
    $this->database->merge('import_breaker')
      ->keys(['endpoint' => $endpoint])
      ->insertFields(['endpoint' => $endpoint, 'changed' => $now])
      ->execute();
  }

  /**
   * Turns a table row into a record.
   */
  private function hydrate(\stdClass $row): BreakerRecord {
    return new BreakerRecord(
      (string) $row->endpoint,
      BreakerState::from((int) $row->state),
      (int) $row->failures,
      (int) $row->trips,
      (int) $row->opened,
      (int) $row->next_probe,
      (int) $row->probe_until,
      (bool) $row->manual,
      (int) $row->changed,
    );
  }

}
