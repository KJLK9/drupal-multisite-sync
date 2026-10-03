<?php

/**
 * @file
 * Environment-driven settings shared by all sites.
 *
 * Required from config/<site>/settings.php, which is included last from
 * web/sites/<site>/settings.php. Reads the project's .env (real environment
 * variables win) and builds the database connection and hash salt for the
 * current site from <SITE>_DB_* and <SITE>_HASH_SALT, where <SITE> is the
 * upper-cased site directory name, e.g. SITE_A.
 *
 * Secrets never belong in settings.php or in git: see .env.example.
 */

use Symfony\Component\Dotenv\Dotenv;

$env_file = dirname(__DIR__) . '/.env';
if (is_file($env_file)) {
  // usePutenv() so getenv() sees the values; existing variables are kept.
  (new Dotenv())->usePutenv()->load($env_file);
}

$site_key = strtoupper(basename($site_path));
$required_env = static function (string $name): string {
  $value = getenv($name);
  if ($value === FALSE || $value === '') {
    throw new \RuntimeException(sprintf('Missing environment variable %s. Copy .env.example to .env and fill it in.', $name));
  }
  return $value;
};

$databases['default']['default'] = [
  'database' => $required_env($site_key . '_DB_NAME'),
  'username' => $required_env($site_key . '_DB_USER'),
  'password' => $required_env($site_key . '_DB_PASSWORD'),
  'host' => $required_env($site_key . '_DB_HOST'),
  'port' => getenv($site_key . '_DB_PORT') ?: '3306',
  'prefix' => '',
  'isolation_level' => 'READ COMMITTED',
  'driver' => 'mysql',
  'namespace' => 'Drupal\\mysql\\Driver\\Database\\mysql',
  'autoload' => 'core/modules/mysql/src/Driver/Database/mysql/',
];

$settings['hash_salt'] = $required_env($site_key . '_HASH_SALT');

unset($env_file, $site_key, $required_env);
