<?php

declare(strict_types=1);

namespace Drupal\import_engine\Finish;

use Drupal\import_engine\Run\RunStatus;

/**
 * What a call of the finish stage did.
 */
final class FinishResult {

  /**
   * Constructs the result.
   *
   * @param \Drupal\import_engine\Finish\FinishStatus $status
   *   How the call ended.
   * @param \Drupal\import_engine\Run\RunStatus|null $runStatus
   *   The final status of the run, when it was finished.
   * @param int $swept
   *   Items the sweep unpublished or deleted.
   * @param int $verified
   *   Pages that were verified.
   * @param int $reported
   *   Reporters that were told.
   */
  public function __construct(
    public readonly FinishStatus $status,
    public readonly ?RunStatus $runStatus = NULL,
    public readonly int $swept = 0,
    public readonly int $verified = 0,
    public readonly int $reported = 0,
  ) {
  }

}
