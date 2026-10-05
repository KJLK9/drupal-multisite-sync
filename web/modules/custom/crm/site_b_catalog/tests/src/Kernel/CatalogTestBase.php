<?php

declare(strict_types=1);

namespace Drupal\Tests\site_b_catalog\Kernel;

use Drupal\filter\Entity\FilterFormat;
use Drupal\site_b_catalog\Entity\Account;
use Drupal\site_b_catalog\Entity\Agreement;
use Drupal\site_b_catalog\Entity\Item;
use Drupal\Tests\import_engine\Kernel\StorageTestBase;
use Drupal\user\Entity\User;

/**
 * Base class for tests of the content model of site B.
 */
abstract class CatalogTestBase extends StorageTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'money_field',
    'published_access',
    'import_engine',
    'site_b_catalog',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('account');
    $this->installEntitySchema('item');
    $this->installEntitySchema('agreement');
    $this->installConfig(['filter']);
    // The first user: may do anything, and owns what the imports write.
    User::create(['name' => 'importer', 'status' => 1])->save();
    // Site B has this format from its standard profile.
    FilterFormat::create([
      'format' => 'basic_html',
      'name' => 'Basic HTML',
      'filters' => ['filter_html' => ['status' => TRUE, 'settings' => ['allowed_html' => '<p> <strong> <em>']]],
    ])->save();
  }

  /**
   * Creates and saves an account.
   *
   * @param string $number
   *   The account number.
   * @param array<string, mixed> $values
   *   Other values.
   */
  protected function account(string $number, array $values = []): Account {
    $account = Account::create($values + ['name' => "Account $number", 'number' => $number]);
    $account->save();
    return $account;
  }

  /**
   * Creates and saves an item.
   *
   * @param string $title
   *   The title.
   * @param array<string, mixed> $values
   *   Other values.
   */
  protected function item(string $title, array $values = []): Item {
    $item = Item::create($values + ['title' => $title]);
    $item->save();
    return $item;
  }

  /**
   * Creates and saves an agreement.
   *
   * @param \Drupal\site_b_catalog\Entity\Account $account
   *   The account.
   * @param \Drupal\site_b_catalog\Entity\Item $item
   *   The item.
   * @param string $price
   *   The agreed price.
   */
  protected function agreement(Account $account, Item $item, string $price = '5.00'): Agreement {
    $agreement = Agreement::create([
      'account' => $account->id(),
      'item' => $item->id(),
      'price' => ['number' => $price, 'currency_code' => 'EUR'],
    ]);
    $agreement->save();
    return $agreement;
  }

  /**
   * Counts the entities of a type.
   */
  protected function total(string $type): int {
    return (int) $this->container->get('entity_type.manager')->getStorage($type)->getQuery()->accessCheck(FALSE)->count()->execute();
  }

}
