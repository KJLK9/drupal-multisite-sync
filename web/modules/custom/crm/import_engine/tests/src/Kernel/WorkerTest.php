<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Kernel;

use Drupal\import_engine\Drive\RunBudget;
use Drupal\import_engine\Drive\Worker;
use Drupal\import_engine\Drive\WorkerResult;
use Drupal\import_engine\Run\RunStatus;
use Drupal\node\Entity\Node;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the worker of a pool, and how the process stage stops cleanly.
 */
#[Group('import_engine')]
#[RunTestsInSeparateProcesses]
class WorkerTest extends NodeTestBase {

  /**
   * The worker.
   */
  protected Worker $worker;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->worker = $this->container->get('import_engine.worker');
  }

  /**
   * A worker handles the queue and finishes the run, then stops when idle.
   */
  public function testWorksUntilIdle(): void {
    $run = $this->extractRows($this->pagesOf(1, 5)[0]);

    $result = $this->worker->work(new RunBudget(), 'w', 'default', 2, TRUE);

    $this->assertSame(5, $result->items);
    $this->assertSame(1, $result->finished);
    $this->assertSame(WorkerResult::IDLE, $result->reason);
    $this->assertCount(5, Node::loadMultiple());
    $this->assertSame(RunStatus::Completed, $this->reload($run)->getStatus());
  }

  /**
   * A worker only takes the items of its own pool.
   */
  public function testPoolsAreSeparate(): void {
    $this->extractRows($this->pagesOf(1, 3)[0], ['pool' => 'heavy']);

    $other = $this->worker->work(new RunBudget(), 'w', 'default', 50, TRUE);
    $this->assertSame(0, $other->items);
    $this->assertCount(0, Node::loadMultiple());

    $heavy = $this->worker->work(new RunBudget(), 'w', 'heavy', 50, TRUE);
    $this->assertSame(3, $heavy->items);
  }

  /**
   * Without once a worker waits for new items until the budget is spent.
   */
  public function testWaitsUntilStopped(): void {
    $budget = new RunBudget();
    $waits = 0;

    $result = $this->worker->work($budget, 'w', 'default', 50, FALSE, static function () use ($budget, &$waits): void {
      $waits++;
      if ($waits === 2) {
        $budget->stop();
      }
    });

    $this->assertSame(2, $waits);
    $this->assertSame(0, $result->items);
    $this->assertSame(WorkerResult::BUDGET, $result->reason);
  }

  /**
   * A spent budget means the worker does not claim anything.
   */
  public function testSpentBudgetClaimsNothing(): void {
    $this->extractRows($this->pagesOf(1, 3)[0]);

    $result = $this->worker->work(new RunBudget(1), 'w', 'default', 50, TRUE);

    $this->assertSame(0, $result->items);
    $this->assertSame(WorkerResult::BUDGET, $result->reason);
    $this->assertSame(3, $this->items->countByState(1)['pending']);
  }

  /**
   * Items claimed but not started when a stop comes are given back at once.
   */
  public function testStopGivesBackClaimedItems(): void {
    $run = $this->extractRows($this->pagesOf(1, 4)[0]);
    $calls = 0;

    $result = $this->process->process('w', 10, 'default', 600, 1000, NULL, static function () use (&$calls): bool {
      // Let one item through, then stop.
      return ++$calls > 1;
    });

    $this->assertSame(4, $result->claimed);
    $this->assertSame(1, $result->created);
    $states = $this->items->countByState((int) $run->id());
    $this->assertSame(0, $states['processing']);
    $this->assertSame(3, $states['retrying']);
    // They are due at once and the attempt was not counted.
    $again = $this->process->process('w', 10, 'default', 600, 1000);
    $this->assertSame(3, $again->created);
    $this->assertCount(4, Node::loadMultiple());
  }

}
