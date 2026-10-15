<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine_ui\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\import_engine_ui\Lock\WizardLock;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the lock that lets one person at a time edit an import.
 */
#[Group('import_engine_ui')]
#[RunTestsInSeparateProcesses]
class WizardLockTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'import_engine', 'import_engine_ui'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
  }

  /**
   * The time the lock sees, which a test moves.
   */
  protected int $now = 100000;

  /**
   * Builds a lock that reads the time from the test.
   */
  protected function lock(): WizardLock {
    $clock = new class($this) implements TimeInterface {

      /**
       * Constructs the clock.
       */
      public function __construct(private readonly WizardLockTest $test) {
      }

      /**
       * {@inheritdoc}
       */
      public function getRequestTime(): int {
        return $this->test->now();
      }

      /**
       * {@inheritdoc}
       */
      public function getRequestMicroTime(): float {
        return (float) $this->test->now();
      }

      /**
       * {@inheritdoc}
       */
      public function getCurrentTime(): int {
        return $this->test->now();
      }

      /**
       * {@inheritdoc}
       */
      public function getCurrentMicroTime(): float {
        return (float) $this->test->now();
      }

    };
    return new WizardLock($this->container->get('keyvalue.expirable'), $clock);
  }

  /**
   * The time the lock sees.
   */
  public function now(): int {
    return $this->now;
  }

  /**
   * The first person gets the lock, the second is told who has it.
   */
  public function testOnePersonAtOnce(): void {
    $lock = $this->lock();
    $alice = $this->createUser([], 'alice');
    $bob = $this->createUser([], 'bob');

    $this->assertNull($lock->acquire('accounts', $alice));
    $held = $lock->acquire('accounts', $bob);

    $this->assertNotNull($held);
    $this->assertSame('alice', $held['name']);
    $this->assertSame((int) $alice->id(), $held['uid']);
    $this->assertSame($this->now, $held['since']);
    // Another import is free.
    $this->assertNull($lock->acquire('items', $bob));
  }

  /**
   * The person who has the lock keeps it, also in a second tab.
   */
  public function testHolderKeepsAndRenewsTheLock(): void {
    $lock = $this->lock();
    $alice = $this->createUser([], 'alice');
    $lock->acquire('accounts', $alice);

    $this->now += 600;
    $this->assertNull($lock->acquire('accounts', $alice), 'The same person again is not locked out.');
    $held = $lock->holder('accounts');

    $this->assertNotNull($held);
    $this->assertSame($this->now + WizardLock::SECONDS, $held['until'], 'A step renews it.');
    $this->assertSame(100000, $held['since'], 'Since is when it was first taken.');
    // 1200 seconds after the start: it would have run out without the step.
    $this->now += 600;
    $this->assertNotNull($lock->holder('accounts'));
  }

  /**
   * Without a step for 15 minutes the lock is gone.
   */
  public function testLockRunsOut(): void {
    $lock = $this->lock();
    $alice = $this->createUser([], 'alice');
    $bob = $this->createUser([], 'bob');
    $lock->acquire('accounts', $alice);

    $this->now += WizardLock::SECONDS - 1;
    $this->assertNotNull($lock->acquire('accounts', $bob));
    $this->now += 1;

    $this->assertNull($lock->holder('accounts'));
    $this->assertNull($lock->acquire('accounts', $bob));
    $now_held = $this->lock()->holder('accounts');
    $this->assertSame('bob', $now_held['name'] ?? NULL);
  }

  /**
   * Only the holder gives the lock up.
   */
  public function testOnlyTheHolderReleases(): void {
    $lock = $this->lock();
    $alice = $this->createUser([], 'alice');
    $bob = $this->createUser([], 'bob');
    $lock->acquire('accounts', $alice);

    $lock->release('accounts', $bob);
    $this->assertSame('alice', $lock->holder('accounts')['name'] ?? NULL);

    $lock->release('accounts', $alice);
    $this->assertNull($lock->holder('accounts'));
    $this->assertNull($lock->acquire('accounts', $bob));
  }

  /**
   * Taking over gives the lock to the one who takes it.
   */
  public function testTakeOver(): void {
    $lock = $this->lock();
    $alice = $this->createUser([], 'alice');
    $bob = $this->createUser([], 'bob');
    $lock->acquire('accounts', $alice);

    $lock->takeOver('accounts', $bob);

    $this->assertSame('bob', $lock->holder('accounts')['name'] ?? NULL);
    $this->assertNotNull($lock->acquire('accounts', $alice), 'Alice is now the one who is locked out.');
  }

}
