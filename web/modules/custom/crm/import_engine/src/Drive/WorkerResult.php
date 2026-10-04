<?php

declare(strict_types=1);

namespace Drupal\import_engine\Drive;

/**
 * What a worker did before it stopped.
 */
final class WorkerResult {

  /**
   * The queue was empty and the worker was told to stop then.
   */
  public const IDLE = 'idle';

  /**
   * The budget ran out or a stop was requested.
   */
  public const BUDGET = 'budget';

  /**
   * Constructs the result.
   *
   * @param int $items
   *   The items handled.
   * @param int $finished
   *   The runs that were finished.
   * @param string $reason
   *   Why the worker stopped: idle or budget.
   */
  public function __construct(
    public readonly int $items,
    public readonly int $finished,
    public readonly string $reason,
  ) {
  }

}
