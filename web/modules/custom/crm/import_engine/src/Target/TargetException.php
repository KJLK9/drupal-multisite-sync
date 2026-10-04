<?php

declare(strict_types=1);

namespace Drupal\import_engine\Target;

/**
 * Writing an item to the target failed for a reason that retrying will not fix.
 *
 * For example the data does not pass the validation of the target. The message
 * is meant for the people who look at the dead letter queue.
 */
class TargetException extends \RuntimeException {

}
