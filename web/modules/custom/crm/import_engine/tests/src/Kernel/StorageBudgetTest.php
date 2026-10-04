<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Kernel;

use Drupal\import_engine\Run\RunStatus;
use Drupal\import_engine\Storage\EventType;
use Drupal\import_engine\Storage\Outcome;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Enforces the storage budget: tables grow with the dataset and with changes.
 *
 * An import that reads a small dataset must not make a table grow by a large
 * amount every day. Several runs over the same dataset are simulated here, the
 * way the stages will write to the tables, and the row counts are asserted.
 */
#[Group('import_engine')]
#[RunTestsInSeparateProcesses]
class StorageBudgetTest extends StorageTestBase {

  /**
   * The size of the dataset.
   */
  private const ITEMS = 250;

  /**
   * The number of items on a page.
   */
  private const PAGE_SIZE = 50;

  /**
   * The seconds in a day.
   */
  private const DAY = 86400;

  /**
   * Simulates a run: reads every page, handles every item, logs the changes.
   *
   * @param int $changed
   *   How many items changed since the previous run.
   * @param int $now
   *   When the run happens.
   * @param bool $first
   *   Whether it is the first run: then every item is created.
   */
  protected function simulateRun(int $changed, int $now, bool $first = FALSE): int {
    $run = $this->createRun();
    $run->transitionTo(RunStatus::Extracting, $now)->save();

    $keys = array_map(static fn (int $n): string => "k$n", range(1, self::ITEMS));
    foreach (array_chunk($keys, self::PAGE_SIZE) as $position => $page_keys) {
      // One page record per position, overwritten by every run.
      $this->pages->put('customers', $position, hash('xxh128', $page_keys[0] . $now), $page_keys, (int) $run->id(), $now);
      $this->items->enqueue((int) $run->id(), 'default', array_map(
        static fn (string $key): array => ['key' => $key, 'payload' => ['id' => $key]],
        $page_keys,
      ), now: $now);
    }

    $number = 0;
    while ($claimed = $this->items->claim('w', 100, 60, now: $now)) {
      foreach ($claimed as $item) {
        $number++;
        $is_change = $first || $number <= $changed;
        $this->items->complete($item, $is_change ? ($first ? Outcome::Created : Outcome::Updated) : Outcome::Unchanged, NULL, $now);
        if ($is_change) {
          $this->events->record((int) $run->id(), 'customers', $first ? EventType::Created : EventType::Updated, $item->key, NULL, NULL, $now);
        }
      }
    }

    $run->transitionTo(RunStatus::Processing, $now)->transitionTo(RunStatus::Finishing, $now)->transitionTo(RunStatus::Completed, $now);
    $run->setCounters(['items_extracted' => self::ITEMS])->save();
    return (int) $run->id();
  }

  /**
   * Persistent tables grow with the dataset and the changes, not with runs.
   */
  public function testTablesDoNotGrowWithTheNumberOfRuns(): void {
    $start = 1_000 * self::DAY;
    $pages = (int) ceil(self::ITEMS / self::PAGE_SIZE);

    // First run: everything is created.
    $this->simulateRun(0, $start, TRUE);
    $this->assertSame($pages, $this->rows('import_page'));
    $this->assertSame(self::ITEMS, $this->rows('import_event'));

    // Five more days: 10 of 250 items change each day.
    for ($day = 1; $day <= 5; $day++) {
      $this->simulateRun(10, $start + $day * self::DAY);
    }
    // Pages: still one record per position. Events: only the changes.
    $this->assertSame($pages, $this->rows('import_page'));
    $this->assertSame(self::ITEMS + 5 * 10, $this->rows('import_event'));
    $this->assertSame(6, $this->rows('import_run'));
    // The queue holds one row per item per run, until it is purged.
    $this->assertSame(6 * self::ITEMS, $this->rows('import_item'));

    // Five days without any change add nothing but a run record.
    for ($day = 6; $day <= 10; $day++) {
      $this->simulateRun(0, $start + $day * self::DAY);
    }
    $this->assertSame($pages, $this->rows('import_page'));
    $this->assertSame(self::ITEMS + 5 * 10, $this->rows('import_event'));
    $this->assertSame(11, $this->rows('import_run'));
  }

  /**
   * The work queue is emptied by the retention, so it does not accumulate.
   */
  public function testTheWorkQueueIsPurged(): void {
    $start = 1_000 * self::DAY;
    for ($day = 0; $day < 10; $day++) {
      $this->simulateRun($day === 0 ? 0 : 10, $start + $day * self::DAY, $day === 0);
      // Retention for items is 7 days: purge as the cron would, every day.
      $this->container->get('import_engine.retention_purger')->purge(now: $start + $day * self::DAY);
    }

    // At most the runs of the last 7 days (plus today) are still in the queue.
    $this->assertLessThanOrEqual(8 * self::ITEMS, $this->rows('import_item'));
    $this->assertGreaterThan(0, $this->rows('import_item'));
    // The history is not touched by it.
    $this->assertSame(self::ITEMS + 9 * 10, $this->rows('import_event'));
  }

  /**
   * Done items are light: no payload is left once they are handled.
   */
  public function testDoneItemsHoldNoPayload(): void {
    $this->simulateRun(0, 1_000 * self::DAY, TRUE);

    $with_payload = (int) $this->field($this->container->get('database')->select('import_item', 'i')->isNotNull('payload')->countQuery());

    $this->assertSame(0, $with_payload);
  }

}
