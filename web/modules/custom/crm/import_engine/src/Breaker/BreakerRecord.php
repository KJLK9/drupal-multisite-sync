<?php

declare(strict_types=1);

namespace Drupal\import_engine\Breaker;

/**
 * The circuit breaker of one server, as stored.
 */
final class BreakerRecord {

  /**
   * Constructs the record.
   *
   * @param string $endpoint
   *   The server.
   * @param \Drupal\import_engine\Breaker\BreakerState $state
   *   The state.
   * @param int $failures
   *   The transient failures in a row.
   * @param int $trips
   *   How many times in a row the breaker opened.
   * @param int $opened
   *   When it was opened last.
   * @param int $nextProbe
   *   From when a probe may check whether the server is back.
   * @param int $probeUntil
   *   Until when a probe is claimed by a process.
   * @param bool $manual
   *   Whether it was opened by hand, so only a reset closes it.
   * @param int $changed
   *   When the record last changed.
   */
  public function __construct(
    public readonly string $endpoint,
    public readonly BreakerState $state = BreakerState::Closed,
    public readonly int $failures = 0,
    public readonly int $trips = 0,
    public readonly int $opened = 0,
    public readonly int $nextProbe = 0,
    public readonly int $probeUntil = 0,
    public readonly bool $manual = FALSE,
    public readonly int $changed = 0,
  ) {
  }

}
