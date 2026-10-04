<?php

declare(strict_types=1);

namespace Drupal\import_engine\Drive;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\import_engine\Entity\ImportDefinition;
use Drupal\import_engine\Finish\FinishStage;
use Drupal\import_engine\Finish\FinishStatus;
use Drupal\import_engine\Process\ProcessStage;
use Drupal\import_engine\Run\ImportRunInterface;
use Drupal\import_engine\Run\RunStatus;

/**
 * A worker of a pool: takes items of any run and finishes runs that are done.
 *
 * Workers know nothing about one import. They claim items from the work queue
 * of their pool and, after every batch, close the runs whose items are all
 * handled. Run as many as needed, in one process or in many; the claims and
 * the locks keep them apart. A pool (a name on the import) decides which
 * workers handle which imports, for example to keep heavy ones apart.
 */
final class Worker {

  /**
   * How long a claimed item belongs to a worker, in seconds.
   */
  private const LEASE_SECONDS = 300;

  /**
   * Constructs the worker.
   */
  public function __construct(
    private readonly ProcessStage $process,
    private readonly FinishStage $finish,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
  ) {
  }

  /**
   * Works until the budget is spent, or, with once, until the queue is empty.
   *
   * @param \Drupal\import_engine\Drive\RunBudget $budget
   *   How long to work.
   * @param string $worker
   *   The name of this worker, to claim items with.
   * @param string $pool
   *   The pool to take items from.
   * @param int $batch
   *   The items claimed at a time.
   * @param bool $once
   *   Whether to stop when there is nothing left to do, instead of waiting for
   *   new items.
   * @param callable|null $idle
   *   Called when there is nothing to do and the worker waits; by default it
   *   sleeps for a second.
   */
  public function work(RunBudget $budget, string $worker, string $pool = 'default', int $batch = 50, bool $once = FALSE, ?callable $idle = NULL): WorkerResult {
    $idle ??= static function (): void {
      sleep(1);
    };
    $items = 0;
    $finished = 0;
    while (!$budget->isExhausted($this->time->getCurrentTime())) {
      $done = $this->process->process($worker, $batch, $pool, self::LEASE_SECONDS, NULL, $budget->deadline, fn (): bool => $budget->isExhausted($this->time->getCurrentTime()));
      $items += $done->claimed;
      $finished += $this->finishRuns();
      if ($done->claimed === 0) {
        if ($once) {
          return new WorkerResult($items, $finished, WorkerResult::IDLE);
        }
        $idle();
      }
    }
    return new WorkerResult($items, $finished, WorkerResult::BUDGET);
  }

  /**
   * Finishes the runs that are processing and have no work left.
   *
   * @return int
   *   How many runs were finished.
   */
  private function finishRuns(): int {
    $storage = $this->entityTypeManager->getStorage('import_run');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', RunStatus::Processing->value)
      ->execute();
    $finished = 0;
    foreach ($storage->loadMultiple($ids) as $run) {
      $definition = $run instanceof ImportRunInterface ? ImportDefinition::load($run->getDefinitionId()) : NULL;
      if ($run instanceof ImportRunInterface && $definition !== NULL && $this->finish->finish($run, $definition)->status === FinishStatus::Finished) {
        $finished++;
      }
    }
    return $finished;
  }

}
