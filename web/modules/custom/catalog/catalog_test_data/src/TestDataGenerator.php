<?php

declare(strict_types=1);

namespace Drupal\catalog_test_data;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Generates customers, products and price agreements for development.
 */
final class TestDataGenerator {

  /**
   * Constructs a TestDataGenerator object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  /**
   * Generates the data.
   *
   * @param int $customers
   *   The number of customers to create.
   * @param int $products
   *   The number of products to create.
   * @param int $coverage
   *   Percentage (0-100) of customer and product combinations that get a
   *   price agreement.
   *
   * @return array{customers: int, products: int, prices: int}
   *   The number of entities created per type.
   *
   * @throws \InvalidArgumentException
   *   When a count is negative or the coverage is outside 0-100.
   */
  public function generate(int $customers, int $products, int $coverage): array {
    if ($customers < 0 || $products < 0) {
      throw new \InvalidArgumentException('The number of customers and products cannot be negative.');
    }
    if ($coverage < 0 || $coverage > 100) {
      throw new \InvalidArgumentException('The coverage must be between 0 and 100.');
    }

    $customer_ids = $this->createCustomers($customers);
    $product_ids = $this->createProducts($products);

    return [
      'customers' => count($customer_ids),
      'products' => count($product_ids),
      'prices' => $this->createPrices($customer_ids, $product_ids, $coverage),
    ];
  }

  /**
   * Creates customers with consecutive customer numbers.
   *
   * @return array<int>
   *   The IDs of the new customers.
   */
  private function createCustomers(int $count): array {
    $storage = $this->entityTypeManager->getStorage('customer');
    // Continue after existing customers so repeated runs do not collide.
    $offset = (int) $storage->getQuery()->accessCheck(FALSE)->count()->execute();

    $ids = [];
    for ($i = 1; $i <= $count; $i++) {
      $customer = $storage->create([
        'label' => 'Customer ' . ($offset + $i),
        'customer_number' => 'CUST-' . str_pad((string) ($offset + $i), 4, '0', STR_PAD_LEFT),
        'status' => TRUE,
      ]);
      $customer->save();
      $ids[] = (int) $customer->id();
    }
    return $ids;
  }

  /**
   * Creates products with a random base price.
   *
   * @return array<int>
   *   The IDs of the new products.
   */
  private function createProducts(int $count): array {
    $storage = $this->entityTypeManager->getStorage('product');
    $offset = (int) $storage->getQuery()->accessCheck(FALSE)->count()->execute();

    $ids = [];
    for ($i = 1; $i <= $count; $i++) {
      $product = $storage->create([
        'label' => 'Product ' . ($offset + $i),
        'status' => TRUE,
        'base_price' => $this->randomPrice(500, 50000),
      ]);
      $product->save();
      $ids[] = (int) $product->id();
    }
    return $ids;
  }

  /**
   * Creates a price agreement for a share of all customer/product pairs.
   *
   * @param array<int> $customer_ids
   *   The customer IDs.
   * @param array<int> $product_ids
   *   The product IDs.
   * @param int $coverage
   *   Percentage (0-100) of pairs that get a price.
   *
   * @return int
   *   The number of prices created.
   */
  private function createPrices(array $customer_ids, array $product_ids, int $coverage): int {
    $storage = $this->entityTypeManager->getStorage('product_price');
    $created = 0;
    foreach ($customer_ids as $customer_id) {
      foreach ($product_ids as $product_id) {
        if (random_int(1, 100) > $coverage) {
          continue;
        }
        $storage->create([
          'product_id' => $product_id,
          'customer' => $customer_id,
          'price' => $this->randomPrice(400, 45000),
        ])->save();
        $created++;
      }
    }
    return $created;
  }

  /**
   * Returns a random euro amount.
   *
   * @param int $min_cents
   *   The lowest amount in cents.
   * @param int $max_cents
   *   The highest amount in cents.
   *
   * @return array{number: string, currency_code: string}
   *   A money_field value.
   */
  private function randomPrice(int $min_cents, int $max_cents): array {
    return [
      'number' => number_format(random_int($min_cents, $max_cents) / 100, 2, '.', ''),
      'currency_code' => 'EUR',
    ];
  }

}
