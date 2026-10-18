<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Guarantee;

use Drupal\import_engine\Drive\DriveStatus;
use Drupal\import_engine\Drive\RunBudget;
use Drupal\import_engine\Run\RunStatus;
use Drupal\import_engine\Run\Trigger;
use Drupal\node\Entity\Node;
use Drupal\Tests\import_engine\Kernel\FakeSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The life of a run: one at a time, forward only, and a cancelled run is quiet.
 */
#[Group('guarantee')]
#[RunTestsInSeparateProcesses]
class LifecycleGuaranteeTest extends GuaranteeTestBase {

  /**
   * A run cannot be started while another of the same import is not over.
   */
  public function testOneRunAtOnce(): void {
    $definition = $this->saveImport();
    $run = $this->starter->start($definition, Trigger::Drush);

    $this->expectException(\RuntimeException::class);
    try {
      $this->starter->start($definition, Trigger::Cron);
    }
    finally {
      // The first one is untouched, and can be driven to its end.
      $this->driver->drive($this->reload($run), $definition, new RunBudget(), 'w', 50, new FakeSource([$this->accountItems(1, 3)]));
      $this->assertSame(RunStatus::Completed, $this->reload($run)->getStatus());
      $another = $this->starter->start($definition, Trigger::Cron);
      $this->assertSame(RunStatus::Queued, $another->getStatus(), 'Once it is over, another can start.');
    }
  }

  /**
   * Where a run can be cancelled from.
   *
   * @return array<string, array{string}>
   *   The moment.
   */
  public static function moments(): array {
    return [
      'before anything was read' => ['queued'],
      'in the middle of the extraction' => ['extracting'],
      'with the items waiting' => ['processing'],
    ];
  }

  /**
   * A cancelled run leaves the site alone, skips what waits, sweeps nothing.
   */
  #[DataProvider('moments')]
  public function testCancelledRunIsQuiet(string $moment): void {
    $definition = $this->saveImport(['delete_threshold_percent' => 0]);
    $this->runToEnd($definition, new FakeSource($this->pagesOfItems($this->accountItems(1, 20), 5)));
    $before = $this->accountsOnSite();
    // The second run has fewer items: a normal one would unpublish five.
    $source = new FakeSource($this->pagesOfItems($this->accountItems(1, 15), 5));
    $run = $this->starter->start($definition, Trigger::Drush);
    if ($moment !== 'queued') {
      $this->driver->drive($run, $definition, new RunBudget(1), 'w', 50, $source);
    }
    if ($moment === 'processing') {
      while ($this->reload($run)->getStatus() === RunStatus::Extracting) {
        $this->driver->drive($this->reload($run), $definition, new RunBudget(1), 'w', 50, $source);
      }
    }

    $this->container->get('import_engine.run_manager')->cancel($this->reload($run));

    $cancelled = $this->reload($run);
    $this->assertSame(RunStatus::Cancelled, $cancelled->getStatus());
    $this->assertSame($before, $this->accountsOnSite(), 'Nothing on the site was touched.');
    $this->assertSame(0, $cancelled->getCounters()['deleted']);
    $counts = $this->items->countByState((int) $run->id());
    $this->assertSame(0, $counts['pending'] + $counts['processing'], 'Nothing is left waiting.');
    // A cancelled run cannot be driven on.
    $result = $this->driver->drive($cancelled, $definition, new RunBudget(), 'w', 50, $source);
    $this->assertNotSame(DriveStatus::OutOfBudget, $result->status);
    $this->assertSame(RunStatus::Cancelled, $this->reload($run)->getStatus());
  }

  /**
   * A run only moves forward: from a final state there is no way back.
   */
  public function testStatesOnlyMoveForward(): void {
    foreach (RunStatus::cases() as $from) {
      foreach (RunStatus::cases() as $to) {
        if ($from->isFinal()) {
          $this->assertFalse($from->canMoveTo($to), $from->value . ' is final.');
        }
        if ($to === RunStatus::Queued) {
          $this->assertFalse($from->canMoveTo($to), 'Nothing moves back to queued.');
        }
      }
    }
    // The whole way, in order.
    $this->assertTrue(RunStatus::Queued->canMoveTo(RunStatus::Extracting));
    $this->assertTrue(RunStatus::Extracting->canMoveTo(RunStatus::Processing));
    $this->assertTrue(RunStatus::Processing->canMoveTo(RunStatus::Finishing));
    $this->assertTrue(RunStatus::Finishing->canMoveTo(RunStatus::Completed));
    $this->assertFalse(RunStatus::Queued->canMoveTo(RunStatus::Completed), 'Nothing is skipped.');
  }

  /**
   * A cancelled import can be run again from the start, to the right result.
   */
  public function testNextRunAfterCancelIsComplete(): void {
    $definition = $this->saveImport();
    $items = $this->accountItems(1, 20);
    $run = $this->starter->start($definition, Trigger::Drush);
    $this->driver->drive($run, $definition, new RunBudget(1), 'w', 50, new FakeSource($this->pagesOfItems($items, 5)));
    $this->container->get('import_engine.run_manager')->cancel($this->reload($run));

    $next = $this->runToEnd($definition, new FakeSource($this->pagesOfItems($items, 5)));

    $this->assertSame(RunStatus::Completed, $next->getStatus());
    $this->assertCount(20, Node::loadMultiple());
  }

}
