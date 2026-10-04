<?php

declare(strict_types=1);

namespace Drupal\Tests\customers\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\customers\Entity\Customer;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that unpublished customers are hidden from non-administrators.
 */
#[Group('customers')]
#[RunTestsInSeparateProcesses]
class CustomerPublishedAccessTest extends KernelTestBase {

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
    'customers',
  ];

  /**
   * The published customer.
   */
  protected Customer $customerPublished;

  /**
   * The unpublished customer.
   */
  protected Customer $customerUnpublished;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('customer');
    // User 1 bypasses all access checks; burn it.
    $this->createUser();
    $this->customerPublished = Customer::create(['label' => 'Published', 'status' => TRUE]);
    $this->customerPublished->save();
    $this->customerUnpublished = Customer::create(['label' => 'Unpublished', 'status' => FALSE]);
    $this->customerUnpublished->save();
  }

  /**
   * Entity access: the view permission only covers published customers.
   */
  public function testEntityAccess(): void {
    $viewer = $this->createUser(['view customer']);
    $admin = $this->createUser(['administer customer']);

    $this->assertTrue($this->customerPublished->access('view', $viewer));
    $this->assertFalse($this->customerUnpublished->access('view', $viewer));
    $this->assertTrue($this->customerUnpublished->access('view', $admin));
    $this->assertFalse($this->customerPublished->access('view', $this->createUser()));
  }

  /**
   * Entity queries with access checking agree with entity access.
   */
  public function testAccessCheckedQueries(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('customer');
    $count = fn (bool $access_check): int => (int) $storage->getQuery()->accessCheck($access_check)->count()->execute();

    $this->setCurrentUser($this->createUser(['view customer']));
    $this->assertSame(1, $count(TRUE));
    // Without access checking nothing is filtered.
    $this->assertSame(2, $count(FALSE));

    $this->setCurrentUser($this->createUser(['administer customer']));
    $this->assertSame(2, $count(TRUE));
  }

}
