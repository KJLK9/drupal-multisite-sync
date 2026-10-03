<?php

declare(strict_types=1);

namespace Drupal\Tests\product_prices\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\customers\Entity\Customer;
use Drupal\product_prices\Entity\ProductPrice;
use Drupal\products\Entity\Product;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests access to product prices.
 */
#[Group('product_prices')]
class ProductPriceAccessTest extends KernelTestBase {

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
    'customers',
    'products',
    'product_prices',
  ];

  /**
   * The price under test.
   */
  protected ProductPrice $price;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('customer');
    $this->installEntitySchema('product');
    $this->installEntitySchema('product_price');
    // User 1 bypasses all access checks; burn it.
    $this->createUser();

    $customer = Customer::create(['label' => 'ACME']);
    $customer->save();
    $product = Product::create(['label' => 'Widget']);
    $product->save();
    $this->price = ProductPrice::create([
      'product_id' => $product->id(),
      'customer' => $customer->id(),
    ]);
    $this->price->save();
  }

  /**
   * Viewing requires view access to both the product and the customer.
   */
  public function testViewRequiresProductAndCustomerAccess(): void {
    $both = $this->createUser(['view product', 'view customer']);
    $product_only = $this->createUser(['view product']);
    $customer_only = $this->createUser(['view customer']);
    $nobody = $this->createUser();

    $this->assertTrue($this->price->access('view', $both));
    $this->assertFalse($this->price->access('view', $product_only));
    $this->assertFalse($this->price->access('view', $customer_only));
    $this->assertFalse($this->price->access('view', $nobody));
  }

  /**
   * Only administrators can change prices, whatever they may view.
   */
  public function testChangesRequireAdministerPermission(): void {
    $viewer = $this->createUser(['view product', 'view customer']);
    $admin = $this->createUser(['administer product_price']);

    foreach (['update', 'delete'] as $operation) {
      $this->assertFalse($this->price->access($operation, $viewer));
      $this->assertTrue($this->price->access($operation, $admin));
    }
    $handler = $this->container->get('entity_type.manager')->getAccessControlHandler('product_price');
    $this->assertFalse($handler->createAccess(NULL, $viewer));
    $this->assertTrue($handler->createAccess(NULL, $admin));
    $this->assertTrue($this->price->access('view', $admin));
  }

  /**
   * A price without product or customer is only visible to administrators.
   */
  public function testPriceWithoutReferencesIsAdminOnly(): void {
    $price = ProductPrice::create([]);
    $price->save();
    $viewer = $this->createUser(['view product', 'view customer']);
    $admin = $this->createUser(['administer product_price']);

    $this->assertFalse($price->access('view', $viewer));
    $this->assertTrue($price->access('view', $admin));
  }

}
