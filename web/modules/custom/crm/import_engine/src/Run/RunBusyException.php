<?php

declare(strict_types=1);

namespace Drupal\import_engine\Run;

/**
 * Another process is busy with the run, so it cannot be changed now.
 */
final class RunBusyException extends \RuntimeException {

}
