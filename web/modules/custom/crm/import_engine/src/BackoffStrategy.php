<?php

declare(strict_types=1);

namespace Drupal\import_engine;

/**
 * How the delay between retries of a failed item grows.
 */
enum BackoffStrategy: string {

  // The same delay every time.
  case Fixed = 'fixed';

  // The delay grows by the same step every time.
  case Linear = 'linear';

  // The delay doubles every time.
  case Exponential = 'exponential';

  /**
   * Returns all values, for use as a config schema Choice callback.
   *
   * @return string[]
   *   The backing values of all cases.
   */
  public static function values(): array {
    return array_column(self::cases(), 'value');
  }

}
