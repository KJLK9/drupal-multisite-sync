<?php

declare(strict_types=1);

namespace Drupal\Tests\products\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\products\Entity\Product;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that unpublished products are hidden from non-administrators.
 */
#[Group('products')]
#[RunTestsInSeparateProcesses]
class ProductPublishedAccessTest extends KernelTestBase {

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
    'published_access',
    'products',
  ];

  /**
   * The published product.
   */
  protected Product $productPublished;

  /**
   * The unpublished product.
   */
  protected Product $productUnpublished;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('product');
    // User 1 bypasses all access checks; burn it.
    $this->createUser();
    $this->productPublished = Product::create(['label' => 'Published', 'status' => TRUE]);
    $this->productPublished->save();
    $this->productUnpublished = Product::create(['label' => 'Unpublished', 'status' => FALSE]);
    $this->productUnpublished->save();
  }

  /**
   * Entity access: the view permission only covers published products.
   */
  public function testEntityAccess(): void {
    $viewer = $this->createUser(['view product']);
    $admin = $this->createUser(['administer product']);

    $this->assertTrue($this->productPublished->access('view', $viewer));
    $this->assertFalse($this->productUnpublished->access('view', $viewer));
    $this->assertTrue($this->productUnpublished->access('view', $admin));
    $this->assertFalse($this->productPublished->access('view', $this->createUser()));
  }

  /**
   * Entity queries with access checking agree with entity access.
   */
  public function testAccessCheckedQueries(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('product');
    $count = fn (bool $access_check): int => (int) $storage->getQuery()->accessCheck($access_check)->count()->execute();

    $this->setCurrentUser($this->createUser(['view product']));
    $this->assertSame(1, $count(TRUE));
    // Without access checking nothing is filtered.
    $this->assertSame(2, $count(FALSE));

    $this->setCurrentUser($this->createUser(['administer product']));
    $this->assertSame(2, $count(TRUE));
  }

}
