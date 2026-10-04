<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Kernel;

use Drupal\import_engine\Run\RunAlreadyActiveException;
use Drupal\import_engine\Run\RunStatus;
use Drupal\import_engine\Run\Trigger;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests starting runs: one active run per import.
 */
#[Group('import_engine')]
#[RunTestsInSeparateProcesses]
class RunStarterTest extends ExtractTestBase {

  /**
   * A run is created queued, with who and how it was started.
   */
  public function testStartCreatesQueuedRun(): void {
    $run = $this->starter->start($this->definition(), Trigger::Ui, 7, TRUE);

    $this->assertSame(RunStatus::Queued, $run->getStatus());
    $this->assertSame('customers', $run->getDefinitionId());
    $this->assertSame(Trigger::Ui, $run->getTrigger());
    $this->assertTrue($run->isFullRun());
    $this->assertSame(7, (int) $run->get('uid')->target_id);
    $this->assertNotNull($run->id());
  }

  /**
   * A second run of the same import is refused while one is active.
   */
  public function testAnImportHasOneActiveRun(): void {
    $first = $this->starter->start($this->definition(), Trigger::Cron);

    try {
      $this->starter->start($this->definition(), Trigger::Drush);
      $this->fail('Expected a RunAlreadyActiveException.');
    }
    catch (RunAlreadyActiveException $exception) {
      $this->assertSame((int) $first->id(), $exception->runId);
      $this->assertStringContainsString('still active', $exception->getMessage());
    }
    $this->assertSame((int) $first->id(), $this->starter->activeRunId('customers'));
  }

  /**
   * Another import is not affected, and a finished run does not block.
   */
  public function testOtherImportsAndFinishedRuns(): void {
    $first = $this->starter->start($this->definition(), Trigger::Cron);

    $other = $this->starter->start($this->definition(['id' => 'orders', 'label' => 'Orders']), Trigger::Cron);
    $this->assertNotSame($first->id(), $other->id());

    $first->transitionTo(RunStatus::Cancelled)->save();
    $this->assertNull($this->starter->activeRunId('customers'));
    $again = $this->starter->start($this->definition(), Trigger::Cron);
    $this->assertNotSame($first->id(), $again->id());
  }

  /**
   * Every final status frees the import.
   */
  public function testEveryFinalStatusFreesTheImport(): void {
    foreach ([RunStatus::Failed, RunStatus::Cancelled] as $status) {
      $run = $this->starter->start($this->definition(), Trigger::Cron);
      $this->assertSame((int) $run->id(), $this->starter->activeRunId('customers'));
      $run->transitionTo($status)->save();
      $this->assertNull($this->starter->activeRunId('customers'));
    }
  }

}
