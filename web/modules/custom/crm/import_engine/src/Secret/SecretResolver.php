<?php

declare(strict_types=1);

namespace Drupal\import_engine\Secret;

/**
 * Reads secrets from the environment by variable name.
 *
 * Definitions only ever store the name of the variable, never the secret, so
 * config exports and git stay free of credentials. The project's .env is
 * loaded into the environment by config/settings.env.php.
 */
final class SecretResolver {

  /**
   * Returns the value of an environment variable.
   *
   * @throws \Drupal\import_engine\Secret\MissingSecretException
   *   When the variable is not set or empty. The message names the variable
   *   and never contains a value.
   */
  public function get(string $name): string {
    $value = getenv($name);
    if ($value === FALSE || $value === '') {
      throw new MissingSecretException(sprintf('The environment variable %s is not set.', $name));
    }
    return $value;
  }

}
