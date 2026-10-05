<?php

declare(strict_types=1);

namespace Drupal\import_engine\RunSet;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\import_engine\Drive\DriveStatus;
use Drupal\import_engine\Drive\RunBudget;
use Drupal\import_engine\Drive\RunDriver;
use Drupal\import_engine\Entity\ImportDefinition;
use Drupal\import_engine\ImportRunSetInterface;
use Drupal\import_engine\Run\ImportRunInterface;
use Drupal\import_engine\Run\RunAlreadyActiveException;
use Drupal\import_engine\Run\RunStarter;
use Drupal\import_engine\Run\RunStatus;
use Drupal\import_engine\Run\Trigger;

/**
 * Runs the imports of a set one after the other.
 *
 * The runner works for as long as its budget lasts and returns where it got
 * to; the caller calls it again with that, until the set is over. A command
 * gives it one budget; the interface gives it a few seconds at a time between
 * the steps of a progress bar. It does not run imports itself: every import
 * is a run, driven by the run driver, so everything a run does (retries, the
 * circuit breaker, reports) is the same inside a set.
 *
 * A set that was not finished starts again from its first import. An import
 * with a run that is not over continues that run, and an import whose pages
 * did not change reads them quickly, so starting again costs little.
 */
final class RunSetRunner {

  /**
   * Constructs the runner.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RunStarter $starter,
    private readonly RunDriver $driver,
  ) {
  }

  /**
   * Works on a set until it is over, cannot go on, or the budget is spent.
   *
   * @param \Drupal\import_engine\ImportRunSetInterface $set
   *   The set.
   * @param \Drupal\import_engine\RunSet\SetProgress $from
   *   Where the previous call stopped; a new SetProgress to begin.
   * @param \Drupal\import_engine\Drive\RunBudget $budget
   *   How long to work.
   * @param string $worker
   *   The name of this worker, to claim items with.
   * @param \Drupal\import_engine\Run\Trigger $trigger
   *   What started the runs.
   * @param int|null $uid
   *   The person who started them, if a person did.
   * @param bool $full
   *   Whether the runs that start process every page.
   * @param int $batch
   *   The items claimed at a time.
   *
   * @return \Drupal\import_engine\RunSet\SetProgress
   *   Where the set is now. Its state is Running when the budget ran out.
   */
  public function run(ImportRunSetInterface $set, SetProgress $from, RunBudget $budget, string $worker, Trigger $trigger, ?int $uid = NULL, bool $full = FALSE, int $batch = 50): SetProgress {
    $progress = $from;
    $imports = $set->getImports();
    while ($progress->state === SetState::Running) {
      if ($progress->index >= count($imports)) {
        return new SetProgress($progress->index, NULL, $progress->outcomes, SetState::Completed);
      }
      $id = $imports[$progress->index];
      $definition = ImportDefinition::load($id);
      if ($definition === NULL || !$definition->status()) {
        $why = $definition === NULL ? sprintf('The import "%s" no longer exists.', $id) : sprintf('The import "%s" is disabled.', $id);
        return $this->stopped($progress, $id, NULL, 'not run', $why);
      }

      try {
        $run = $this->runOf($progress, $definition, $trigger, $uid, $full);
      }
      catch (\RuntimeException $exception) {
        return $this->stopped($progress, $id, NULL, 'not started', $exception->getMessage());
      }
      $progress = new SetProgress($progress->index, (int) $run->id(), $progress->outcomes);

      if (!$run->getStatus()->isFinal()) {
        $result = $this->driver->drive($run, $definition, $budget, $worker, $batch);
        if ($result->status === DriveStatus::OutOfBudget) {
          return $progress;
        }
        if ($result->status !== DriveStatus::Finished) {
          $why = sprintf('The import "%s" cannot go on now: %s', $id, $result->message ?? $result->status->value);
          return new SetProgress($progress->index, $progress->runId, $progress->outcomes, SetState::Waiting, $why);
        }
        $run = $this->entityTypeManager->getStorage('import_run')->loadUnchanged((int) $run->id());
        assert($run instanceof ImportRunInterface);
      }

      $outcome = [
        'import' => $id,
        'run' => (int) $run->id(),
        'status' => $run->getStatus()->value,
        'summary' => $run->getSummary(),
      ];
      $outcomes = [...$progress->outcomes, $outcome];
      if ($this->stops($set, $run->getStatus())) {
        return new SetProgress($progress->index, NULL, $outcomes, SetState::Stopped, sprintf('The import "%s" ended as %s, so the imports after it did not run.', $id, str_replace('_', ' ', $run->getStatus()->value)));
      }
      $progress = new SetProgress($progress->index + 1, NULL, $outcomes);
    }
    return $progress;
  }

  /**
   * Returns the run an import is on: the one it has, or a new one.
   *
   * @throws \RuntimeException
   *   When no run can be started.
   */
  private function runOf(SetProgress $progress, ImportDefinition $definition, Trigger $trigger, ?int $uid, bool $full): ImportRunInterface {
    $storage = $this->entityTypeManager->getStorage('import_run');
    $id = $progress->runId ?? $this->starter->activeRunId((string) $definition->id());
    if ($id === NULL) {
      try {
        return $this->starter->start($definition, $trigger, $uid, $full);
      }
      catch (RunAlreadyActiveException $exception) {
        $id = $exception->runId;
      }
    }
    $run = $storage->load($id);
    if (!$run instanceof ImportRunInterface) {
      throw new \RuntimeException(sprintf('Run %d of the import "%s" no longer exists.', $id, $definition->id()));
    }
    return $run;
  }

  /**
   * Returns whether a set stops after a run that ended like this.
   */
  private function stops(ImportRunSetInterface $set, RunStatus $status): bool {
    return match ($status) {
      RunStatus::Completed => FALSE,
      RunStatus::CompletedWithErrors => $set->stopsOnErrors(),
      default => TRUE,
    };
  }

  /**
   * Ends the set at an import that could not run.
   */
  private function stopped(SetProgress $progress, string $import, ?int $run, string $status, string $why): SetProgress {
    $outcomes = [...$progress->outcomes, ['import' => $import, 'run' => $run, 'status' => $status, 'summary' => $why]];
    return new SetProgress($progress->index, NULL, $outcomes, SetState::Stopped, $why);
  }

}
