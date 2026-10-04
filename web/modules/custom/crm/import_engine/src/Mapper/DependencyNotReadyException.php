<?php

declare(strict_types=1);

namespace Drupal\import_engine\Mapper;

/**
 * Something the item refers to has not been imported yet.
 *
 * This is not the fault of the item: the other import may simply run later, so
 * the item is retried.
 */
final class DependencyNotReadyException extends \RuntimeException {

}
