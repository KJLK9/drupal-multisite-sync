<?php

declare(strict_types=1);

namespace Drupal\import_engine\Drive;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\import_engine\Breaker\CircuitBreaker;
use Drupal\import_engine\Connection\ConnectionException;
use Drupal\import_engine\Entity\ImportDefinition;
use Drupal\import_engine\Extract\ExtractStage;
use Drupal\import_engine\Extract\ExtractStatus;
use Drupal\import_engine\Finish\FinishStage;
use Drupal\import_engine\Finish\FinishStatus;
use Drupal\import_engine\ImportDefinitionInterface;
use Drupal\import_engine\Process\ProcessStage;
use Drupal\import_engine\Run\ImportRunInterface;
use Drupal\import_engine\Run\RunStatus;
use Drupal\import_engine\Source\SourceFactory;
use Drupal\import_engine\Source\SourceInterface;

/**
 * Drives one run through its stages for as long as the budget allows.
 *
 * The three stages (extract, process, finish) each do a portion and report
 * back. The driver chains them: it reads the source in portions, processes
 * the items in batches and finishes the run. When the budget runs out or a
 * stop is requested it returns, and the next call continues where the run
 * stood, because every stage keeps its position in the run and the work queue.
 *
 * It is the one place that knows the order of the stages. A drush command, a
 * cron run or a batch request from the interface only decide the budget.
 */
final class RunDriver {

  /**
   * The pages read per call of the extract stage.
   */
  private const PAGES_PER_PORTION = 10;

  /**
   * How long a claimed item belongs to a worker, in seconds.
   */
  private const LEASE_SECONDS = 300;

  /**
   * Constructs the driver.
   */
  public function __construct(
    private readonly ExtractStage $extract,
    private readonly ProcessStage $process,
    private readonly FinishStage $finish,
    private readonly SourceFactory $sources,
    private readonly CircuitBreaker $breaker,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
  ) {
  }

  /**
   * Drives a run.
   *
   * @param \Drupal\import_engine\Run\ImportRunInterface $run
   *   The run, in any status that is not final.
   * @param \Drupal\import_engine\ImportDefinitionInterface $definition
   *   The import of the run.
   * @param \Drupal\import_engine\Drive\RunBudget $budget
   *   How long to work.
   * @param string $worker
   *   The name of this worker, to claim items with.
   * @param int $batch
   *   The items claimed at a time.
   * @param \Drupal\import_engine\Source\SourceInterface|null $source
   *   The source to read, instead of the one the definition describes.
   */
  public function drive(ImportRunInterface $run, ImportDefinitionInterface $definition, RunBudget $budget, string $worker, int $batch = 50, ?SourceInterface $source = NULL): DriveResult {
    $pages = 0;
    $items = 0;

    if (in_array($run->getStatus(), [RunStatus::Queued, RunStatus::Extracting], TRUE)) {
      try {
        $source ??= $this->breaker->wrap($this->sources->create($definition), $definition);
      }
      catch (ConnectionException $exception) {
        // Nothing can be read until the import and its connection fit again.
        $run->setSummary($exception->getMessage())->transitionTo(RunStatus::Failed, $this->time->getCurrentTime())->save();
        return new DriveResult(DriveStatus::Finished, RunStatus::Failed, 0, 0, $exception->getMessage());
      }
      do {
        $result = $this->extract->extract($run, $definition, $source, self::PAGES_PER_PORTION, $budget->deadline);
        $pages += $result->pages;
        if ($result->status === ExtractStatus::Busy) {
          return new DriveResult(DriveStatus::Busy, $run->getStatus(), $pages, $items, $result->message);
        }
        if ($result->status === ExtractStatus::Interrupted) {
          return new DriveResult(DriveStatus::Interrupted, $run->getStatus(), $pages, $items, $result->message);
        }
        if ($result->status === ExtractStatus::MoreToDo && $this->spent($budget)) {
          return new DriveResult(DriveStatus::OutOfBudget, $run->getStatus(), $pages, $items);
        }
      } while ($result->status === ExtractStatus::MoreToDo);
    }

    if ($run->getStatus() === RunStatus::Processing) {
      do {
        if ($this->spent($budget)) {
          return new DriveResult(DriveStatus::OutOfBudget, $run->getStatus(), $pages, $items);
        }
        $done = $this->process->process($worker, $batch, $definition->getPool(), self::LEASE_SECONDS, NULL, $budget->deadline, fn (): bool => $this->spent($budget));
        $items += $done->claimed;
      } while ($done->claimed > 0);
    }

    $finished = $this->finish->finish($run, $definition);
    // The finish stage worked on a fresh copy of the run.
    $current = $this->entityTypeManager->getStorage('import_run')->loadUnchanged((int) $run->id());
    $status = $current instanceof ImportRunInterface ? $current->getStatus() : $run->getStatus();
    return match ($finished->status) {
      FinishStatus::Finished => new DriveResult(DriveStatus::Finished, $status, $pages, $items),
      FinishStatus::Waiting => new DriveResult(DriveStatus::Waiting, $status, $pages, $items, 'Items wait for a retry or are with another worker.'),
      FinishStatus::Busy => new DriveResult(DriveStatus::Busy, $status, $pages, $items, 'Another process is finishing this run.'),
      FinishStatus::NotApplicable => new DriveResult(DriveStatus::Finished, $status, $pages, $items),
    };
  }

  /**
   * Continues the runs that are not over, as long as the budget allows.
   *
   * For a periodic caller such as cron: a run that was stopped, or that waits
   * for a retry, is picked up again without anyone starting it.
   *
   * @param \Drupal\import_engine\Drive\RunBudget $budget
   *   How long to work.
   * @param string $worker
   *   The name of this worker.
   *
   * @return int
   *   How many runs were finished.
   */
  public function resumeActive(RunBudget $budget, string $worker): int {
    $storage = $this->entityTypeManager->getStorage('import_run');
    $active = array_map(static fn (RunStatus $status): string => $status->value, array_filter(RunStatus::cases(), static fn (RunStatus $status): bool => !$status->isFinal()));
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('status', $active, 'IN')->sort('id')->execute();
    $finished = 0;
    foreach ($storage->loadMultiple($ids) as $run) {
      if ($this->spent($budget)) {
        break;
      }
      $definition = $run instanceof ImportRunInterface ? ImportDefinition::load($run->getDefinitionId()) : NULL;
      if ($run instanceof ImportRunInterface && $definition !== NULL && $this->drive($run, $definition, $budget, $worker)->status === DriveStatus::Finished) {
        $finished++;
      }
    }
    return $finished;
  }

  /**
   * Returns whether the budget is spent.
   */
  private function spent(RunBudget $budget): bool {
    return $budget->isExhausted($this->time->getCurrentTime());
  }

}
