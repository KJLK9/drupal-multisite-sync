<?php

declare(strict_types=1);

namespace Drupal\Tests\product_prices\Kernel;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\customers\Entity\Customer;
use Drupal\product_prices\Entity\ProductPrice;
use Drupal\products\Entity\Product;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that a customer has at most one price per product.
 */
#[Group('product_prices')]
#[RunTestsInSeparateProcesses]
class ProductPriceUniquenessTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'money_field',
    'published_access',
    'customers',
    'products',
    'product_prices',
  ];

  /**
   * The customer and product of the first price.
   *
   * @var array{customer: \Drupal\customers\Entity\Customer, product: \Drupal\products\Entity\Product}
   */
  protected array $pair;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('customer');
    $this->installEntitySchema('product');
    $this->installEntitySchema('product_price');
    $customer = Customer::create(['label' => 'ACME']);
    $customer->save();
    $product = Product::create(['label' => 'Widget']);
    $product->save();
    $this->pair = ['customer' => $customer, 'product' => $product];
  }

  /**
   * Builds a price for the given customer and product.
   */
  protected function price(Customer $customer, Product $product): ProductPrice {
    return ProductPrice::create([
      'product_id' => $product->id(),
      'customer' => $customer->id(),
      'price' => ['number' => '5.00', 'currency_code' => 'EUR'],
    ]);
  }

  /**
   * Validation rejects a second price for the same product and customer.
   */
  public function testValidationRejectsDuplicates(): void {
    $first = $this->price($this->pair['customer'], $this->pair['product']);
    $this->assertCount(0, $first->validate());
    $first->save();

    $duplicate = $this->price($this->pair['customer'], $this->pair['product']);
    $violations = $duplicate->validate();
    $this->assertCount(1, $violations);
    $violation = $violations->get(0);
    $this->assertSame('customer', $violation->getPropertyPath());
    $this->assertStringContainsString('already exists', (string) $violation->getMessage());

    // The saved price is not a duplicate of itself.
    $this->assertCount(0, $first->validate());
  }

  /**
   * The same product for another customer, and vice versa, is allowed.
   */
  public function testOtherCombinationsAreAllowed(): void {
    $this->price($this->pair['customer'], $this->pair['product'])->save();
    $other_customer = Customer::create(['label' => 'Other']);
    $other_customer->save();
    $other_product = Product::create(['label' => 'Gadget']);
    $other_product->save();

    $this->assertCount(0, $this->price($other_customer, $this->pair['product'])->validate());
    $this->assertCount(0, $this->price($this->pair['customer'], $other_product)->validate());
  }

  /**
   * The database refuses a duplicate even when validation is bypassed.
   */
  public function testDatabaseGuaranteesUniqueness(): void {
    $this->price($this->pair['customer'], $this->pair['product'])->save();

    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessageMatches('/Duplicate entry|product_price__product_customer/');
    $this->price($this->pair['customer'], $this->pair['product'])->save();
  }

  /**
   * Product, customer and price are required.
   */
  public function testReferencesAndPriceAreRequired(): void {
    $violations = ProductPrice::create([])->validate();

    $paths = [];
    foreach ($violations as $violation) {
      $paths[] = $violation->getPropertyPath();
    }
    sort($paths);
    $this->assertSame(['customer', 'price', 'product_id'], $paths);
  }

}
