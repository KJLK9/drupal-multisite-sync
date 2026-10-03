<?php

declare(strict_types=1);

namespace Drupal\Tests\products\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\products\Entity\Product;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the hook implementations of the product module.
 */
#[Group('products')]
class ProductHooksTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'money_field',
    'products',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->installEntitySchema('product');
  }

  /**
   * The module registers its theme hook.
   */
  public function testThemeHookIsRegistered(): void {
    $registry = $this->container->get('theme.registry')->get();
    $this->assertArrayHasKey('product', $registry);
  }

  /**
   * Deleting a user deletes the products they own.
   */
  public function testUserDeleteRemovesProducts(): void {
    $account = $this->createUser();
    $product = Product::create(['label' => 'Test', 'uid' => $account->id()]);
    $product->save();

    $account->delete();

    $this->assertNull(Product::load($product->id()));
  }

  /**
   * Blocking a user unpublishes their products.
   */
  public function testUserCancelUnpublishesProducts(): void {
    $account = $this->createUser();
    $product = Product::create(['label' => 'Test', 'uid' => $account->id(), 'status' => TRUE]);
    $product->save();

    $this->container->get('module_handler')->invokeAll('user_cancel', [[], $account, 'user_cancel_block_unpublish']);

    $reloaded = Product::load($product->id());
    $this->assertNotNull($reloaded);
    $this->assertFalse((bool) $reloaded->get('status')->value);
  }

}
