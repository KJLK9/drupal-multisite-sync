<?php

/**
 * @file
 * Gives site B a read-only account for showing the engine to someone.
 *
 * Run with: drush php:script seed-site-b.php (DEMO_PASSWORD in the
 * environment). The account can see runs, the dead letter queue, the circuit
 * breakers and the lists of the catalog (accounts, items and agreements), and
 * cannot change anything: it has no permission to administer.
 */

use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

$password = getenv('DEMO_PASSWORD');
if ($password === FALSE || $password === '') {
  echo "DEMO_PASSWORD is not set: no demo account.\n";
  return;
}

if (Role::load('import_viewer') === NULL) {
  Role::create(['id' => 'import_viewer', 'label' => 'Import viewer'])->save();
}
$role = Role::load('import_viewer');
foreach ([
  'view import runs',
  'view the administration theme',
  'access administration pages',
  'access catalog lists',
  'view account',
  'view item',
  'view agreement',
] as $permission) {
  $role->grantPermission($permission);
}
$role->save();

$storage = \Drupal::entityTypeManager()->getStorage('user');
$existing = $storage->loadByProperties(['name' => 'demo']);
$user = $existing ? reset($existing) : User::create([
  'name' => 'demo',
  'mail' => 'demo@example.invalid',
  'status' => 1,
]);
$user->set('pass', $password);
$user->addRole('import_viewer');
$user->save();
echo "The account demo can look, not change.\n";
