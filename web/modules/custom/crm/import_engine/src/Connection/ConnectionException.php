<?php

declare(strict_types=1);

namespace Drupal\import_engine\Connection;

/**
 * An import cannot use its connection, for example because it is gone.
 */
final class ConnectionException extends \RuntimeException {

}
