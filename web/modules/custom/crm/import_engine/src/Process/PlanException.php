<?php

declare(strict_types=1);

namespace Drupal\import_engine\Process;

/**
 * The definition cannot be turned into a working plan.
 *
 * For example it maps a field the target does not have. Retrying will not help
 * until the definition is changed.
 */
final class PlanException extends \RuntimeException {

}
