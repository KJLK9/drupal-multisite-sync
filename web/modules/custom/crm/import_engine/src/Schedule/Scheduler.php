<?php

declare(strict_types=1);

namespace Drupal\import_engine\Schedule;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\import_engine\Drive\DriveResult;
use Drupal\import_engine\Drive\RunBudget;
use Drupal\import_engine\Drive\RunDriver;
use Drupal\import_engine\ImportDefinitionInterface;
use Drupal\import_engine\ImportRunSetInterface;
use Drupal\import_engine\Run\ImportRunInterface;
use Drupal\import_engine\Run\RunStarter;
use Drupal\import_engine\Run\Trigger;
use Drupal\import_engine\RunSet\RunSetRunner;
use Drupal\import_engine\RunSet\SetProgress;

/**
 * Starts the runs that are due.
 *
 * It does not keep time itself: something calls it once per quarter of an hour
 * (see docs/scheduling.md), and it looks at the slot that call is in. Two calls
 * in the same slot (a cron that runs twice, a job that is started again) do
 * not start the same thing twice, and a run that is not over is continued, not
 * started again. Run sets go before imports, and an import that is in a set
 * that is due is left to the set: the set has an order, and the import on its
 * own would run beside it.
 */
final class Scheduler {

  /**
   * The state key that holds, per target, the last slot that was started.
   */
  private const STATE = 'import_engine.schedule_slots';

  /**
   * Constructs the scheduler.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly StateInterface $state,
    private readonly RunStarter $starter,
    private readonly RunDriver $driver,
    private readonly RunSetRunner $setRunner,
  ) {
  }

  /**
   * Returns what is due in the slot of a time, and was not started yet.
   *
   * @param int $now
   *   The time.
   *
   * @return array{sets: list<\Drupal\import_engine\ImportRunSetInterface>, imports: list<\Drupal\import_engine\ImportDefinitionInterface>}
   *   The run sets and the imports that are due.
   */
  public function due(int $now): array {
    $slot = ScheduleRules::slotOf($now);
    $started = $this->state->get(self::STATE, []);
    $sets = [];
    $in_sets = [];
    foreach ($this->entityTypeManager->getStorage('import_run_set')->loadMultiple() as $set) {
      if ($set instanceof ImportRunSetInterface && ScheduleRules::isDue($set->getSchedule(), $slot)) {
        // A set that was due leaves its imports to itself, whether or not it
        // has been started yet in this slot.
        $in_sets = [...$in_sets, ...$set->getImports()];
        if (($started['set:' . $set->id()] ?? NULL) !== $slot) {
          $sets[] = $set;
        }
      }
    }
    $imports = [];
    foreach ($this->entityTypeManager->getStorage('import_definition')->loadMultiple() as $definition) {
      if (!$definition instanceof ImportDefinitionInterface || !$definition->status() || $definition->isTemplate()) {
        continue;
      }
      $id = (string) $definition->id();
      if (ScheduleRules::isDue($definition->getSchedule(), $slot) && !in_array($id, $in_sets, TRUE) && ($started['import:' . $id] ?? NULL) !== $slot) {
        $imports[] = $definition;
      }
    }
    return ['sets' => $sets, 'imports' => $imports];
  }

  /**
   * Runs what is due, within a budget.
   *
   * @param int $now
   *   The time.
   * @param \Drupal\import_engine\Drive\RunBudget $budget
   *   How long to work, for all that is due together.
   * @param string $worker
   *   The name of this worker.
   * @param int $batch
   *   The items claimed at a time.
   */
  public function runDue(int $now, RunBudget $budget, string $worker, int $batch = 50): SchedulerResult {
    $slot = ScheduleRules::slotOf($now);
    $due = $this->due($now);
    $outcomes = [];
    $exit = 0;

    foreach ($due['sets'] as $set) {
      $this->remember('set:' . $set->id(), $slot);
      $progress = $this->setRunner->run($set, new SetProgress(), $budget, $worker, Trigger::Cron, NULL, FALSE, $batch);
      $exit = max($exit, $progress->exitCode());
      $outcomes[] = [
        'kind' => 'set',
        'id' => (string) $set->id(),
        'status' => $progress->state->value,
        'message' => $progress->message,
      ];
    }
    foreach ($due['imports'] as $definition) {
      $this->remember('import:' . $definition->id(), $slot);
      $result = $this->runImport($definition, $budget, $worker, $batch);
      $exit = max($exit, $result->exitCode());
      $outcomes[] = [
        'kind' => 'import',
        'id' => (string) $definition->id(),
        'status' => $result->runStatus->value,
        'message' => $result->message,
      ];
    }
    return new SchedulerResult($outcomes, $exit);
  }

  /**
   * Works on the run of an import.
   *
   * Starts the run, or continues the one that is not over.
   */
  private function runImport(ImportDefinitionInterface $definition, RunBudget $budget, string $worker, int $batch): DriveResult {
    $id = (string) $definition->id();
    $active = $this->starter->activeRunId($id);
    $run = $active === NULL ? $this->starter->start($definition, Trigger::Cron) : $this->entityTypeManager->getStorage('import_run')->load($active);
    if (!$run instanceof ImportRunInterface) {
      throw new \LogicException(sprintf('The run of "%s" is not there.', $id));
    }
    return $this->driver->drive($run, $definition, $budget, $worker, $batch);
  }

  /**
   * Remembers that a target was started in a slot.
   */
  private function remember(string $target, int $slot): void {
    $started = $this->state->get(self::STATE, []);
    $started[$target] = $slot;
    $this->state->set(self::STATE, $started);
  }

}
