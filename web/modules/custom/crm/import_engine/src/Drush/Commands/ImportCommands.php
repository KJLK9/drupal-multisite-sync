<?php

declare(strict_types=1);

namespace Drupal\import_engine\Drush\Commands;

use Consolidation\AnnotatedCommand\CommandResult;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\import_engine\Drive\DriveResult;
use Drupal\import_engine\Drive\DriveStatus;
use Drupal\import_engine\Drive\RunBudget;
use Drupal\import_engine\Drive\RunDriver;
use Drupal\import_engine\Drive\Worker;
use Drupal\import_engine\Entity\ImportDefinition;
use Drupal\import_engine\ImportDefinitionInterface;
use Drupal\import_engine\Run\ImportRunInterface;
use Drupal\import_engine\Run\RunManager;
use Drupal\import_engine\Run\RunStarter;
use Drupal\import_engine\Run\RunStatus;
use Drupal\import_engine\Run\Trigger;
use Drupal\import_engine\Storage\ItemStorage;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Drush commands to run imports.
 *
 * The commands are thin: the work is done by the run driver and the worker.
 * Exit codes of import:run: 0 completed, 1 failed or completed with errors,
 * 2 not over yet (budget spent, waiting for retries, source interrupted).
 */
final class ImportCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * Constructs the commands.
   */
  public function __construct(
    #[Autowire(service: 'import_engine.run_driver')]
    private readonly RunDriver $driver,
    #[Autowire(service: 'import_engine.worker')]
    private readonly Worker $worker,
    #[Autowire(service: 'import_engine.run_starter')]
    private readonly RunStarter $starter,
    #[Autowire(service: 'import_engine.run_manager')]
    private readonly RunManager $manager,
    #[Autowire(service: 'import_engine.item_storage')]
    private readonly ItemStorage $items,
    #[Autowire(service: 'entity_type.manager')]
    private readonly EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'datetime.time')]
    private readonly TimeInterface $time,
  ) {
    parent::__construct();
  }

  /**
   * Runs an import from start to end, or continues its unfinished run.
   *
   * @param string $import
   *   The ID of the import definition.
   * @param array<string, mixed> $options
   *   The command options.
   */
  #[CLI\Command(name: 'import:run')]
  #[CLI\Argument(name: 'import', description: 'The ID of the import definition.')]
  #[CLI\Option(name: 'full', description: 'Process every page, also those that did not change.')]
  #[CLI\Option(name: 'max-time', description: 'Stop after this many seconds; 0 for no limit.')]
  #[CLI\Option(name: 'batch', description: 'Items to claim at a time.')]
  #[CLI\Usage(name: 'drush import:run customers', description: 'Run the customers import, or continue its unfinished run.')]
  public function run(string $import, array $options = ['full' => FALSE, 'max-time' => 0, 'batch' => 50]): CommandResult {
    $definition = $this->definition($import);
    $active = $this->starter->activeRunId($import);
    if ($active !== NULL) {
      $run = $this->entityTypeManager->getStorage('import_run')->load($active);
      assert($run instanceof ImportRunInterface);
      $this->io()->writeln(sprintf('Continuing run %d (%s).', $active, $run->getStatus()->value));
    }
    else {
      $run = $this->starter->start($definition, Trigger::Drush, NULL, (bool) $options['full']);
      $this->io()->writeln(sprintf('Started run %d.', $run->id()));
    }

    $budget = RunBudget::ofSeconds((int) $options['max-time'], $this->time->getCurrentTime());
    $budget->stopOnSignals();
    $result = $this->driver->drive($run, $definition, $budget, $this->workerName(), max(1, (int) $options['batch']));
    $this->io()->writeln($this->describe($run, $result));
    return CommandResult::exitCode($result->exitCode());
  }

  /**
   * Works through the queue of items of a pool.
   *
   * @param array<string, mixed> $options
   *   The command options.
   */
  #[CLI\Command(name: 'import:work')]
  #[CLI\Option(name: 'pool', description: 'The worker pool to take items from.')]
  #[CLI\Option(name: 'max-time', description: 'Stop after this many seconds; 0 for no limit.')]
  #[CLI\Option(name: 'batch', description: 'Items to claim at a time.')]
  #[CLI\Option(name: 'once', description: 'Stop when the queue is empty instead of waiting for new items.')]
  #[CLI\Usage(name: 'drush import:work --pool=heavy --once', description: 'Handle the items of the heavy pool and stop.')]
  public function work(array $options = ['pool' => 'default', 'max-time' => 0, 'batch' => 50, 'once' => FALSE]): CommandResult {
    $budget = RunBudget::ofSeconds((int) $options['max-time'], $this->time->getCurrentTime());
    $budget->stopOnSignals();
    $result = $this->worker->work($budget, $this->workerName(), (string) $options['pool'], max(1, (int) $options['batch']), (bool) $options['once']);
    $this->io()->writeln(sprintf('Handled %d items and finished %d runs; stopped: %s.', $result->items, $result->finished, $result->reason));
    return CommandResult::exitCode(0);
  }

  /**
   * Shows the latest run of one or all imports.
   *
   * @param string|null $import
   *   The ID of an import definition; all imports by default.
   */
  #[CLI\Command(name: 'import:status')]
  #[CLI\Argument(name: 'import', description: 'The ID of the import definition; all by default.')]
  #[CLI\Usage(name: 'drush import:status', description: 'Show the latest run of every import.')]
  public function status(?string $import = NULL): CommandResult {
    $ids = $import === NULL ? array_keys($this->entityTypeManager->getStorage('import_definition')->loadMultiple()) : [$this->definition($import)->id()];
    $rows = [];
    foreach ($ids as $id) {
      $run = $this->manager->latest((string) $id);
      if ($run === NULL) {
        $rows[] = [$id, '-', 'never run', '', ''];
        continue;
      }
      $states = $this->items->countByState((int) $run->id());
      $rows[] = [
        $id,
        (string) $run->id(),
        $run->getStatus()->value,
        sprintf('pending %d, processing %d, retrying %d, done %d, dead %d', $states['pending'], $states['processing'], $states['retrying'], $states['done'], $states['dead']),
        $run->getSummary(),
      ];
    }
    $this->io()->table(['Import', 'Run', 'Status', 'Items', 'Summary'], $rows);
    return CommandResult::exitCode(0);
  }

  /**
   * Makes the dead items of an import pending again.
   *
   * @param string $import
   *   The ID of the import definition.
   * @param array<string, mixed> $options
   *   The command options.
   */
  #[CLI\Command(name: 'import:retry')]
  #[CLI\Argument(name: 'import', description: 'The ID of the import definition.')]
  #[CLI\Option(name: 'limit', description: 'The most items to retry.')]
  #[CLI\Usage(name: 'drush import:retry customers', description: 'Retry the dead items of the customers import; a worker handles them.')]
  public function retry(string $import, array $options = ['limit' => 1000]): CommandResult {
    $this->definition($import);
    $count = $this->manager->retryDead($import, max(1, (int) $options['limit']));
    $this->io()->writeln(sprintf('%d dead items are pending again. Run import:work to handle them.', $count));
    return CommandResult::exitCode(0);
  }

  /**
   * Cancels a run that is not over.
   *
   * @param string $run
   *   The ID of the run.
   */
  #[CLI\Command(name: 'import:cancel')]
  #[CLI\Argument(name: 'run', description: 'The ID of the run.')]
  #[CLI\Usage(name: 'drush import:cancel 12', description: 'Cancel run 12; items that wait are skipped.')]
  public function cancel(string $run): CommandResult {
    $entity = $this->entityTypeManager->getStorage('import_run')->load((int) $run);
    if (!$entity instanceof ImportRunInterface) {
      throw new \InvalidArgumentException(sprintf('There is no run %s.', $run));
    }
    if ($entity->getStatus()->isFinal()) {
      $this->io()->writeln(sprintf('Run %s is already over (%s).', $run, $entity->getStatus()->value));
      return CommandResult::exitCode(1);
    }
    $skipped = $this->manager->cancel($entity);
    $this->io()->writeln(sprintf('Run %s cancelled; %d waiting items skipped.', $run, $skipped));
    return CommandResult::exitCode(0);
  }

  /**
   * Loads an enabled import definition.
   *
   * @throws \InvalidArgumentException
   *   When it does not exist or is disabled.
   */
  private function definition(string $id): ImportDefinitionInterface {
    $definition = ImportDefinition::load($id);
    if ($definition === NULL) {
      throw new \InvalidArgumentException(sprintf('There is no import "%s".', $id));
    }
    if (!$definition->status()) {
      throw new \InvalidArgumentException(sprintf('The import "%s" is disabled.', $id));
    }
    return $definition;
  }

  /**
   * Returns the name of this worker: host and process.
   *
   * Letters, digits, dots, dashes and underscores only, at most 40, as the
   * work queue wants; the process ID is kept when the host name is long.
   */
  private function workerName(): string {
    $host = preg_replace('/[^A-Za-z0-9._-]/', '-', (string) gethostname()) ?? 'host';
    $pid = '-' . getmypid();
    return substr($host, 0, 40 - strlen($pid)) . $pid;
  }

  /**
   * Describes how a call of the driver ended.
   */
  private function describe(ImportRunInterface $run, DriveResult $result): string {
    $line = sprintf('Run %d: %s, run status %s; %d pages read, %d items handled.', $run->id(), $result->status->value, $result->runStatus->value, $result->pages, $result->items);
    if ($result->message !== NULL) {
      $line .= ' ' . $result->message;
    }
    if ($result->runStatus === RunStatus::Processing && $result->status === DriveStatus::OutOfBudget) {
      $line .= ' Run the command again to continue.';
    }
    return $line;
  }

}
