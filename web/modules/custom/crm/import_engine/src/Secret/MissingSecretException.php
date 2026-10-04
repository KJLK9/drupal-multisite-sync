<?php

declare(strict_types=1);

namespace Drupal\import_engine\Secret;

/**
 * A secret that a definition refers to is not set in the environment.
 */
final class MissingSecretException extends \RuntimeException {

}
