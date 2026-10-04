<?php

declare(strict_types=1);

namespace Drupal\import_engine\Storage;

/**
 * A row of the work queue.
 */
final class ImportItem {

  /**
   * Constructs an item.
   *
   * @param int $id
   *   The row ID.
   * @param int $runId
   *   The run the item belongs to.
   * @param string $pool
   *   The worker pool that processes the item.
   * @param string $key
   *   The key that identifies the item in its source.
   * @param \Drupal\import_engine\Storage\ItemState $state
   *   Where the item is in its life.
   * @param \Drupal\import_engine\Storage\Outcome|null $outcome
   *   What handling the item resulted in, once handled.
   * @param int $attempts
   *   How many times a worker started on it.
   * @param int $priority
   *   Higher is claimed first.
   * @param int $nextAttempt
   *   When a retrying item may be claimed again, as a timestamp.
   * @param string|null $claimToken
   *   The token of the claim that holds the item, while it is processing.
   * @param string|null $hash
   *   The hash of the mapped data in hex, once handled.
   * @param string|null $error
   *   The last error.
   * @param array<mixed>|null $payload
   *   The source item, until the item is done.
   */
  public function __construct(
    public readonly int $id,
    public readonly int $runId,
    public readonly string $pool,
    public readonly string $key,
    public readonly ItemState $state,
    public readonly ?Outcome $outcome,
    public readonly int $attempts,
    public readonly int $priority,
    public readonly int $nextAttempt,
    public readonly ?string $claimToken,
    public readonly ?string $hash,
    public readonly ?string $error,
    public readonly ?array $payload,
  ) {
  }

}
