<?php

declare(strict_types=1);

namespace Drupal\import_engine;

/**
 * What happens to a target entity whose source item is gone.
 *
 * Applied after a complete run, to entities that were not seen in the source.
 */
enum DeletePolicy: string {

  // Unpublish the entity; the default, because it is reversible.
  case Unpublish = 'unpublish';

  // Delete the entity.
  case Delete = 'delete';

  // Leave the entity as it is.
  case Ignore = 'ignore';

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
