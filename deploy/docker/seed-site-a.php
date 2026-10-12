<?php

/**
 * @file
 * Gives site A the account that site B reads the catalog with.
 *
 * Run with: drush php:script seed-site-a.php (SITE_A_API_KEY in the
 * environment). The key is the one the import connection of site B sends, so
 * the two sites agree without anyone copying it by hand.
 */

use Drupal\user\Entity\User;

$key = getenv('SITE_A_API_KEY');
if ($key === FALSE || $key === '') {
  throw new \RuntimeException('SITE_A_API_KEY is not set.');
}

$storage = \Drupal::entityTypeManager()->getStorage('user');
$existing = $storage->loadByProperties(['name' => 'catalog_reader']);
$user = $existing ? reset($existing) : User::create([
  'name' => 'catalog_reader',
  'mail' => 'catalog_reader@example.invalid',
  'status' => 1,
  'roles' => ['catalog_reader'],
]);
// key_auth gives a new account a key of its own when it is inserted, so the
// key is set once the account exists.
$user->save();
$user->set('api_key', $key)->save();
echo "The account catalog_reader has the key of SITE_A_API_KEY.\n";
