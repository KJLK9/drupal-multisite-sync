<?php

declare(strict_types=1);

namespace Drupal\Tests\catalog_graphql\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Core\Database\Database;
use Drupal\customers\Entity\Customer;
use Drupal\graphql\Entity\Server;
use Drupal\product_prices\Entity\ProductPrice;
use Drupal\products\Entity\Product;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the catalog GraphQL schema end to end.
 */
#[Group('catalog_graphql')]
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
class CatalogQueryTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'typed_data',
    'file',
    'text',
    'graphql',
    'money_field',
    'customers',
    'products',
    'product_prices',
    'catalog_graphql',
  ];

  /**
   * The GraphQL server under test.
   */
  protected Server $server;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'graphql']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('graphql_server');
    $this->installEntitySchema('customer');
    $this->installEntitySchema('product');
    $this->installEntitySchema('product_price');
    // Use the server configuration the module ships with.
    $this->installConfig(['catalog_graphql']);
    $server = Server::load('catalog');
    $this->assertInstanceOf(Server::class, $server);
    $this->server = $server;
    // Expose error details so failing tests are readable.
    $this->server->set('debug_flag', 3)->save();

    $this->setUpCurrentUser([], [
      'access content',
      'view customer',
      'view product',
      'execute catalog arbitrary graphql requests',
    ]);
  }

  /**
   * Fields, relations and money values resolve to the stored data.
   */
  public function testProductWithPricesAndCustomer(): void {
    $customer = Customer::create(['label' => 'ACME', 'status' => TRUE]);
    $customer->save();
    $product = $this->createProduct('Widget', '9.95');
    ProductPrice::create([
      'product_id' => $product->id(),
      'customer' => $customer->id(),
      'price' => ['number' => '7.500000', 'currency_code' => 'EUR'],
    ])->save();

    $data = $this->query('{
      product(id: "' . $product->id() . '") {
        label
        basePrice { number currencyCode }
        prices { price { number currencyCode } customer { label } product { label } }
      }
    }');

    $this->assertSame([
      'label' => 'Widget',
      'basePrice' => ['number' => '9.950000', 'currencyCode' => 'EUR'],
      'prices' => [
        [
          'price' => ['number' => '7.500000', 'currencyCode' => 'EUR'],
          'customer' => ['label' => 'ACME'],
          'product' => ['label' => 'Widget'],
        ],
      ],
    ], $data['product']);
  }

  /**
   * Fetching prices must not cost one query per product (no N+1).
   */
  public function testPricesAreBatchedAcrossProducts(): void {
    $customers = [];
    for ($c = 1; $c <= 10; $c++) {
      $customers[$c] = Customer::create(['label' => "Customer $c", 'status' => TRUE]);
      $customers[$c]->save();
    }
    for ($i = 1; $i <= 50; $i++) {
      $product = $this->createProduct("Product $i", '1.00');
      foreach ([1, 2] as $n) {
        ProductPrice::create([
          'product_id' => $product->id(),
          'customer' => $customers[($i + $n) % 10 + 1]->id(),
          'price' => ['number' => (string) $n, 'currency_code' => 'EUR'],
        ])->save();
      }
    }

    $query = '{ products(limit: 50) { label prices { price { number } customer { label } } } }';
    // Saved entities are cached; start cold so loads show up as queries.
    foreach (['customer', 'product', 'product_price'] as $type) {
      $this->container->get('entity_type.manager')->getStorage($type)->resetCache();
    }
    Database::startLog('catalog');
    $data = $this->query($query);
    $queries = Database::getLog('catalog');

    $this->assertCount(50, $data['products']);
    foreach ($data['products'] as $product) {
      $this->assertCount(2, $product['prices']);
    }

    $price_queries = array_filter(
      $queries,
      static fn (array $log): bool => str_contains($log['query'], 'product_price'),
    );
    // One entity query plus one load for all prices, not 50 of each.
    $this->assertLessThanOrEqual(3, count($price_queries), implode("\n", array_column($price_queries, 'query')));

    // Access checks and the customer field must not load customers one by one.
    $customer_queries = array_filter(
      $queries,
      static fn (array $log): bool => preg_match('/FROM\s+\S*customer\b/', $log['query']) === 1,
    );
    $this->assertLessThanOrEqual(2, count($customer_queries), implode("\n", array_column($customer_queries, 'query')));
  }

  /**
   * Prices are reachable from the customer side too, and batched as well.
   */
  public function testCustomerPricesAreBatchedAndLimited(): void {
    $products = [];
    for ($p = 1; $p <= 10; $p++) {
      $products[$p] = $this->createProduct("Product $p", '1.00');
    }
    $customers = [];
    for ($c = 1; $c <= 20; $c++) {
      $customers[$c] = Customer::create(['label' => "Customer $c", 'status' => TRUE]);
      $customers[$c]->save();
      foreach ([1, 2, 3] as $n) {
        ProductPrice::create([
          'product_id' => $products[($c + $n) % 10 + 1]->id(),
          'customer' => $customers[$c]->id(),
          'price' => ['number' => (string) $n, 'currency_code' => 'EUR'],
        ])->save();
      }
    }

    foreach (['customer', 'product', 'product_price'] as $type) {
      $this->container->get('entity_type.manager')->getStorage($type)->resetCache();
    }
    Database::startLog('catalog');
    $data = $this->query('{ customers(limit: 20) { label prices { price { number } product { label } } } }');
    $queries = Database::getLog('catalog');

    $this->assertCount(20, $data['customers']);
    foreach ($data['customers'] as $customer) {
      $this->assertCount(3, $customer['prices']);
    }
    $price_queries = array_filter(
      $queries,
      static fn (array $log): bool => str_contains($log['query'], 'product_price'),
    );
    $this->assertLessThanOrEqual(3, count($price_queries), implode("\n", array_column($price_queries, 'query')));

    // The nested list is bounded as well: limit is applied per parent.
    $first = $this->query('{ customers(limit: 1) { prices(limit: 2) { id } } }');
    $this->assertCount(2, $first['customers'][0]['prices']);
    $over = $this->query('{ customers(limit: 1) { prices(limit: 1000) { id } } }');
    $this->assertCount(3, $over['customers'][0]['prices']);
  }

  /**
   * Prices are visible to users who can view both the product and customer.
   */
  public function testPriceAccessFollowsProductAndCustomer(): void {
    $customer = Customer::create(['label' => 'ACME', 'status' => TRUE]);
    $customer->save();
    $product = $this->createProduct('Widget', '9.95');
    ProductPrice::create([
      'product_id' => $product->id(),
      'customer' => $customer->id(),
      'price' => ['number' => '7.50', 'currency_code' => 'EUR'],
    ])->save();
    $query = '{ product(id: "' . $product->id() . '") { prices { price { number } } } }';

    // Both view permissions (the default test user).
    $this->assertCount(1, $this->query($query)['product']['prices']);

    // Product only: the price is hidden.
    $this->setUpCurrentUser([], ['view product', 'execute catalog arbitrary graphql requests']);
    $this->assertSame([], $this->query($query)['product']['prices']);

    // Customer only: the product itself is not visible.
    $this->setUpCurrentUser([], ['view customer', 'execute catalog arbitrary graphql requests']);
    $this->assertNull($this->query($query)['product']);
  }

  /**
   * Lists default to 50 items and never return more than 100.
   */
  public function testListLimits(): void {
    for ($i = 1; $i <= 105; $i++) {
      $this->createProduct("Product $i", '1.00');
    }

    $this->assertCount(50, $this->query('{ products { id } }')['products']);
    $this->assertCount(100, $this->query('{ products(limit: 500) { id } }')['products']);
    $this->assertCount(1, $this->query('{ products(limit: 0) { id } }')['products']);
    $this->assertCount(5, $this->query('{ products(limit: 100, offset: 100) { id } }')['products']);
  }

  /**
   * Every scalar field of every type resolves (boolean, text, ids).
   */
  public function testScalarFieldsResolve(): void {
    $customer = Customer::create([
      'label' => 'ACME',
      'customer_number' => 'C-100',
      'status' => FALSE,
      'description' => 'Preferred customer',
    ]);
    $customer->save();
    $product = $this->createProduct('Widget', '9.95');

    $data = $this->query('{
      customer(id: "' . $customer->id() . '") { id uuid label customerNumber status description }
      product(id: "' . $product->id() . '") { id uuid label status description }
    }');

    $this->assertSame((string) $customer->id(), (string) $data['customer']['id']);
    $this->assertSame($customer->uuid(), $data['customer']['uuid']);
    $this->assertSame('C-100', $data['customer']['customerNumber']);
    $this->assertFalse($data['customer']['status']);
    $this->assertSame('Preferred customer', $data['customer']['description']);
    $this->assertTrue($data['product']['status']);
  }

  /**
   * An explicit null limit or offset is a validation error, not a crash.
   */
  public function testNullPaginationArgumentsAreRejected(): void {
    $this->createProduct('Widget', '1.00');
    foreach (['limit', 'offset'] as $argument) {
      $content = $this->rawQuery('{ products(' . $argument . ': null) { id } }');
      $this->assertArrayHasKey('errors', $content);
      $this->assertStringNotContainsString('Internal server error', json_encode($content, JSON_THROW_ON_ERROR));
    }
  }

  /**
   * Pages are ordered by id, so offset paging is stable.
   */
  public function testListsAreOrderedById(): void {
    for ($i = 1; $i <= 7; $i++) {
      $this->createProduct("Product $i", '1.00');
    }
    $ids = [];
    foreach ([0, 3, 6] as $offset) {
      $page = $this->query('{ products(limit: 3, offset: ' . $offset . ') { id } }')['products'];
      $ids = array_merge($ids, array_map(static fn (array $row): int => (int) $row['id'], $page));
    }
    $this->assertSame(range(1, 7), $ids);
  }

  /**
   * The cyclic schema cannot be used to build arbitrarily deep queries.
   */
  public function testQueryDepthIsLimited(): void {
    $deep = '{ products { prices { customer { prices { product { prices { customer { id } } } } } } } }';
    $content = $this->rawQuery($deep);
    $this->assertArrayHasKey('errors', $content);
    $this->assertStringContainsString('depth', strtolower(json_encode($content, JSON_THROW_ON_ERROR)));
  }

  /**
   * Creates and saves a product.
   */
  protected function createProduct(string $label, string $price): Product {
    $product = Product::create([
      'label' => $label,
      'status' => TRUE,
      'base_price' => ['number' => $price, 'currency_code' => 'EUR'],
    ]);
    $product->save();
    return $product;
  }

  /**
   * Executes a query against the server and returns the data.
   *
   * @return array<string, mixed>
   *   The "data" part of the response.
   */
  protected function query(string $query): array {
    $content = $this->rawQuery($query);
    $this->assertArrayNotHasKey('errors', $content, json_encode($content, JSON_THROW_ON_ERROR));
    return $content['data'];
  }

  /**
   * Executes a query against the server and returns the full response.
   *
   * @return array<string, mixed>
   *   The decoded JSON response, including any errors.
   */
  protected function rawQuery(string $query): array {
    $request = Request::create($this->server->get('endpoint'), 'GET', ['query' => $query]);
    $response = $this->container->get('http_kernel')->handle($request);
    return json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
  }

}
