<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Kernel;

use Drupal\Core\Lock\PersistentDatabaseLockBackend;
use Drupal\import_engine\Run\RunBusyException;
use Drupal\import_engine\Run\RunManager;
use Drupal\import_engine\Run\RunStatus;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests cancelling runs and retrying dead items.
 */
#[Group('import_engine')]
#[RunTestsInSeparateProcesses]
class RunManagerTest extends NodeTestBase {

  /**
   * The manager.
   */
  protected RunManager $manager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->manager = $this->container->get('import_engine.run_manager');
  }

  /**
   * Cancelling skips the items that wait and ends the run.
   */
  public function testCancel(): void {
    $run = $this->extractRows($this->pagesOf(1, 3)[0]);

    $skipped = $this->manager->cancel($run);

    $this->assertSame(3, $skipped);
    $reloaded = $this->reload($run);
    $this->assertSame(RunStatus::Cancelled, $reloaded->getStatus());
    $this->assertSame('Cancelled.', $reloaded->getSummary());
    $this->assertNotNull($reloaded->get('finished')->value);
    $this->assertSame(3, $this->items->countByOutcome((int) $run->id())['skipped']);
    // Nothing is left to do, and the import can be started again.
    $this->assertSame(0, $this->process->process('w', 10)->claimed);
    $this->assertNull($this->container->get('import_engine.run_starter')->activeRunId('customers'));
  }

  /**
   * A run that is over cannot be cancelled.
   */
  public function testCancelFinishedRunFails(): void {
    $run = $this->importRows($this->pagesOf(1, 1)[0]);
    $this->finishRun($run);

    $this->expectException(\LogicException::class);
    $this->manager->cancel($this->reload($run));
  }

  /**
   * A run that is being extracted elsewhere cannot be cancelled.
   */
  public function testCancelWhileExtractingElsewhereFails(): void {
    $run = $this->extractRows($this->pagesOf(1, 1)[0]);
    $other = new PersistentDatabaseLockBackend($this->container->get('database'));
    $other->acquire('import_engine:extract:customers', 60);

    try {
      $this->manager->cancel($run);
      $this->fail('Expected a RunBusyException.');
    }
    catch (RunBusyException) {
      $this->assertSame(RunStatus::Processing, $this->reload($run)->getStatus());
    }
  }

  /**
   * Dead items, also of earlier runs, become pending again.
   */
  public function testRetryDead(): void {
    $run = $this->importRows([['id' => 1, 'name' => 'Acme', 'code' => 'A'], ['id' => 2, 'name' => '', 'code' => 'B']]);
    $this->finishRun($run);
    $this->assertSame(1, $this->items->countByState((int) $run->id())['dead']);

    $this->assertSame(1, $this->manager->retryDead('customers'));
    $this->assertSame(0, $this->manager->retryDead('other'));
    $states = $this->items->countByState((int) $run->id());
    $this->assertSame(0, $states['dead']);
    $this->assertSame(1, $states['pending']);

    // A worker takes it up again; it fails again for the same reason.
    $this->assertSame(1, $this->process->process('w', 10)->failed);
  }

  /**
   * The latest run of an import is found.
   */
  public function testLatest(): void {
    $none = $this->manager->latest('customers');
    $this->assertNull($none);
    $first = $this->importRows($this->pagesOf(1, 1)[0]);
    $this->finishRun($first);
    $second = $this->importRows($this->pagesOf(1, 1)[0]);

    $this->assertSame($second->id(), $this->manager->latest('customers')?->id());
  }

}
