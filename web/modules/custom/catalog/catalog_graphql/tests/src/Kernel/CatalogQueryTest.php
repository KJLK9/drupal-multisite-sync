<?php

declare(strict_types=1);

namespace Drupal\Tests\catalog_graphql\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Core\Database\Database;
use Drupal\customers\Entity\Customer;
use Drupal\graphql\Entity\Server;
use GraphQL\Type\Introspection;
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
        prices { totalCount items { price { number currencyCode } customer { label } product { label } } }
      }
    }');

    $this->assertSame([
      'label' => 'Widget',
      'basePrice' => ['number' => '9.950000', 'currencyCode' => 'EUR'],
      'prices' => [
        'totalCount' => 1,
        'items' => [
          [
            'price' => ['number' => '7.500000', 'currencyCode' => 'EUR'],
            'customer' => ['label' => 'ACME'],
            'product' => ['label' => 'Widget'],
          ],
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

    $query = '{ products(limit: 50) { items { label prices { items { price { number } customer { label } } } } } }';
    // Saved entities are cached; start cold so loads show up as queries.
    foreach (['customer', 'product', 'product_price'] as $type) {
      $this->container->get('entity_type.manager')->getStorage($type)->resetCache();
    }
    Database::startLog('catalog');
    $data = $this->query($query);
    $queries = Database::getLog('catalog');

    $this->assertCount(50, $data['products']['items']);
    foreach ($data['products']['items'] as $product) {
      $this->assertCount(2, $product['prices']['items']);
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
    $data = $this->query('{ customers(limit: 20) { items { label prices { items { price { number } product { label } } } } } }');
    $queries = Database::getLog('catalog');

    $this->assertCount(20, $data['customers']['items']);
    foreach ($data['customers']['items'] as $customer) {
      $this->assertCount(3, $customer['prices']['items']);
    }
    $price_queries = array_filter(
      $queries,
      static fn (array $log): bool => str_contains($log['query'], 'product_price'),
    );
    $this->assertLessThanOrEqual(3, count($price_queries), implode("\n", array_column($price_queries, 'query')));

    // The nested list is bounded as well: limit is applied per parent.
    $first = $this->query('{ customers(limit: 1) { items { prices(limit: 2) { totalCount items { id } } } } }');
    $prices = $first['customers']['items'][0]['prices'];
    $this->assertCount(2, $prices['items']);
    // The total is not limited by the page size.
    $this->assertSame(3, $prices['totalCount']);
    $over = $this->query('{ customers(limit: 1) { items { prices(limit: 1000) { items { id } } } } }');
    $this->assertCount(3, $over['customers']['items'][0]['prices']['items']);
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
    $query = '{ product(id: "' . $product->id() . '") { prices { totalCount items { price { number } } } } }';

    // Both view permissions (the default test user).
    $prices = $this->query($query)['product']['prices'];
    $this->assertCount(1, $prices['items']);
    $this->assertSame(1, $prices['totalCount']);

    // Product only: the price is hidden.
    $this->setUpCurrentUser([], ['view product', 'execute catalog arbitrary graphql requests']);
    $this->assertSame(['totalCount' => 0, 'items' => []], $this->query($query)['product']['prices']);

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

    $this->assertCount(50, $this->query('{ products { items { id } } }')['products']['items']);
    $this->assertCount(100, $this->query('{ products(limit: 500) { items { id } } }')['products']['items']);
    $this->assertCount(1, $this->query('{ products(limit: 0) { items { id } } }')['products']['items']);
    $last = $this->query('{ products(limit: 100, offset: 100) { totalCount items { id } } }')['products'];
    $this->assertCount(5, $last['items']);
    // The total counts all matches, independent of limit and offset.
    $this->assertSame(105, $last['totalCount']);
    $this->assertSame(105, $this->query('{ products(limit: 10) { totalCount } }')['products']['totalCount']);
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
      $content = $this->rawQuery('{ products(' . $argument . ': null) { items { id } } }');
      $this->assertArrayHasKey('errors', $content);
      $json = json_encode($content, JSON_THROW_ON_ERROR);
      $this->assertStringNotContainsString('Internal server error', $json);
      // Rejected because the argument is non-null, not because of a typo.
      $this->assertStringContainsString('Int!', $json);
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
      $page = $this->query('{ products(limit: 3, offset: ' . $offset . ') { items { id } } }')['products']['items'];
      $ids = array_merge($ids, array_map(static fn (array $row): int => (int) $row['id'], $page));
    }
    $this->assertSame(range(1, 7), $ids);
  }

  /**
   * The explorer's introspection query is accepted by the depth limit.
   */
  public function testIntrospectionQueryIsAllowed(): void {
    $data = $this->query(Introspection::getIntrospectionQuery());

    $names = array_column($data['__schema']['types'], 'name');
    foreach (['Query', 'Customer', 'Product', 'ProductPrice', 'Money'] as $type) {
      $this->assertContains($type, $names);
    }
  }

  /**
   * The cyclic schema cannot be used to build arbitrarily deep queries.
   */
  public function testQueryDepthIsLimited(): void {
    // Products -> items -> (prices -> items -> customer -> prices -> items ->
    // product)* -> id.
    $query = '{ products { items ' . str_repeat('{ prices { items { customer { prices { items { product ', 3) . '{ id }' . str_repeat(' } } } } } }', 3) . ' } }';
    $content = $this->rawQuery($query);

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
