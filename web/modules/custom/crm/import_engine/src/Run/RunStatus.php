<?php

declare(strict_types=1);

namespace Drupal\import_engine\Run;

/**
 * The status of a run.
 *
 * A run is queued, then extracts pages while workers process the items; once
 * extraction is complete it only drains the items, and when all items are done
 * it finishes (sweep and reports). It ends as completed, completed with errors,
 * failed or cancelled; those four are final.
 */
enum RunStatus: string {

  case Queued = 'queued';
  case Extracting = 'extracting';
  case Processing = 'processing';
  case Finishing = 'finishing';
  case Completed = 'completed';
  case CompletedWithErrors = 'completed_with_errors';
  case Failed = 'failed';
  case Cancelled = 'cancelled';

  /**
   * Returns all values, for use as a Choice callback.
   *
   * @return string[]
   *   The backing values of all cases.
   */
  public static function values(): array {
    return array_column(self::cases(), 'value');
  }

  /**
   * Returns whether the run is over.
   */
  public function isFinal(): bool {
    return in_array($this, [self::Completed, self::CompletedWithErrors, self::Failed, self::Cancelled], TRUE);
  }

  /**
   * Returns the statuses this status may move to.
   *
   * @return list<\Drupal\import_engine\Run\RunStatus>
   *   The allowed next statuses; none for a final status.
   */
  public function next(): array {
    return match ($this) {
      self::Queued => [self::Extracting, self::Failed, self::Cancelled],
      self::Extracting => [self::Processing, self::Failed, self::Cancelled],
      self::Processing => [self::Finishing, self::Failed, self::Cancelled],
      self::Finishing => [self::Completed, self::CompletedWithErrors, self::Failed, self::Cancelled],
      default => [],
    };
  }

  /**
   * Returns whether a run may move from this status to another.
   */
  public function canMoveTo(self $to): bool {
    return in_array($to, $this->next(), TRUE);
  }

}
