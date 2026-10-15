<?php

declare(strict_types=1);

namespace Drupal\import_engine\Schedule;

/**
 * What a call of the scheduler did.
 */
final class SchedulerResult {

  /**
   * Constructs the result.
   *
   * @param list<array{kind: string, id: string, status: string, message: string|null}> $outcomes
   *   What was run, in order: a run set or an import, and how it went.
   * @param int $exitCode
   *   Zero when everything that was due completed (or nothing was due), 1 when
   *   something failed, 2 when something is not over yet.
   */
  public function __construct(
    public readonly array $outcomes = [],
    public readonly int $exitCode = 0,
  ) {
  }

}
