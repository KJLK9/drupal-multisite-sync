<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Kernel;

use Drupal\import_engine\Entity\ImportRun;
use Drupal\import_engine\Run\RunStatus;
use Drupal\import_engine\Storage\EventType;
use Drupal\import_engine\Storage\Outcome;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that what has outlived its retention is removed, and only that.
 */
#[Group('import_engine')]
#[RunTestsInSeparateProcesses]
class RetentionTest extends StorageTestBase {

  /**
   * The day in seconds.
   */
  private const DAY = 86400;

  /**
   * Creates a finished run at a time.
   */
  protected function finishedRun(int $finishedAt): ImportRun {
    $run = $this->createRun();
    $run->transitionTo(RunStatus::Failed, $finishedAt)->save();
    return $run;
  }

  /**
   * The defaults keep the work queue short and the history long.
   */
  public function testDefaultSettings(): void {
    $settings = $this->config('import_engine.settings');

    $this->assertSame(7, $settings->get('retention_items_days'));
    $this->assertSame(90, $settings->get('retention_dead_days'));
    $this->assertSame(365, $settings->get('retention_events_days'));
    $this->assertSame(90, $settings->get('retention_runs_days'));
  }

  /**
   * Old rows are removed, recent rows and the dead letter queue are kept.
   */
  public function testPurgeRemovesOnlyWhatIsOld(): void {
    $now = 1_000 * self::DAY;
    // Items: one done long ago, one done recently, one dead for 100 days.
    $this->items->enqueue(1, 'default', $this->makeItems(3), now: $now - 100 * self::DAY);
    $claimed = $this->items->claim('w', 3, 60, now: $now - 100 * self::DAY + 1);
    $this->items->complete($claimed[0], Outcome::Created, now: $now - 10 * self::DAY);
    $this->items->complete($claimed[1], Outcome::Created, now: $now - 1 * self::DAY);
    $this->items->fail($claimed[2], 'bad', TRUE, $now - 100 * self::DAY);
    // Events: one of two years ago, one of last month.
    $this->events->record(1, 'customers', EventType::Created, 'old', NULL, NULL, $now - 700 * self::DAY);
    $this->events->record(1, 'customers', EventType::Created, 'new', NULL, NULL, $now - 30 * self::DAY);
    // Runs: finished long ago and recently.
    $old = $this->finishedRun($now - 200 * self::DAY);
    $recent = $this->finishedRun($now - 5 * self::DAY);

    $removed = $this->container->get('import_engine.retention_purger')->purge(now: $now);

    $this->assertSame(['items' => 1, 'dead_items' => 1, 'events' => 1, 'runs' => 1], $removed);
    $this->assertSame(1, $this->rows('import_item'));
    $this->assertSame(['new'], array_column($this->events->forRun(1), 'key'));
    $this->assertNull(ImportRun::load($old->id()));
    $this->assertNotNull(ImportRun::load($recent->id()));
  }

  /**
   * Zero keeps for ever.
   */
  public function testZeroKeepsForEver(): void {
    $this->config('import_engine.settings')
      ->set('retention_items_days', 0)
      ->set('retention_dead_days', 0)
      ->set('retention_events_days', 0)
      ->set('retention_runs_days', 0)
      ->save();
    $now = 1_000 * self::DAY;
    $this->events->record(1, 'customers', EventType::Created, 'ancient', NULL, NULL, 1);
    $this->items->enqueue(1, 'default', $this->makeItems(1), now: 1);
    $item = $this->items->claim('w', 1, 60, now: 2)[0];
    $this->items->complete($item, Outcome::Created, now: 3);
    $this->finishedRun(5);

    $removed = $this->container->get('import_engine.retention_purger')->purge(now: $now);

    $this->assertSame(['items' => 0, 'dead_items' => 0, 'events' => 0, 'runs' => 0], $removed);
    $this->assertSame(1, $this->rows('import_event'));
    $this->assertSame(1, $this->rows('import_item'));
    $this->assertSame(1, $this->rows('import_run'));
  }

  /**
   * One call removes a bounded amount; the next continues.
   */
  public function testPurgeIsBoundedPerCall(): void {
    $now = 1_000 * self::DAY;
    for ($n = 0; $n < 25; $n++) {
      $this->events->record(1, 'customers', EventType::Created, "k$n", NULL, NULL, 1);
    }
    $purger = $this->container->get('import_engine.retention_purger');

    // 10 per batch, at most 2 batches: 20 of 25.
    $this->assertSame(20, $purger->purge(10, 2, $now)['events']);
    $this->assertSame(5, $this->rows('import_event'));
    $this->assertSame(5, $purger->purge(10, 2, $now)['events']);
    $this->assertSame(0, $this->rows('import_event'));
  }

  /**
   * The settings are validated.
   */
  public function testSettingsAreValidated(): void {
    $typed = $this->container->get('config.typed')->createFromNameAndData('import_engine.settings', [
      'retention_items_days' => -1,
      'retention_dead_days' => 90,
      'retention_events_days' => 99999,
      'retention_runs_days' => 90,
    ]);

    $paths = [];
    foreach ($typed->validate() as $violation) {
      $paths[] = $violation->getPropertyPath();
    }
    $this->assertEqualsCanonicalizing(['retention_items_days', 'retention_events_days'], $paths);
  }

}
