<?php

declare(strict_types=1);

namespace Drupal\import_engine\Mapper;

/**
 * A source value cannot be turned into the value of the target field.
 *
 * Retrying will not help: the source data is wrong, or the mapping is.
 */
final class MappingException extends \RuntimeException {

}
