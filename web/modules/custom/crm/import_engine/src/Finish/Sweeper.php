<?php

declare(strict_types=1);

namespace Drupal\import_engine\Finish;

use Drupal\import_engine\DeletePolicy;
use Drupal\import_engine\ImportDefinitionInterface;
use Drupal\import_engine\Storage\EventLog;
use Drupal\import_engine\Storage\EventType;
use Drupal\import_engine\Storage\MappingStore;
use Drupal\import_engine\Target\TargetInterface;

/**
 * Handles what disappeared from the source, after a complete extraction.
 *
 * Every item that a complete run did not see (reading it, or skipping its page
 * because it was unchanged) is gone from the source. What happens to its
 * target follows the delete policy of the import: unpublish (the item is
 * marked gone, and shown again when it returns), delete (the mapping is
 * forgotten too) or ignore.
 *
 * Safeguard: when more than the threshold of the known items would go in one
 * go, nothing is done. A source that suddenly returns an empty or half list
 * must not be able to wipe the target.
 */
final class Sweeper {

  /**
   * The number of items handled per query.
   */
  private const BATCH = 200;

  /**
   * Constructs the sweeper.
   */
  public function __construct(
    private readonly MappingStore $mapping,
    private readonly EventLog $events,
  ) {
  }

  /**
   * Sweeps the items the run did not see.
   *
   * @param int $runId
   *   The run: items last seen before it are gone.
   * @param \Drupal\import_engine\ImportDefinitionInterface $definition
   *   The import.
   * @param \Drupal\import_engine\Target\TargetInterface $target
   *   The target.
   * @param callable|null $heartbeat
   *   Called after every batch; returns FALSE to stop, for example when a lock
   *   was lost.
   * @param int|null $now
   *   The time, for tests.
   */
  public function sweep(int $runId, ImportDefinitionInterface $definition, TargetInterface $target, ?callable $heartbeat = NULL, ?int $now = NULL): SweepResult {
    $policy = $definition->getDeletePolicy();
    $definition_id = (string) $definition->id();
    if ($policy === DeletePolicy::Ignore) {
      return new SweepResult();
    }
    if ($policy === DeletePolicy::Unpublish && !$target->supportsUnpublish()) {
      return new SweepResult(message: 'The target cannot unpublish, so what left the source was left as it is.');
    }

    $missing = $this->mapping->countNotSeenSince($definition_id, $runId);
    if ($missing === 0) {
      return new SweepResult();
    }
    $threshold = $definition->getDeleteThresholdPercent();
    $active = $this->mapping->countActive($definition_id);
    if ($threshold > 0 && $missing * 100 > $threshold * $active) {
      return new SweepResult(
        message: sprintf('Sweep skipped: %d of %d known items (%d%%) are missing from the source, more than the %d%% allowed.', $missing, $active, intdiv($missing * 100, max(1, $active)), $threshold),
        problem: TRUE,
      );
    }

    $swept = 0;
    $failed = 0;
    while (($records = $this->mapping->notSeenSince($definition_id, $runId, self::BATCH)) !== []) {
      $progress = 0;
      foreach ($records as $record) {
        try {
          $handled = $this->handle($policy, $target, $record->targetId);
        }
        catch (\Throwable $exception) {
          $failed++;
          $this->events->record($runId, $definition_id, EventType::Failed, $record->key, $record->targetType . ':' . $record->targetId, 'Sweep failed: ' . $exception->getMessage(), $now);
          continue;
        }
        if ($policy === DeletePolicy::Delete) {
          $this->mapping->forget($definition_id, $record->key);
        }
        else {
          $this->mapping->markGone($definition_id, $record->key, $now);
        }
        $this->events->record($runId, $definition_id, $policy === DeletePolicy::Delete ? EventType::Deleted : EventType::Unpublished, $record->key, $record->targetType . ':' . $record->targetId, $handled ? NULL : 'The target was already gone or hidden.', $now);
        $progress++;
        $swept++;
      }
      // A batch in which nothing worked would come back forever.
      if ($progress === 0 || ($heartbeat !== NULL && $heartbeat() === FALSE)) {
        break;
      }
    }
    $message = $failed > 0 ? sprintf('%d items could not be swept and are tried again next run.', $failed) : NULL;
    return new SweepResult($swept, $failed, $message, $failed > 0);
  }

  /**
   * Unpublishes or deletes one target; returns whether it changed anything.
   */
  private function handle(DeletePolicy $policy, TargetInterface $target, ?string $targetId): bool {
    if ($targetId === NULL) {
      return FALSE;
    }
    return $policy === DeletePolicy::Delete ? $target->delete($targetId) : $target->unpublish($targetId);
  }

}
