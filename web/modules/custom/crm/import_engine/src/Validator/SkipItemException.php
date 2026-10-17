<?php

declare(strict_types=1);

namespace Drupal\import_engine\Validator;

/**
 * An item is not imported because a value of it is not valid.
 *
 * Not a failure: the mapping row said that such an item is to be left out.
 */
final class SkipItemException extends \RuntimeException {

}
