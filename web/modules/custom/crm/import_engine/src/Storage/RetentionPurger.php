<?php

declare(strict_types=1);

namespace Drupal\import_engine\Storage;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Removes what has outlived its retention, in batches.
 *
 * Retention is set in import_engine.settings, in days; 0 keeps for ever. The
 * work queue is short-lived by design. The event log is the long history. A
 * finished run is small and is kept for a long time.
 */
final class RetentionPurger {

  use QueryHelpers;

  /**
   * Constructs the purger.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ItemStorage $items,
    private readonly EventLog $events,
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
  ) {
  }

  /**
   * Removes everything older than its retention.
   *
   * @param int $batchSize
   *   The most rows per statement.
   * @param int $maxBatches
   *   The most batches per kind of data, to bound the time one call takes; a
   *   later call continues where this one stopped.
   * @param int|null $now
   *   The time, for tests.
   *
   * @return array{items: int, dead_items: int, events: int, runs: int}
   *   How many of each were removed.
   */
  public function purge(int $batchSize = 5000, int $maxBatches = 20, ?int $now = NULL): array {
    $now ??= $this->time->getRequestTime();
    $settings = $this->configFactory->get('import_engine.settings');
    $removed = ['items' => 0, 'dead_items' => 0, 'events' => 0, 'runs' => 0];

    $items_days = (int) $settings->get('retention_items_days');
    if ($items_days > 0) {
      $removed['items'] = $this->batches($maxBatches, $batchSize, fn () => $this->items->purge(ItemState::Done, $now - $items_days * 86400, $batchSize));
    }
    $dead_days = (int) $settings->get('retention_dead_days');
    if ($dead_days > 0) {
      $removed['dead_items'] = $this->batches($maxBatches, $batchSize, fn () => $this->items->purge(ItemState::Dead, $now - $dead_days * 86400, $batchSize));
    }
    $events_days = (int) $settings->get('retention_events_days');
    if ($events_days > 0) {
      $removed['events'] = $this->batches($maxBatches, $batchSize, fn () => $this->events->purge($now - $events_days * 86400, $batchSize));
    }
    $runs_days = (int) $settings->get('retention_runs_days');
    if ($runs_days > 0) {
      $removed['runs'] = $this->purgeRuns($now - $runs_days * 86400, $batchSize, $maxBatches);
    }
    return $removed;
  }

  /**
   * Calls a purge step until it removes less than a batch, or the limit.
   *
   * @param int $maxBatches
   *   The most times the step is called.
   * @param int $batchSize
   *   The size of a batch; a step that removes fewer rows is the last one.
   * @param callable(): int $step
   *   Removes one batch and returns how many rows it removed.
   */
  private function batches(int $maxBatches, int $batchSize, callable $step): int {
    $total = 0;
    for ($batch = 0; $batch < $maxBatches; $batch++) {
      $count = $step();
      $total += $count;
      if ($count < $batchSize) {
        break;
      }
    }
    return $total;
  }

  /**
   * Deletes finished runs older than a time, in batches.
   */
  private function purgeRuns(int $olderThan, int $batchSize, int $maxBatches): int {
    $storage = $this->entityTypeManager->getStorage('import_run');
    // Runs are few, so a smaller batch keeps entity loading cheap.
    $size = min($batchSize, 200);
    return $this->batches($maxBatches, $size, function () use ($storage, $olderThan, $size): int {
      $ids = $this->column($this->database->select('import_run', 'r')
        ->fields('r', ['id'])
        ->condition('finished', 0, '>')
        ->condition('finished', $olderThan, '<')
        ->range(0, $size));
      if ($ids === []) {
        return 0;
      }
      $storage->delete($storage->loadMultiple($ids));
      return count($ids);
    });
  }

}
