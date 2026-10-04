<?php

declare(strict_types=1);

namespace Drupal\Tests\catalog_jsonapi\Kernel;

use Drupal\customers\Entity\Customer;
use Drupal\KernelTests\KernelTestBase;
use Drupal\product_prices\Entity\ProductPrice;
use Drupal\products\Entity\Product;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the JSON:API output of the catalog resources.
 */
#[Group('catalog_jsonapi')]
#[RunTestsInSeparateProcesses]
class CatalogJsonApiTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'file',
    'serialization',
    'jsonapi',
    'jsonapi_include',
    'money_field',
    'published_access',
    'customers',
    'products',
    'product_prices',
    'catalog_jsonapi',
  ];

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
    $this->setUpCurrentUser([], ['view customer', 'view product']);
  }

  /**
   * Returns the decoded response of a JSON:API request.
   *
   * @return array<string, mixed>
   *   The decoded body.
   */
  protected function get(string $path): array {
    $request = Request::create($path, 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/vnd.api+json']);
    $response = $this->container->get('http_kernel')->handle($request);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    return json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
  }

  /**
   * The author is not exposed on customers and products.
   */
  public function testAuthorIsHidden(): void {
    Customer::create(['label' => 'ACME', 'status' => TRUE])->save();
    Product::create(['label' => 'Widget', 'status' => TRUE])->save();

    foreach (['/jsonapi/customer/customer', '/jsonapi/product/product'] as $path) {
      $resource = $this->get($path)['data'][0];
      $this->assertArrayNotHasKey('uid', $resource);
      $this->assertArrayNotHasKey('uid', $resource['relationships'] ?? []);
      $this->assertArrayNotHasKey('uid', $resource['attributes'] ?? []);
      $this->assertStringNotContainsString('user--user', json_encode($resource, JSON_THROW_ON_ERROR));
    }
  }

  /**
   * Unpublished items are not listed.
   */
  public function testUnpublishedItemsAreNotListed(): void {
    Customer::create(['label' => 'Visible', 'status' => TRUE])->save();
    Customer::create(['label' => 'Hidden', 'status' => FALSE])->save();

    $labels = [];
    foreach ($this->get('/jsonapi/customer/customer')['data'] as $resource) {
      $labels[] = $resource['label'] ?? $resource['attributes']['label'];
    }
    $this->assertSame(['Visible'], $labels);
  }

  /**
   * Included relationships are flattened into the parent resource.
   */
  public function testIncludesAreFlattened(): void {
    $customer = Customer::create(['label' => 'ACME', 'status' => TRUE]);
    $customer->save();
    $product = Product::create(['label' => 'Widget', 'status' => TRUE]);
    $product->save();
    ProductPrice::create([
      'product_id' => $product->id(),
      'customer' => $customer->id(),
      'price' => ['number' => '7.50', 'currency_code' => 'EUR'],
    ])->save();
    $this->setUpCurrentUser([], ['view customer', 'view product']);

    $price = $this->get('/jsonapi/product_price/product_price?include=product_id,customer')['data'][0];

    $this->assertSame('Widget', $price['product_id']['label']);
    $this->assertSame('ACME', $price['customer']['label']);
  }

}
