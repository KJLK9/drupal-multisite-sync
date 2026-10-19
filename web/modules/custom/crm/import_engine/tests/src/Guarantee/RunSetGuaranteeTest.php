<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Guarantee;

use Drupal\import_engine\Entity\ImportRunSet;
use Drupal\import_engine\Drive\RunBudget;
use Drupal\import_engine\Run\RunStatus;
use Drupal\import_engine\Run\Trigger;
use Drupal\import_engine\RunSet\SetProgress;
use Drupal\import_engine\RunSet\SetState;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * A run set runs its imports in order, and stops where it must.
 */
#[Group('guarantee')]
#[RunTestsInSeparateProcesses]
class RunSetGuaranteeTest extends GuaranteeTestBase {

  /**
   * Runs the set of the test from the start, to its end.
   */
  protected function runSetToEnd(): SetProgress {
    $set = ImportRunSet::load('catalog');
    $this->assertNotNull($set);
    return $this->container->get('import_engine.run_set_runner')->run($set, new SetProgress(), new RunBudget(), 'w', Trigger::Drush);
  }

  /**
   * The imports run one after the other, in the order of the set.
   */
  public function testImportsRunInTheOrderOfTheSet(): void {
    foreach (['third', 'first', 'second'] as $id) {
      $this->httpImport($id);
    }
    $this->runSet(['first', 'second', 'third']);
    $this->answers([$this->accountsResponse([1]), $this->accountsResponse([2]), $this->accountsResponse([3])]);

    $progress = $this->runSetToEnd();

    $this->assertSame(SetState::Completed, $progress->state);
    $this->assertSame(['first', 'second', 'third'], array_column($progress->outcomes, 'import'));
    $runs = array_column($progress->outcomes, 'run');
    $sorted = $runs;
    sort($sorted);
    $this->assertSame($sorted, $runs, 'The runs were made in that order.');
    $this->assertSame(['completed', 'completed', 'completed'], array_column($progress->outcomes, 'status'));
  }

  /**
   * An import that fails stops the set; the ones after it do not run.
   */
  public function testFailingImportStopsTheSet(): void {
    foreach (['first', 'second', 'third'] as $id) {
      $this->httpImport($id);
    }
    $this->runSet(['first', 'second', 'third']);
    $this->answers([$this->accountsResponse([1]), new Response(404), $this->accountsResponse([3])]);

    $progress = $this->runSetToEnd();

    $this->assertSame(SetState::Stopped, $progress->state);
    $this->assertSame(['first', 'second'], array_column($progress->outcomes, 'import'));
    $this->assertSame(RunStatus::Failed->value, $progress->outcomes[1]['status']);
    $this->assertNull($this->container->get('import_engine.run_manager')->latest('third'), 'The third never started.');
  }

  /**
   * Whether items that went wrong stop the set is up to the set.
   */
  public function testStopOnErrorsIsTheSetsChoice(): void {
    foreach (['first', 'second'] as $id) {
      $this->httpImport($id);
    }
    // The first import has an item that is not valid.
    $answers = fn (): array => [$this->accountsResponse([1, 2], ''), $this->accountsResponse([3])];

    $this->runSet(['first', 'second'], ['stop_on_errors' => FALSE]);
    $this->answers($answers());
    $goesOn = $this->runSetToEnd();
    $this->assertSame(SetState::Completed, $goesOn->state, 'Without the choice the set goes on.');
    $this->assertCount(2, $goesOn->outcomes);

    $set = ImportRunSet::load('catalog');
    $set?->set('stop_on_errors', TRUE)->save();
    $this->answers($answers());
    $stops = $this->runSetToEnd();
    $this->assertSame(SetState::Stopped, $stops->state, 'With it, the set stops at the first import that has errors.');
    $this->assertCount(1, $stops->outcomes);
  }

  /**
   * The same set run again changes nothing on the site.
   */
  public function testSecondRunOfTheSetChangesNothing(): void {
    foreach (['first', 'second'] as $id) {
      $this->httpImport($id);
    }
    $this->runSet(['first', 'second']);
    $this->answers([$this->accountsResponse([1, 2, 3]), $this->accountsResponse([4, 5])]);
    $this->runSetToEnd();
    $before = $this->accountsOnSite();

    $this->answers([$this->accountsResponse([1, 2, 3]), $this->accountsResponse([4, 5])]);
    $again = $this->runSetToEnd();

    $this->assertSame(SetState::Completed, $again->state);
    $this->assertSame($before, $this->accountsOnSite(), 'Not an account was rewritten.');
  }

}
