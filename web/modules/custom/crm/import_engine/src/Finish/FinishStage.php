<?php

declare(strict_types=1);

namespace Drupal\import_engine\Finish;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\import_engine\ImportDefinitionInterface;
use Drupal\import_engine\Process\PlanException;
use Drupal\import_engine\Process\PlanFactory;
use Drupal\import_engine\Reporter\RunReport;
use Drupal\import_engine\Reporter\RunReporter;
use Drupal\import_engine\Run\ImportRunInterface;
use Drupal\import_engine\Run\RunCounters;
use Drupal\import_engine\Run\RunStatus;
use Drupal\import_engine\Storage\EventLog;
use Drupal\import_engine\Storage\ItemStorage;
use Drupal\import_engine\Storage\PageStore;

/**
 * Closes a run once every item has been handled.
 *
 * Any worker may call it after a batch: it does nothing while items are still
 * waiting or being retried. When the work is done it:
 * - verifies the pages whose items all went well, so the next run may skip
 *   them;
 * - sweeps what disappeared from the source, but only when extraction was
 *   complete, so a failing source can never empty the target;
 * - stores the counters, derived from the items, on the run;
 * - sets the final status: failed when extraction was not complete, completed
 *   with errors when items failed or the sweep did not do its job, completed
 *   otherwise;
 * - tells the reporters of the import.
 *
 * Only one process finishes a run, held by a lock. A finish that stops halfway
 * leaves the run finishing and can be called again: each step can be repeated.
 */
final class FinishStage {

  /**
   * How long the lock is held without being renewed, in seconds.
   */
  public const LOCK_SECONDS = 300;

  /**
   * How many problems a report names.
   */
  private const REPORTED_PROBLEMS = 20;

  /**
   * Constructs the stage.
   */
  public function __construct(
    private readonly ItemStorage $items,
    private readonly RunCounters $counters,
    private readonly PageStore $pages,
    private readonly EventLog $events,
    private readonly Sweeper $sweeper,
    private readonly PlanFactory $plans,
    private readonly RunReporter $reporter,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LockBackendInterface $lock,
    private readonly TimeInterface $time,
  ) {
  }

  /**
   * Finishes a run when its work is done.
   *
   * The given run object is not updated: it is loaded again under the lock.
   *
   * @param \Drupal\import_engine\Run\ImportRunInterface $run
   *   The run.
   * @param \Drupal\import_engine\ImportDefinitionInterface $definition
   *   The import of the run.
   * @param int|null $now
   *   The time, for tests.
   *
   * @throws \LogicException
   *   When the run belongs to another import.
   */
  public function finish(ImportRunInterface $run, ImportDefinitionInterface $definition, ?int $now = NULL): FinishResult {
    if ($run->getDefinitionId() !== $definition->id()) {
      throw new \LogicException('The run belongs to another import.');
    }
    $lock = 'import_engine:finish:' . $run->id();
    if (!$this->lock->acquire($lock, self::LOCK_SECONDS)) {
      return new FinishResult(FinishStatus::Busy);
    }
    try {
      return $this->work((int) $run->id(), $definition, $lock, $now ?? $this->time->getCurrentTime());
    }
    finally {
      $this->lock->release($lock);
    }
  }

  /**
   * Does the work, holding the lock.
   */
  private function work(int $runId, ImportDefinitionInterface $definition, string $lock, int $now): FinishResult {
    $storage = $this->entityTypeManager->getStorage('import_run');
    $run = $storage->loadUnchanged($runId);
    if (!$run instanceof ImportRunInterface) {
      return new FinishResult(FinishStatus::NotApplicable);
    }

    if ($run->getStatus() === RunStatus::Processing) {
      $states = $this->items->countByState($runId);
      if ($states['pending'] + $states['processing'] + $states['retrying'] > 0) {
        return new FinishResult(FinishStatus::Waiting);
      }
      $run->transitionTo(RunStatus::Finishing, $now)->save();
    }
    elseif ($run->getStatus() !== RunStatus::Finishing) {
      return new FinishResult(FinishStatus::NotApplicable);
    }

    $definition_id = (string) $definition->id();
    $verified = $this->pages->verify($definition_id, $runId, $this->items->pagesWithFailures($runId));

    $sweep = new SweepResult();
    if ($run->isExtractComplete()) {
      $sweep = $this->sweep($runId, $definition, fn (): bool => $this->lock->acquire($lock, self::LOCK_SECONDS), $now);
    }

    $run->setCounters($this->counters->derive($run, $sweep->swept));
    $status = $this->finalStatus($run, $sweep);
    $summary = trim($run->getSummary() . ' ' . ($sweep->message ?? ''));
    $run->setSummary($summary)->transitionTo($status, $now)->save();

    $report = new RunReport(
      $runId,
      $definition_id,
      (string) $definition->label(),
      $status,
      $run->getCounters(),
      $run->getSummary(),
      $this->timestamp($run, 'started'),
      $this->timestamp($run, 'finished'),
      $this->events->problems($runId, self::REPORTED_PROBLEMS),
    );
    $reported = $this->reporter->report($definition, $report);

    return new FinishResult(FinishStatus::Finished, $status, $sweep->swept, $verified, $reported);
  }

  /**
   * Sweeps; a definition whose target cannot be created is a problem.
   */
  private function sweep(int $runId, ImportDefinitionInterface $definition, callable $heartbeat, int $now): SweepResult {
    try {
      $target = $this->plans->target($definition);
    }
    catch (PlanException $exception) {
      return new SweepResult(message: 'Sweep skipped: ' . $exception->getMessage(), problem: TRUE);
    }
    return $this->sweeper->sweep($runId, $definition, $target, $heartbeat, $now);
  }

  /**
   * Decides how the run ended.
   */
  private function finalStatus(ImportRunInterface $run, SweepResult $sweep): RunStatus {
    if (!$run->isExtractComplete()) {
      return RunStatus::Failed;
    }
    $counters = $run->getCounters();
    if ($counters['failed'] > 0 || $counters['dead'] > 0 || $sweep->problem) {
      return RunStatus::CompletedWithErrors;
    }
    return RunStatus::Completed;
  }

  /**
   * Reads a timestamp field of the run.
   */
  private function timestamp(ImportRunInterface $run, string $field): ?int {
    $value = $run->get($field)->value;
    return $value === NULL ? NULL : (int) $value;
  }

}
