<?php

declare(strict_types=1);

namespace Drupal\import_engine\Secret;

use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Reads secrets from the environment by variable name.
 *
 * Definitions only ever store the name of the variable, never the secret, so
 * config exports and git stay free of credentials. The project's .env is
 * loaded into the environment by config/settings.env.php.
 *
 * Not every variable may be named: whoever can edit a definition chooses the
 * name and the address the key is sent to, so a name like SITE_B_DB_PASSWORD
 * would send the database password to any host. Only names that say they hold a
 * key for an API (ending in _API_KEY, _TOKEN, _SECRET or _KEY) are allowed, and
 * never one that says it is about the database, a password or a salt. See
 * ADR 0024.
 */
final class SecretResolver {

  /**
   * What a name must end in.
   */
  private const ALLOWED = '/^[A-Z][A-Z0-9_]*_(API_KEY|TOKEN|SECRET|KEY)$/';

  /**
   * What a name must not contain, as a word between underscores.
   */
  private const FORBIDDEN = '/(^|_)(DB|DATABASE|HASH|SALT|PASS|PASSWORD|PASSWD|PWD|ROOT|ADMIN)(_|$)/';

  /**
   * Returns whether a variable may be used as the key of an import.
   */
  public static function isAllowedName(string $name): bool {
    return preg_match(self::ALLOWED, $name) === 1 && preg_match(self::FORBIDDEN, $name) !== 1;
  }

  /**
   * Config schema callback: the name of a variable that may hold a key.
   */
  public static function validateName(mixed $value, ExecutionContextInterface $context): void {
    if (is_string($value) && $value !== '' && !self::isAllowedName($value)) {
      $context->buildViolation('The variable name must end in _API_KEY, _TOKEN, _SECRET or _KEY, and may not be about the database, a password, a salt or the admin.')->addViolation();
    }
  }

  /**
   * Returns the value of an environment variable.
   *
   * @throws \Drupal\import_engine\Secret\ForbiddenSecretException
   *   When the name may not be used as a key.
   * @throws \Drupal\import_engine\Secret\MissingSecretException
   *   When the variable is not set or empty. The message names the variable
   *   and never contains a value.
   */
  public function get(string $name): string {
    // Also here: configuration can be imported without being validated.
    if (!self::isAllowedName($name)) {
      throw new ForbiddenSecretException(sprintf('The environment variable %s may not be used as a key.', $name));
    }
    $value = getenv($name);
    if ($value === FALSE || $value === '') {
      throw new MissingSecretException(sprintf('The environment variable %s is not set.', $name));
    }
    return $value;
  }

}
