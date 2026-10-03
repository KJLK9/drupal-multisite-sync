<?php

declare(strict_types=1);

namespace Drupal\Tests\customers\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\customers\Entity\Customer;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the hook implementations of the customer module.
 */
#[Group('customers')]
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
class CustomerHooksTest extends KernelTestBase {

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
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->installEntitySchema('customer');
  }

  /**
   * The module registers its theme hook.
   */
  public function testThemeHookIsRegistered(): void {
    $registry = $this->container->get('theme.registry')->get();
    $this->assertArrayHasKey('customer', $registry);
  }

  /**
   * Deleting a user deletes the customers they own.
   */
  public function testUserDeleteRemovesCustomers(): void {
    $account = $this->createUser();
    $customer = Customer::create(['label' => 'Test', 'uid' => $account->id()]);
    $customer->save();

    $account->delete();

    $this->assertNull(Customer::load($customer->id()));
  }

  /**
   * Blocking a user unpublishes their customers.
   */
  public function testUserCancelUnpublishesCustomers(): void {
    $account = $this->createUser();
    $customer = Customer::create(['label' => 'Test', 'uid' => $account->id(), 'status' => TRUE]);
    $customer->save();

    $this->container->get('module_handler')->invokeAll('user_cancel', [[], $account, 'user_cancel_block_unpublish']);

    $reloaded = Customer::load($customer->id());
    $this->assertNotNull($reloaded);
    $this->assertFalse((bool) $reloaded->get('status')->value);
  }

}
