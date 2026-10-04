<?php

declare(strict_types=1);

namespace Drupal\import_engine\Finish;

/**
 * What the sweep of a run did.
 */
final class SweepResult {

  /**
   * Constructs the result.
   *
   * @param int $swept
   *   Items whose target was unpublished or deleted.
   * @param int $failed
   *   Items whose target could not be handled; they are tried again next run.
   * @param string|null $message
   *   Why the sweep did less than it should, if it did.
   * @param bool $problem
   *   Whether the message is a problem that makes the run end with errors.
   */
  public function __construct(
    public readonly int $swept = 0,
    public readonly int $failed = 0,
    public readonly ?string $message = NULL,
    public readonly bool $problem = FALSE,
  ) {
  }

}
