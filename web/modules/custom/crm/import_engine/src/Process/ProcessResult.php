<?php

declare(strict_types=1);

namespace Drupal\import_engine\Process;

/**
 * What one call of the process stage did.
 */
final class ProcessResult {

  /**
   * Constructs a result.
   *
   * @param int $claimed
   *   The items the call claimed.
   * @param int $created
   *   Items that created a new entity.
   * @param int $updated
   *   Items that changed an existing entity.
   * @param int $unchanged
   *   Items whose entity needed no change.
   * @param int $retried
   *   Items put back to be tried again later.
   * @param int $failed
   *   Items that failed for good: dead, or ended as failed.
   * @param int $lost
   *   Items whose claim ran out before the worker was done with them.
   * @param list<int> $runIds
   *   The runs the items belong to.
   */
  public function __construct(
    public readonly int $claimed = 0,
    public readonly int $created = 0,
    public readonly int $updated = 0,
    public readonly int $unchanged = 0,
    public readonly int $retried = 0,
    public readonly int $failed = 0,
    public readonly int $lost = 0,
    public readonly array $runIds = [],
  ) {
  }

}
