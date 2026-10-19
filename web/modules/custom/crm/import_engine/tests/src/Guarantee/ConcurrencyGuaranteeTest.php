<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Guarantee;

use Drupal\import_engine\Run\Trigger;
use Drupal\import_engine\Storage\ItemState;
use Drupal\import_engine\Storage\Outcome;
use Drupal\node\Entity\Node;
use Drupal\Tests\import_engine\Kernel\FakeSource;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Several workers, and workers that die: every item is handled exactly once.
 */
#[Group('guarantee')]
#[RunTestsInSeparateProcesses]
class ConcurrencyGuaranteeTest extends GuaranteeTestBase {

  /**
   * Workers that take turns, with different batches, never share an item.
   */
  public function testWorkersTakingTurnsHandleEachItemOnce(): void {
    $definition = $this->saveImport();
    $run = $this->starter->start($definition, Trigger::Drush);
    $this->stage->extract($run, $definition, new FakeSource($this->pagesOfItems($this->accountItems(1, 60), 15)));

    $claimed = [];
    $now = 1000;
    $guard = 0;
    while ($guard++ < 100) {
      // All the workers hold their claims at the same time, then finish.
      $held = [];
      foreach (['a' => 4, 'b' => 7, 'c' => 1] as $worker => $batch) {
        $held[$worker] = $this->items->claim($worker, $batch, 600, now: $now);
      }
      $ids = array_merge(...array_map(static fn (array $items): array => array_map(static fn ($item): int => $item->id, $items), array_values($held)));
      $this->assertSame($ids, array_values(array_unique($ids)), 'Claims held at the same time are disjoint.');
      $round = count($ids);
      foreach ($held as $items) {
        foreach ($items as $item) {
          $claimed[] = $item->id;
          $this->items->complete($item, Outcome::Created, NULL, $now);
        }
      }
      if ($round === 0) {
        break;
      }
    }

    $this->assertCount(60, $claimed);
    $this->assertSame($claimed, array_values(array_unique($claimed)), 'No item was given to two workers.');
    $this->assertSame(60, $this->items->countByState((int) $run->id())[strtolower(ItemState::Done->name)]);
  }

  /**
   * A worker that dies loses its items to another, and cannot take them back.
   */
  public function testDeadWorkerLosesItsItems(): void {
    $definition = $this->saveImport();
    $run = $this->starter->start($definition, Trigger::Drush);
    $this->stage->extract($run, $definition, new FakeSource($this->pagesOfItems($this->accountItems(1, 10), 10)));

    $mine = $this->items->claim('dies', 10, 10, now: 1000);
    $this->assertCount(10, $mine);
    $this->assertSame([], $this->items->claim('other', 10, 600, now: 1005), 'While the claim lasts the items are taken.');

    // The claim has run out: another worker handles all ten.
    $result = $this->process->process('other', 20, 'default', 600, 1100);
    $this->assertSame(10, $result->created);
    $this->assertCount(10, Node::loadMultiple());

    // The first one wakes up and tries to finish what it had.
    foreach ($mine as $item) {
      $this->assertFalse($this->items->complete($item, Outcome::Created, NULL, 1101), 'Too late: the item is not its any more.');
    }
    $this->assertCount(10, Node::loadMultiple(), 'Nothing was written twice.');
    $this->assertSame(10, $this->items->countByState((int) $run->id())[strtolower(ItemState::Done->name)]);
  }

  /**
   * Handling an item again, later, changes nothing the second time.
   */
  public function testTheSameItemTwiceIsOneAccount(): void {
    $definition = $this->saveImport();
    $items = $this->accountItems(1, 5);
    $this->runToEnd($definition, new FakeSource([$items]));
    $before = $this->accountsOnSite();

    // The very same run again, so every item is processed a second time.
    $this->runToEnd($definition, new FakeSource([$items]), TRUE);
    $this->runToEnd($definition, new FakeSource([$items]), TRUE);

    $this->assertSame($before, $this->accountsOnSite());
  }

}
