<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Kernel;

use Drupal\import_engine\Drive\RunBudget;
use Drupal\import_engine\Drush\Commands\ImportCommands;
use Drupal\import_engine\Entity\ImportDefinition;
use Drupal\import_engine\Entity\ImportRunSet;
use Drupal\import_engine\Run\Trigger;
use Drupal\import_engine\Schedule\SchedulerResult;
use Drupal\node\Entity\Node;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Tests that imports and run sets with a schedule are run when it is due.
 */
#[Group('import_engine')]
#[RunTestsInSeparateProcesses]
class ScheduleTest extends HttpImportTestBase {

  /**
   * A time on a day.
   */
  protected static function at(string $time): int {
    return (int) strtotime('2026-10-12 ' . $time . ' UTC');
  }

  /**
   * Gives an import a schedule.
   *
   * @param string $id
   *   The import.
   * @param int $every
   *   Every how many minutes.
   * @param int $start
   *   From which minute of the day.
   */
  protected function schedule(string $id, int $every, int $start = 0): void {
    ImportDefinition::load($id)?->set('schedule', ['enabled' => TRUE, 'every' => $every, 'start' => $start])->save();
  }

  /**
   * Runs what is due at a time.
   */
  protected function runDueAt(string $time): SchedulerResult {
    return $this->container->get('import_engine.scheduler')->runDue(self::at($time), new RunBudget(), 'test');
  }

  /**
   * An import runs when it is due and not when it is not.
   */
  public function testImportRunsWhenDue(): void {
    $this->httpImport('first');
    $this->schedule('first', 60, 15);
    $this->answers([$this->accountsResponse([1, 2]), $this->accountsResponse([3])]);

    $early = $this->runDueAt('10:00:00');
    $this->assertSame([], $early->outcomes);
    $this->assertCount(0, Node::loadMultiple());

    $due = $this->runDueAt('10:15:00');
    $this->assertSame([['kind' => 'import', 'id' => 'first', 'status' => 'completed', 'message' => NULL]], $due->outcomes);
    $this->assertSame(0, $due->exitCode);
    $this->assertCount(2, Node::loadMultiple());
    $run = $this->container->get('import_engine.run_manager')->latest('first');
    $this->assertSame(Trigger::Cron, $run?->getTrigger(), 'Scheduled runs say so.');
  }

  /**
   * The same slot starts nothing twice, a later one does.
   */
  public function testSlotIsStartedOnce(): void {
    $this->httpImport('first');
    $this->schedule('first', 15);
    $this->answers([$this->accountsResponse([1]), $this->accountsResponse([2])]);

    $first = $this->runDueAt('10:15:05');
    $again = $this->runDueAt('10:20:00');
    $next = $this->runDueAt('10:30:00');

    $this->assertCount(1, $first->outcomes);
    $this->assertSame([], $again->outcomes, 'A second call in the same quarter does nothing.');
    $this->assertCount(1, $next->outcomes);
    $this->assertCount(2, Node::loadMultiple());
  }

  /**
   * A disabled import is not run, whatever its schedule says.
   */
  public function testDisabledImportIsLeftAlone(): void {
    $this->httpImport('first');
    $this->schedule('first', 15);
    ImportDefinition::load('first')?->setStatus(FALSE)->save();

    $this->assertSame([], $this->runDueAt('10:15:00')->outcomes);
  }

  /**
   * An import that is not scheduled is never run by the scheduler.
   */
  public function testImportWithoutScheduleIsNotRun(): void {
    $this->httpImport('first');

    $this->assertSame([], $this->runDueAt('10:15:00')->outcomes);
    $this->assertNull($this->container->get('import_engine.run_manager')->latest('first'));
  }

  /**
   * A run set is run when due, and its imports are not run on their own too.
   */
  public function testRunSetIsRunAndItsImportsAreLeftToIt(): void {
    $this->httpImport('first');
    $this->httpImport('second');
    $this->runSet(['first', 'second'], ['schedule' => ['enabled' => TRUE, 'every' => 60, 'start' => 15]]);
    // The imports have a schedule of their own, at the same time.
    $this->schedule('first', 15);
    $this->schedule('second', 15);
    $this->answers([$this->accountsResponse([1]), $this->accountsResponse([2])]);

    $result = $this->runDueAt('10:15:00');

    $this->assertSame(['set'], array_column($result->outcomes, 'kind'), 'Only the set: its imports run in its order, not beside it.');
    $this->assertSame('completed', $result->outcomes[0]['status']);
    $this->assertCount(2, Node::loadMultiple());
    $manager = $this->container->get('import_engine.run_manager');
    $this->assertLessThan((int) $manager->latest('second')?->id(), (int) $manager->latest('first')?->id());

    // At a quarter where only the imports are due, they run on their own.
    $this->answers([$this->accountsResponse([3]), $this->accountsResponse([4])]);
    $later = $this->runDueAt('10:30:00');
    $this->assertSame(['import', 'import'], array_column($later->outcomes, 'kind'));
  }

  /**
   * A run that is not over is continued, not started again.
   */
  public function testActiveRunIsContinued(): void {
    $this->httpImport('first');
    $this->schedule('first', 15);
    $definition = ImportDefinition::load('first');
    $this->assertNotNull($definition);
    $active = $this->container->get('import_engine.run_starter')->start($definition, Trigger::Ui);
    $this->answers([$this->accountsResponse([1])]);

    $result = $this->runDueAt('10:15:00');

    $this->assertSame('completed', $result->outcomes[0]['status']);
    $this->assertSame((int) $active->id(), (int) $this->container->get('import_engine.run_manager')->latest('first')?->id());
  }

  /**
   * The exit code says whether something failed.
   */
  public function testExitCodeSaysWhatWentWrong(): void {
    $this->httpImport('first');
    $this->schedule('first', 15);
    $this->answers([new Response(200, ['Content-Type' => 'application/json'], '{"other": 1}')]);

    $result = $this->runDueAt('10:15:00');

    $this->assertSame(1, $result->exitCode);
    $this->assertSame('failed', $result->outcomes[0]['status']);
  }

  /**
   * The command says what it did.
   */
  public function testCommand(): void {
    $this->httpImport('first');
    $this->schedule('first', 15);
    $this->answers([$this->accountsResponse([1])]);
    $output = new BufferedOutput();
    $commands = new ImportCommands(
      $this->container->get('import_engine.run_driver'),
      $this->container->get('import_engine.worker'),
      $this->container->get('import_engine.run_starter'),
      $this->container->get('import_engine.run_manager'),
      $this->container->get('import_engine.item_storage'),
      $this->container->get('import_engine.breaker_store'),
      $this->container->get('entity_type.manager'),
      $this->container->get('datetime.time'),
      $this->container->get('import_engine.run_set_runner'),
      $this->container->get('import_engine.scheduler'),
    );
    $commands->setInput(new ArrayInput([]));
    $commands->setOutput($output);

    $result = $commands->runDue();

    // The real time: the slot may or may not be a quarter that is due, and
    // every quarter is, for a schedule of 15 minutes.
    $this->assertSame(0, $result->getExitCode());
    $this->assertStringContainsString('import first: completed', $output->fetch());
  }

  /**
   * A schedule is checked like the rest of the configuration.
   *
   * @param array<string, mixed> $schedule
   *   The schedule.
   * @param bool $valid
   *   Whether it is valid.
   */
  #[DataProvider('schedulesForTheSchema')]
  public function testScheduleIsValidated(array $schedule, bool $valid): void {
    $this->httpImport('first');
    $set = ImportRunSet::create(['id' => 'a', 'label' => 'A', 'imports' => ['first'], 'schedule' => $schedule]);
    $definition = ImportDefinition::load('first');
    $this->assertNotNull($definition);
    $definition->set('schedule', $schedule);

    foreach ([$set, $definition] as $entity) {
      $paths = [];
      foreach ($entity->getTypedData()->validate() as $violation) {
        $paths[] = $violation->getPropertyPath();
      }
      $schedule_paths = array_filter($paths, static fn (string $path): bool => str_starts_with($path, 'schedule'));
      $this->assertSame($valid, $schedule_paths === [], implode(', ', $paths));
    }
  }

  /**
   * Schedules and whether they are valid.
   *
   * @return array<string, array{array<string, mixed>, bool}>
   *   The schedules.
   */
  public static function schedulesForTheSchema(): array {
    return [
      'hourly at a quarter past' => [['enabled' => TRUE, 'every' => 60, 'start' => 15], TRUE],
      'not enabled' => [['enabled' => FALSE, 'every' => 60, 'start' => 0], TRUE],
      'daily at two' => [['enabled' => TRUE, 'every' => 1440, 'start' => 120], TRUE],
      'every ten minutes' => [['enabled' => TRUE, 'every' => 10, 'start' => 0], FALSE],
      'every 45 minutes, which does not fit a day' => [['enabled' => TRUE, 'every' => 45, 'start' => 0], FALSE],
      'a start that is not a quarter' => [['enabled' => TRUE, 'every' => 60, 'start' => 10], FALSE],
      'a start after the end of the day' => [['enabled' => TRUE, 'every' => 60, 'start' => 1440], FALSE],
      'a negative start' => [['enabled' => TRUE, 'every' => 60, 'start' => -15], FALSE],
    ];
  }

  /**
   * An import without the key (an older export) has no schedule.
   */
  public function testOlderConfigurationHasNoSchedule(): void {
    $this->httpImport('first');

    $this->assertFalse(ImportDefinition::load('first')?->getSchedule()['enabled']);
    $set = ImportRunSet::create(['id' => 'a', 'label' => 'A', 'imports' => ['first']]);
    $this->assertFalse($set->getSchedule()['enabled']);
    $this->assertSame([], array_filter(
      array_map(static fn ($violation): string => $violation->getPropertyPath(), iterator_to_array($set->getTypedData()->validate())),
      static fn (string $path): bool => str_starts_with($path, 'schedule'),
    ));
  }

}
