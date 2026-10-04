<?php

declare(strict_types=1);

namespace Drupal\import_engine\Process;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\import_engine\Entity\ImportDefinition;
use Drupal\import_engine\ImportDefinitionInterface;
use Drupal\import_engine\Mapper\DependencyNotReadyException;
use Drupal\import_engine\Mapper\MappingException;
use Drupal\import_engine\Page\PageFingerprint;
use Drupal\import_engine\Retry\RetryPolicy;
use Drupal\import_engine\Run\ImportRunInterface;
use Drupal\import_engine\Storage\EventLog;
use Drupal\import_engine\Storage\EventType;
use Drupal\import_engine\Storage\ImportItem;
use Drupal\import_engine\Storage\ItemStorage;
use Drupal\import_engine\Storage\MappingStore;
use Drupal\import_engine\Storage\Outcome;
use Drupal\import_engine\Target\TargetException;

/**
 * Takes items from the work queue, maps them and writes them to the target.
 *
 * A worker claims a number of items of a pool and handles each one:
 * - The item is mapped. If what would be written has the same hash as the last
 *   time, nothing is saved: the item is unchanged.
 * - Otherwise the target is saved (the mapped values are validated first) and
 *   the mapping store remembers which entity the item became.
 * - A failure that retrying cannot fix (a value that cannot be mapped, data
 *   that does not validate, a definition that does not work) ends the item at
 *   once. A failure that may pass (something it refers to is not imported yet,
 *   an unexpected error) is retried later, following the backoff of the import,
 *   until the attempts run out.
 * - An item that ends goes to the dead letter queue with its payload, or, when
 *   the import has no dead letter queue, is done as failed.
 *
 * Only changes and problems are logged as events, never an unchanged item.
 * Finishing runs (sweep, counters, reports) is a separate stage.
 */
final class ProcessStage {

  /**
   * Constructs the stage.
   */
  public function __construct(
    private readonly ItemStorage $items,
    private readonly MappingStore $mapping,
    private readonly EventLog $events,
    private readonly PlanFactory $plans,
    private readonly PageFingerprint $fingerprints,
    private readonly RetryPolicy $retry,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
  ) {
  }

  /**
   * Claims and handles items.
   *
   * @param string $worker
   *   The name of the worker.
   * @param int $limit
   *   The most items to claim.
   * @param string $pool
   *   The worker pool whose items are handled.
   * @param int $leaseSeconds
   *   How long the claim lasts; an item that takes longer is given to another.
   * @param int|null $now
   *   The time, for tests; the current time by default.
   * @param int|null $deadline
   *   A timestamp after which no new item is started; the claimed items that
   *   are left go back after their lease runs out.
   */
  public function process(string $worker, int $limit = 50, string $pool = 'default', int $leaseSeconds = 300, ?int $now = NULL, ?int $deadline = NULL): ProcessResult {
    $now ??= $this->time->getCurrentTime();
    $claimed = $this->items->claim($worker, $limit, $leaseSeconds, $pool, $now);

    $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'retried' => 0, 'failed' => 0, 'lost' => 0];
    $runs = [];
    $cache = ['runs' => [], 'definitions' => [], 'plans' => []];
    foreach ($claimed as $item) {
      if ($deadline !== NULL && $this->time->getCurrentTime() >= $deadline) {
        break;
      }
      $runs[$item->runId] = $item->runId;
      $kind = $this->handle($item, $cache, $now);
      $counts[$kind]++;
    }

    return new ProcessResult(
      count($claimed),
      $counts['created'],
      $counts['updated'],
      $counts['unchanged'],
      $counts['retried'],
      $counts['failed'],
      $counts['lost'],
      array_values($runs),
    );
  }

  /**
   * Handles one claimed item and returns what happened to it.
   *
   * @param \Drupal\import_engine\Storage\ImportItem $item
   *   The claimed item.
   * @param array{runs: array<int, \Drupal\import_engine\Run\ImportRunInterface|null>, definitions: array<string, \Drupal\import_engine\ImportDefinitionInterface|null>, plans: array<string, \Drupal\import_engine\Process\ImportPlan|\Drupal\import_engine\Process\PlanException>} $cache
   *   Runs, definitions and plans loaded so far; updated.
   * @param int $now
   *   The current time.
   *
   * @return string
   *   One of created, updated, unchanged, retried, failed or lost.
   */
  private function handle(ImportItem $item, array &$cache, int $now): string {
    $run = $cache['runs'][$item->runId] ??= $this->loadRun($item->runId);
    $definition = $run === NULL ? NULL : ($cache['definitions'][$run->getDefinitionId()] ??= ImportDefinition::load($run->getDefinitionId()));
    if ($run === NULL || $definition === NULL) {
      return $this->end($item, NULL, 'The run or the import of this item no longer exists.', $now);
    }

    // An item that was claimed more often than allowed keeps failing in a way
    // that stops its worker (it never reports back): give up on it.
    if ($item->attempts > $definition->getMaxAttempts()) {
      return $this->end($item, $definition, sprintf('Gave up: claimed %d times, %d attempts allowed. Last error: %s', $item->attempts, $definition->getMaxAttempts(), $item->error ?? 'none'), $now, (int) $run->id());
    }

    try {
      $plan = $cache['plans'][(string) $definition->id()] ??= $this->plan($definition);
      if ($plan instanceof PlanException) {
        throw $plan;
      }
      return $this->write($item, $run, $definition, $plan, $now);
    }
    catch (MappingException | TargetException | PlanException $exception) {
      // Retrying would fail the same way.
      return $this->end($item, $definition, $exception->getMessage(), $now, (int) $run->id());
    }
    catch (DependencyNotReadyException $exception) {
      return $this->retryOrEnd($item, $definition, $exception->getMessage(), $now, (int) $run->id());
    }
    catch (\Throwable $exception) {
      return $this->retryOrEnd($item, $definition, get_class($exception) . ': ' . $exception->getMessage(), $now, (int) $run->id());
    }
  }

  /**
   * Maps and writes an item; returns created, updated, unchanged or lost.
   */
  private function write(ImportItem $item, ImportRunInterface $run, ImportDefinitionInterface $definition, ImportPlan $plan, int $now): string {
    $definition_id = (string) $definition->id();
    $run_id = (int) $run->id();
    $payload = $item->payload ?? [];

    $values = $plan->map($payload);
    $hash = $this->fingerprints->fingerprint($values);
    $known = $this->mapping->find($definition_id, $item->key);

    if ($known !== NULL && $known->targetId !== NULL && $known->hash === $hash) {
      // The target already holds exactly this. An item that was unpublished
      // because it left the source is the only thing left to undo.
      $restored = $known->gone && $plan->target->publish($known->targetId);
      $this->mapping->record($definition_id, $item->key, (string) $known->targetType, $known->targetId, $hash, $run_id, $restored, $now);
      if ($restored) {
        $this->events->record($run_id, $definition_id, EventType::Updated, $item->key, $known->targetType . ':' . $known->targetId, 'Back in the source: published again.', $now);
        return $this->items->complete($item, Outcome::Updated, $hash, $now) ? 'updated' : 'lost';
      }
      return $this->items->complete($item, Outcome::Unchanged, $hash, $now) ? 'unchanged' : 'lost';
    }

    $result = $plan->target->save($values, $known?->targetId);
    if ($known !== NULL && $known->gone && !array_key_exists('status', $values)) {
      // It left the source, was unpublished and is back: show it again.
      $plan->target->publish($result->id);
    }
    $this->mapping->record($definition_id, $item->key, $result->type, $result->id, $hash, $run_id, TRUE, $now);
    $this->events->record($run_id, $definition_id, $result->created ? EventType::Created : EventType::Updated, $item->key, $result->type . ':' . $result->id, NULL, $now);

    $outcome = $result->created ? Outcome::Created : Outcome::Updated;
    if (!$this->items->complete($item, $outcome, $hash, $now)) {
      // Another worker has the item now; it will find the entity unchanged.
      return 'lost';
    }
    return $result->created ? 'created' : 'updated';
  }

  /**
   * Puts an item back for a later attempt, or ends it when attempts ran out.
   */
  private function retryOrEnd(ImportItem $item, ImportDefinitionInterface $definition, string $message, int $now, int $runId): string {
    $max = $definition->getMaxAttempts();
    if ($item->attempts >= $max) {
      return $this->end($item, $definition, sprintf('Gave up after %d attempts. %s', $item->attempts, $message), $now, $runId);
    }
    $next = $this->retry->nextAttempt($item->attempts, $definition->getBackoff(), $definition->getRetryDelay(), $now);
    return $this->items->retry($item, $message, $next, $now) ? 'retried' : 'lost';
  }

  /**
   * Ends an item that will not be tried again.
   *
   * It goes to the dead letter queue, keeping its payload, or, when the import
   * has none, is done as failed. Either way the event log gets an entry.
   *
   * @param \Drupal\import_engine\Storage\ImportItem $item
   *   The item.
   * @param \Drupal\import_engine\ImportDefinitionInterface|null $definition
   *   The import, or NULL when it no longer exists.
   * @param string $message
   *   Why.
   * @param int $now
   *   The current time.
   * @param int $runId
   *   The run of the item.
   */
  private function end(ImportItem $item, ?ImportDefinitionInterface $definition, string $message, int $now, int $runId = 0): string {
    $dead_letter = $definition === NULL || $definition->isDlqEnabled();
    if (!$this->items->fail($item, $message, $dead_letter, $now)) {
      return 'lost';
    }
    $this->events->record(
      $runId > 0 ? $runId : $item->runId,
      $definition === NULL ? 'unknown' : (string) $definition->id(),
      $dead_letter ? EventType::Dead : EventType::Failed,
      $item->key,
      NULL,
      $message,
      $now,
    );
    return 'failed';
  }

  /**
   * Loads a run, or NULL when it is gone.
   */
  private function loadRun(int $id): ?ImportRunInterface {
    $run = $this->entityTypeManager->getStorage('import_run')->load($id);
    return $run instanceof ImportRunInterface ? $run : NULL;
  }

  /**
   * Builds the plan of an import, or the reason it cannot be built.
   */
  private function plan(ImportDefinitionInterface $definition): ImportPlan|PlanException {
    try {
      return $this->plans->create($definition);
    }
    catch (PlanException $exception) {
      return $exception;
    }
  }

}
