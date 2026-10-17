<?php

declare(strict_types=1);

namespace Drupal\import_engine\Validator;

/**
 * What happens to an item when a value of a mapping row is not valid.
 */
enum OnInvalid: string {

  // The item fails and goes to the dead letter queue, with the reason.
  case Fail = 'fail';

  // The field is left empty and the rest of the item is imported.
  case Empty = 'empty';

  // The item is not imported; what is there for it is left as it is.
  case SkipItem = 'skip_item';

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
