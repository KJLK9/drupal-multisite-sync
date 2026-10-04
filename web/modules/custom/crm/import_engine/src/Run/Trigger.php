<?php

declare(strict_types=1);

namespace Drupal\import_engine\Run;

/**
 * What started a run.
 */
enum Trigger: string {

  case Cron = 'cron';
  case Drush = 'drush';
  case Ui = 'ui';

  /**
   * Returns all values, for use as a Choice callback.
   *
   * @return string[]
   *   The backing values of all cases.
   */
  public static function values(): array {
    return array_column(self::cases(), 'value');
  }

}
