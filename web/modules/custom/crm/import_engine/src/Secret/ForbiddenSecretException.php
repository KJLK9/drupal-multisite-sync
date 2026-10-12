<?php

declare(strict_types=1);

namespace Drupal\import_engine\Secret;

/**
 * A definition refers to an environment variable that may not be used as a key.
 */
final class ForbiddenSecretException extends \RuntimeException {

}
