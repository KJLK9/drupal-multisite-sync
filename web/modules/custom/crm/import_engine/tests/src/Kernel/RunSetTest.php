<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Kernel;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\import_engine\Drive\RunBudget;
use Drupal\import_engine\Drush\Commands\ImportCommands;
use Drupal\import_engine\Entity\ImportDefinition;
use Drupal\import_engine\Entity\ImportRunSet;
use Drupal\import_engine\Run\RunStatus;
use Drupal\import_engine\Run\Trigger;
use Drupal\import_engine\RunSet\SetProgress;
use Drupal\import_engine\RunSet\SetState;
use Drupal\node\Entity\Node;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Tests run sets: imports run in order, and the set stops at a failure.
 */
#[Group('import_engine')]
#[RunTestsInSeparateProcesses]
class RunSetTest extends HttpImportTestBase {

  /**
   * Runs the set to its end.
   */
  protected function runToEnd(ImportRunSet $set): SetProgress {
    return $this->container->get('import_engine.run_set_runner')->run($set, new SetProgress(), new RunBudget(), 'test', Trigger::Drush);
  }

  /**
   * The imports of a set run in the order of the set.
   */
  public function testImportsRunInOrder(): void {
    $this->httpImport('first');
    $this->httpImport('second');
    $this->answers([$this->accountsResponse([1, 2]), $this->accountsResponse([3])]);

    $progress = $this->runToEnd($this->runSet(['first', 'second']));

    $this->assertSame(SetState::Completed, $progress->state);
    $this->assertSame(0, $progress->exitCode());
    $this->assertSame(['first', 'second'], array_column($progress->outcomes, 'import'));
    $this->assertSame(['completed', 'completed'], array_column($progress->outcomes, 'status'));
    $this->assertCount(3, Node::loadMultiple());
  }

  /**
   * The order of the set is the order of the runs, not the alphabet.
   */
  public function testOrderIsTheOrderOfTheSet(): void {
    $this->httpImport('first');
    $this->httpImport('second');
    $this->answers([$this->accountsResponse([1]), $this->accountsResponse([2])]);

    $progress = $this->runToEnd($this->runSet(['second', 'first']));

    $this->assertSame(['second', 'first'], array_column($progress->outcomes, 'import'));
    $manager = $this->container->get('import_engine.run_manager');
    $this->assertLessThan((int) $manager->latest('first')?->id(), (int) $manager->latest('second')?->id());
  }

  /**
   * The set stops at an import that fails; the ones after it do not run.
   */
  public function testStopsAtTheFirstFailure(): void {
    $this->httpImport('first');
    $this->httpImport('second');
    // There is no list of items at "data": the run fails.
    $this->answers([new Response(200, ['Content-Type' => 'application/json'], '{"other": 1}')]);

    $progress = $this->runToEnd($this->runSet(['first', 'second']));

    $this->assertSame(SetState::Stopped, $progress->state);
    $this->assertSame(1, $progress->exitCode());
    $this->assertSame(['first'], array_column($progress->outcomes, 'import'));
    $this->assertSame('failed', $progress->outcomes[0]['status']);
    $this->assertStringContainsString('"first" ended as failed', (string) $progress->message);
    $this->assertNull($this->container->get('import_engine.run_manager')->latest('second'));
  }

  /**
   * Items that went wrong only stop a set that asks for it.
   */
  public function testErrorsStopOnlyWhenAsked(): void {
    $this->httpImport('first');
    $this->httpImport('second');
    $this->answers([$this->accountsResponse([1], ''), $this->accountsResponse([2])]);

    $progress = $this->runToEnd($this->runSet(['first', 'second']));

    $this->assertSame(SetState::Completed, $progress->state);
    $this->assertSame(['completed_with_errors', 'completed'], array_column($progress->outcomes, 'status'));

    ImportRunSet::load('catalog')?->delete();
    $this->answers([$this->accountsResponse([3], ''), $this->accountsResponse([4])]);
    $progress = $this->runToEnd($this->runSet(['first', 'second'], ['stop_on_errors' => TRUE]));

    $this->assertSame(SetState::Stopped, $progress->state);
    $this->assertSame(['completed_with_errors'], array_column($progress->outcomes, 'status'));
    $this->assertStringContainsString('completed with errors', (string) $progress->message);
  }

  /**
   * An import that is gone or disabled stops the set, with a reason.
   */
  public function testMissingOrDisabledImportStops(): void {
    $this->httpImport('first');
    $set = $this->runSet(['first']);
    $this->answers([$this->accountsResponse([1])]);
    $this->runToEnd($set);

    $set->set('imports', ['first', 'ghost'])->save();
    $this->answers([$this->accountsResponse([1])]);
    $progress = $this->runToEnd($set);
    $this->assertSame(SetState::Stopped, $progress->state);
    $this->assertSame('The import "ghost" no longer exists.', $progress->message);

    $first = ImportDefinition::load('first');
    $first?->setStatus(FALSE)->save();
    $set->set('imports', ['first'])->save();
    $progress = $this->runToEnd($set);
    $this->assertSame('The import "first" is disabled.', $progress->message);
    $runs = array_filter(array_column($progress->outcomes, 'run'));
    $this->assertSame([], array_values($runs));
  }

  /**
   * When the time is spent the set can be continued where it was.
   */
  public function testSetContinuesWhereItStopped(): void {
    $this->httpImport('first');
    $this->httpImport('second');
    $this->answers([$this->accountsResponse([1]), $this->accountsResponse([2])]);
    $set = $this->runSet(['first', 'second']);
    $runner = $this->container->get('import_engine.run_set_runner');

    // A budget that is spent already: the first run is made but not driven.
    $progress = $runner->run($set, new SetProgress(), new RunBudget(1), 'test', Trigger::Drush);

    $this->assertSame(SetState::Running, $progress->state);
    $this->assertSame(0, $progress->index);
    $this->assertNotNull($progress->runId);
    $this->assertSame(2, $progress->exitCode());

    $progress = $runner->run($set, $progress, new RunBudget(), 'test', Trigger::Drush);

    $this->assertSame(SetState::Completed, $progress->state);
    $this->assertSame(['first', 'second'], array_column($progress->outcomes, 'import'));
    // The run that was made first was the one that went on.
    $this->assertSame(1, $progress->outcomes[0]['run']);
  }

  /**
   * An import with a run that is not over continues that run.
   */
  public function testImportWithAnActiveRunContinuesIt(): void {
    $this->httpImport('first');
    $first = ImportDefinition::load('first');
    $this->assertNotNull($first);
    $active = $this->container->get('import_engine.run_starter')->start($first, Trigger::Ui);
    $this->answers([$this->accountsResponse([1])]);

    $progress = $this->runToEnd($this->runSet(['first']));

    $this->assertSame((int) $active->id(), $progress->outcomes[0]['run']);
    $this->assertSame(RunStatus::Completed->value, $progress->outcomes[0]['status']);
  }

  /**
   * A set is checked like every other configuration.
   */
  public function testSetIsValidated(): void {
    $this->httpImport('first');
    $paths = static function (ImportRunSet $set): array {
      $paths = [];
      foreach ($set->getTypedData()->validate() as $violation) {
        $paths[] = $violation->getPropertyPath();
      }
      return $paths;
    };

    $make = static fn (array $imports): ImportRunSet => ImportRunSet::create([
      'id' => 'a',
      'label' => 'A',
      'imports' => $imports,
    ]);

    $this->assertSame([], $paths($make(['first'])));
    $this->assertContains('imports', $paths($make([])));
    $this->assertContains('imports.0', $paths($make(['nope'])));
    $this->assertContains('imports.1', $paths($make(['first', 'first'])));
  }

  /**
   * An import that a set lists cannot be deleted, until it is taken out.
   */
  public function testImportInSetCannotBeDeleted(): void {
    $this->httpImport('first');
    $this->httpImport('second');
    $set = $this->runSet(['first', 'second']);

    try {
      ImportDefinition::load('first')?->delete();
      $this->fail('Expected an EntityStorageException.');
    }
    catch (EntityStorageException $exception) {
      $this->assertSame('The import "first" cannot be deleted: it is in the run sets catalog.', $exception->getMessage());
    }
    $this->assertNotNull(ImportDefinition::load('first'));

    $set->set('imports', ['second'])->save();
    ImportDefinition::load('first')->delete();
    $this->assertNull(ImportDefinition::load('first'));
  }

  /**
   * Builds the commands with their output captured.
   */
  protected function commands(BufferedOutput $output): ImportCommands {
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
    return $commands;
  }

  /**
   * The command runs a set and says how it went, with an exit code.
   */
  public function testRunSetCommand(): void {
    $this->httpImport('first');
    $this->httpImport('second');
    $this->runSet(['first', 'second']);
    $this->answers([$this->accountsResponse([1]), $this->accountsResponse([2])]);
    $output = new BufferedOutput();

    $result = $this->commands($output)->runSet('catalog');

    $this->assertSame(0, $result->getExitCode());
    $text = $output->fetch();
    $this->assertStringContainsString('first: completed (run 1)', $text);
    $this->assertStringContainsString('second: completed (run 2)', $text);
    $this->assertStringContainsString('The set "catalog" is complete.', $text);
  }

  /**
   * The command gives exit code 1 when the set stopped at a failure.
   */
  public function testRunSetCommandStopped(): void {
    $this->httpImport('first');
    $this->httpImport('second');
    $this->runSet(['first', 'second']);
    $this->answers([new Response(200, ['Content-Type' => 'application/json'], '{"other": 1}')]);
    $output = new BufferedOutput();

    $result = $this->commands($output)->runSet('catalog');

    $this->assertSame(1, $result->getExitCode());
    $this->assertStringContainsString('did not run', $output->fetch());
  }

  /**
   * A set that does not exist is an error.
   */
  public function testRunSetCommandUnknownSet(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('There is no run set "nope".');

    $this->commands(new BufferedOutput())->runSet('nope');
  }

}
