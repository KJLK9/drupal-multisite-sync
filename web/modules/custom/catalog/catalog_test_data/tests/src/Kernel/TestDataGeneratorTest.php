<?php

declare(strict_types=1);

namespace Drupal\Tests\catalog_test_data\Kernel;

use Drupal\catalog_test_data\TestDataGenerator;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the test data generator.
 */
#[Group('catalog_test_data')]
#[RunTestsInSeparateProcesses]
class TestDataGeneratorTest extends KernelTestBase {

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
    'catalog_test_data',
  ];

  /**
   * The generator under test.
   */
  protected TestDataGenerator $generator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('customer');
    $this->installEntitySchema('product');
    $this->installEntitySchema('product_price');
    $this->generator = $this->container->get('catalog_test_data.generator');
  }

  /**
   * Full coverage creates a price for every customer and product pair.
   */
  public function testFullCoverageCreatesAllPrices(): void {
    $result = $this->generator->generate(3, 4, 100);

    $this->assertSame(['customers' => 3, 'products' => 4, 'prices' => 12], $result);
    $this->assertSame(12, $this->countEntities('product_price'));
  }

  /**
   * Zero coverage creates no prices.
   */
  public function testZeroCoverageCreatesNoPrices(): void {
    $result = $this->generator->generate(2, 2, 0);

    $this->assertSame(0, $result['prices']);
    $this->assertSame(2, $this->countEntities('customer'));
  }

  /**
   * Generated data is valid, and repeated runs get unique customer numbers.
   */
  public function testDataIsValidAndRepeatable(): void {
    $this->generator->generate(2, 1, 100);
    $this->generator->generate(2, 1, 100);

    $storage = $this->container->get('entity_type.manager')->getStorage('customer');
    $numbers = [];
    foreach ($storage->loadMultiple() as $customer) {
      /** @var \Drupal\Core\Entity\ContentEntityInterface $customer */
      $this->assertCount(0, $customer->validate());
      $numbers[] = $customer->get('customer_number')->value;
    }
    $this->assertSame(['CUST-0001', 'CUST-0002', 'CUST-0003', 'CUST-0004'], $numbers);

    $prices = $this->container->get('entity_type.manager')->getStorage('product_price')->loadMultiple();
    foreach ($prices as $price) {
      /** @var \Drupal\Core\Entity\ContentEntityInterface $price */
      $this->assertCount(0, $price->get('price')->validate());
      $this->assertNotEmpty($price->get('product_id')->target_id);
    }
  }

  /**
   * Invalid arguments are rejected.
   */
  public function testInvalidArgumentsAreRejected(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->generator->generate(1, 1, 150);
  }

  /**
   * Counts the entities of a type.
   */
  protected function countEntities(string $entity_type): int {
    return (int) $this->container->get('entity_type.manager')
      ->getStorage($entity_type)
      ->getQuery()
      ->accessCheck(FALSE)
      ->count()
      ->execute();
  }

}
