<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Kernel;

use Drupal\Core\Lock\PersistentDatabaseLockBackend;
use Drupal\import_engine\Drive\DriveResult;
use Drupal\import_engine\Drive\DriveStatus;
use Drupal\import_engine\Drive\RunBudget;
use Drupal\import_engine\Drive\RunDriver;
use Drupal\import_engine\Entity\ImportDefinition;
use Drupal\import_engine\Hook\ImportEngineHooks;
use Drupal\import_engine\Run\RunStatus;
use Drupal\import_engine\Run\Trigger;
use Drupal\import_engine\Source\SourceException;
use Drupal\node\Entity\Node;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the driver that chains the stages of a run.
 */
#[Group('import_engine')]
#[RunTestsInSeparateProcesses]
class RunDriverTest extends NodeTestBase {

  /**
   * The driver.
   */
  protected RunDriver $driver;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->driver = $this->container->get('import_engine.run_driver');
  }

  /**
   * Builds pages of accounts.
   *
   * @return list<list<array<string, mixed>>>
   *   Three pages of two accounts each.
   */
  protected function pages(): array {
    $pages = [];
    foreach ([[1, 2], [3, 4], [5, 6]] as $ids) {
      $pages[] = array_map(static fn (int $id): array => ['id' => $id, 'name' => "Account $id", 'code' => "C$id"], $ids);
    }
    return $pages;
  }

  /**
   * Starts a run of the saved definition.
   *
   * @param array<string, mixed> $values
   *   Definition values.
   *
   * @return array{0: \Drupal\import_engine\Entity\ImportRun, 1: \Drupal\import_engine\Entity\ImportDefinition}
   *   The run and the definition.
   */
  protected function start(array $values = []): array {
    $this->definition($this->accounts($values))->save();
    $definition = ImportDefinition::load('customers');
    $this->assertNotNull($definition);
    return [$this->starter->start($definition, Trigger::Drush), $definition];
  }

  /**
   * A run goes from queued to completed in one call.
   */
  public function testDrivesWholeRun(): void {
    [$run, $definition] = $this->start();

    $result = $this->driver->drive($run, $definition, new RunBudget(), 'w', 50, new FakeSource($this->pages()));

    $this->assertSame(DriveStatus::Finished, $result->status);
    $this->assertSame(RunStatus::Completed, $result->runStatus);
    $this->assertSame(3, $result->pages);
    $this->assertSame(6, $result->items);
    $this->assertSame(DriveResult::EXIT_OK, $result->exitCode());
    $this->assertCount(6, Node::loadMultiple());
    $this->assertSame(6, $this->reload($run)->getCounters()['created']);
  }

  /**
   * A spent budget stops the run between stages and a later call continues.
   */
  public function testContinuesAfterBudgetRunsOut(): void {
    [$run, $definition] = $this->start();
    $source = new FakeSource($this->pages());
    // A deadline in the past: extraction still reads a page, then it stops.
    $spent = new RunBudget(1);

    $first = $this->driver->drive($run, $definition, $spent, 'w', 50, $source);

    $this->assertSame(DriveStatus::OutOfBudget, $first->status);
    $this->assertSame(RunStatus::Extracting, $first->runStatus);
    $this->assertSame(1, $first->pages);
    $this->assertSame(DriveResult::EXIT_UNFINISHED, $first->exitCode());

    $second = $this->driver->drive($this->reload($run), $definition, new RunBudget(), 'w', 50, $source);

    $this->assertSame(DriveStatus::Finished, $second->status);
    $this->assertSame(2, $second->pages);
    $this->assertCount(6, Node::loadMultiple());
    $this->assertSame(RunStatus::Completed, $this->reload($run)->getStatus());
  }

  /**
   * A stop request ends processing, gives back claimed items and can resume.
   */
  public function testStopRequestedBeforeProcessing(): void {
    [$run, $definition] = $this->start();
    $budget = new RunBudget();
    $budget->stop();

    $result = $this->driver->drive($run, $definition, $budget, 'w', 50, new FakeSource($this->pages()));

    $this->assertSame(DriveStatus::OutOfBudget, $result->status);
    $this->assertCount(0, Node::loadMultiple());

    $again = $this->driver->drive($this->reload($run), $definition, new RunBudget(), 'w');
    $this->assertSame(DriveStatus::Finished, $again->status);
    $this->assertCount(6, Node::loadMultiple());
  }

  /**
   * A temporary problem at the source interrupts the run, and it resumes.
   */
  public function testInterruptedBySourceAndResumed(): void {
    [$run, $definition] = $this->start();
    $source = (new FakeSource($this->pages()))->failOnce(1, SourceException::transient('timeout'));

    $first = $this->driver->drive($run, $definition, new RunBudget(), 'w', 50, $source);

    $this->assertSame(DriveStatus::Interrupted, $first->status);
    $this->assertSame('timeout', $first->message);
    $this->assertSame(1, $first->pages);

    $second = $this->driver->drive($this->reload($run), $definition, new RunBudget(), 'w', 50, $source);
    $this->assertSame(DriveStatus::Finished, $second->status);
    $this->assertSame(RunStatus::Completed, $second->runStatus);
    $this->assertCount(6, Node::loadMultiple());
  }

  /**
   * Another extraction of the same import makes the call busy.
   */
  public function testBusyWhileAnotherExtracts(): void {
    [$run, $definition] = $this->start();
    $other = new PersistentDatabaseLockBackend($this->container->get('database'));
    $other->acquire('import_engine:extract:customers', 60);

    $result = $this->driver->drive($run, $definition, new RunBudget(), 'w', 50, new FakeSource($this->pages()));

    $this->assertSame(DriveStatus::Busy, $result->status);
    $this->assertSame(DriveResult::EXIT_UNFINISHED, $result->exitCode());
  }

  /**
   * Items that wait for a retry keep the run open and the call waiting.
   */
  public function testWaitsForRetries(): void {
    $values = [
      'mapping' => $this->accounts()['mapping'],
    ];
    $values['mapping'][] = [
      'target_field' => 'field_item',
      'mapper' => [
        'plugin' => 'reference',
        'sources' => ['id' => 'item'],
        'settings' => ['definition' => 'items', 'required' => TRUE],
      ],
    ];
    [$run, $definition] = $this->start($values);
    $source = new FakeSource([[['id' => 1, 'name' => 'Acme', 'code' => 'A', 'item' => 'S-1']]]);

    $result = $this->driver->drive($run, $definition, new RunBudget(), 'w', 50, $source);

    $this->assertSame(DriveStatus::Waiting, $result->status);
    $this->assertSame(RunStatus::Processing, $result->runStatus);
    $this->assertSame(DriveResult::EXIT_UNFINISHED, $result->exitCode());
  }

  /**
   * A run that ended with errors has exit code 1.
   */
  public function testExitCodeForErrors(): void {
    [$run, $definition] = $this->start();
    $source = new FakeSource([[['id' => 1, 'name' => '', 'code' => 'A']]]);

    $result = $this->driver->drive($run, $definition, new RunBudget(), 'w', 50, $source);

    $this->assertSame(RunStatus::CompletedWithErrors, $result->runStatus);
    $this->assertSame(DriveResult::EXIT_FAILED, $result->exitCode());
  }

  /**
   * Runs that are not over are continued, as cron does.
   */
  public function testResumeActive(): void {
    $run = $this->extractRows($this->pagesOf(1, 3)[0]);

    $finished = $this->driver->resumeActive(new RunBudget(), 'cron');

    $this->assertSame(1, $finished);
    $this->assertSame(RunStatus::Completed, $this->reload($run)->getStatus());
    $this->assertSame(0, $this->driver->resumeActive(new RunBudget(), 'cron'));
  }

  /**
   * Cron continues runs only when the settings ask for it.
   */
  public function testCronResumesWhenEnabled(): void {
    $run = $this->extractRows($this->pagesOf(1, 2)[0]);
    $hooks = $this->container->get(ImportEngineHooks::class);
    $this->assertInstanceOf(ImportEngineHooks::class, $hooks);

    $hooks->cron();
    $this->assertSame(RunStatus::Processing, $this->reload($run)->getStatus());

    $this->config('import_engine.settings')->set('cron_resume_seconds', 30)->save();
    $hooks->cron();
    $this->assertSame(RunStatus::Completed, $this->reload($run)->getStatus());
  }

}
