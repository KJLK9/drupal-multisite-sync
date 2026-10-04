<?php

declare(strict_types=1);

namespace Drupal\import_engine\Drive;

use Drupal\import_engine\Run\RunStatus;

/**
 * What a call of the run driver did.
 */
final class DriveResult {

  /**
   * Exit code: the run completed without problems.
   */
  public const EXIT_OK = 0;

  /**
   * Exit code: the run is over but failed or had errors.
   */
  public const EXIT_FAILED = 1;

  /**
   * Exit code: the run is not over; call again.
   */
  public const EXIT_UNFINISHED = 2;

  /**
   * Constructs the result.
   *
   * @param \Drupal\import_engine\Drive\DriveStatus $status
   *   How the call ended.
   * @param \Drupal\import_engine\Run\RunStatus $runStatus
   *   The status of the run after the call.
   * @param int $pages
   *   Pages read in this call.
   * @param int $items
   *   Items handled in this call.
   * @param string|null $message
   *   More about why the call ended, when that is not obvious.
   */
  public function __construct(
    public readonly DriveStatus $status,
    public readonly RunStatus $runStatus,
    public readonly int $pages = 0,
    public readonly int $items = 0,
    public readonly ?string $message = NULL,
  ) {
  }

  /**
   * Returns the exit code for a command line caller.
   */
  public function exitCode(): int {
    if ($this->status !== DriveStatus::Finished) {
      return self::EXIT_UNFINISHED;
    }
    return $this->runStatus === RunStatus::Completed ? self::EXIT_OK : self::EXIT_FAILED;
  }

}
