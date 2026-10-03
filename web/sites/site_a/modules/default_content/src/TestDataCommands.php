<?php

declare(strict_types=1);

namespace Drupal\default_content\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

final class TestDataCommands extends DrushCommands {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ModuleHandlerInterface $moduleHandler
  ) {
    parent::__construct();
  }

  #[CLI\Command(name: 'generate:test-data')]
  #[CLI\Option(name: 'customers', description: 'Number of customers to generate.')]
  #[CLI\Option(name: 'products', description: 'Number of products to generate.')]
  #[CLI\Option(name: 'coverage', description: 'Percentage (0-100) of customer/product combinations that get a price agreement.')]
  public function generateTestData(
    array $options = ['customers' => 10, 'products' => 20, 'coverage' => 70],
  ): void {
    $customerIds = $this->createCustomers((int) $options['customers']);
    $productIds = $this->createProducts((int) $options['products']);
    $this->createPriceAgreements($customerIds, $productIds, (int) $options['coverage']);

    $this->io()->success(sprintf(
      '%d customers, %d products, price agreements for ~%d%% of combinations.',
      count($customerIds),
      count($productIds),
      $options['coverage'],
    ));
  }

  private function createCustomers(int $count): array {
    if (!$this->moduleHandler->moduleExists('customers')) {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage('customer');
    $ids = [];
    for ($i = 1; $i <= $count; $i++) {
      $entity = $storage->create([
        'label' => "Customer $i",
        'customer_number' => 'CUST-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
      ]);
      $entity->save();
      $ids[] = $entity->id();
    }
    return $ids;
  }

  private function createProducts(int $count): array {
    if (!$this->moduleHandler->moduleExists('products')) {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage('product');
    $ids = [];
    for ($i = 1; $i <= $count; $i++) {
      $entity = $storage->create([
        'label' => "Product $i",
        'sku' => 'SKU-' . str_pad((string) $i, 5, '0', STR_PAD_LEFT),
        'base_price' => [
          'number' => (string) (rand(500, 50000) / 100),
          'currency_code' => 'EUR',
        ],
      ]);
      $entity->save();
      $ids[] = $entity->id();
    }
    return $ids;
  }

  private function createPriceAgreements(array $customerIds, array $productIds, int $coverage): void {
    if (!$this->moduleHandler->moduleExists('product_prices')) {
      return;
    }

    $storage = $this->entityTypeManager->getStorage('product_price');
    foreach ($customerIds as $customerId) {
      foreach ($productIds as $productId) {
        if (rand(1, 100) > $coverage) {
          continue;
        }
        $storage->create([
          'customer' => $customerId,
          'product' => $productId,
          'price' => [
            'number' => (string) (rand(400, 45000) / 100),
            'currency_code' => 'EUR',
          ],
        ])->save();
      }
    }
  }

}
