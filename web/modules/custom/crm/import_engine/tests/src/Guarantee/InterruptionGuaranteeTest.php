<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Guarantee;

use Drupal\import_engine\Drive\DriveStatus;
use Drupal\import_engine\Drive\RunBudget;
use Drupal\import_engine\Entity\ImportDefinition;
use Drupal\import_engine\Entity\ImportRun;
use Drupal\import_engine\Run\RunStatus;
use Drupal\import_engine\Run\Trigger;
use Drupal\import_engine\Source\SourceException;
use Drupal\node\Entity\Node;
use Drupal\Tests\import_engine\Kernel\FakeSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * A run that is cut up, stopped or crashed ends like one that was not.
 *
 * This is the promise behind "a run survives failures": whatever happens in
 * between, the site ends up with the same accounts and the run with the same
 * numbers. Two imports with the same mapping read the same items; one is driven
 * in one go, the other is interrupted in the ways below.
 */
#[Group('guarantee')]
#[RunTestsInSeparateProcesses]
class InterruptionGuaranteeTest extends GuaranteeTestBase {

  /**
   * How the source is paged and how many items a worker takes at a time.
   *
   * @return array<string, array{int, int}>
   *   The size of a page and the size of a batch.
   */
  public static function cuts(): array {
    return [
      'pages of 1, batches of 1' => [1, 1],
      'pages of 3, batches of 4' => [3, 4],
      'pages of 10, batches of 2' => [10, 2],
      'one page, batches of 5' => [30, 5],
    ];
  }

  /**
   * Drives a run page by page, and lets workers die between batches.
   */
  protected function runInterrupted(ImportDefinition $definition, FakeSource $source, int $batch): ImportRun {
    $run = $this->starter->start($definition, Trigger::Drush);
    // A spent budget reads one portion of pages per call and then stops.
    for ($guard = 0; $guard < 200; $guard++) {
      $result = $this->driver->drive($this->reload($run), $definition, new RunBudget(1), 'w', 50, $source);
      if ($result->status !== DriveStatus::OutOfBudget || $this->reload($run)->getStatus() === RunStatus::Processing) {
        break;
      }
    }
    $this->assertSame(RunStatus::Processing, $this->reload($run)->getStatus(), 'Extraction is over, processing is next.');

    $now = 10000;
    // Workers that take a batch and die: the claim runs out, another worker
    // takes the items over.
    for ($crash = 0; $crash < 3; $crash++) {
      $this->items->claim('dead-worker-' . $crash, $batch, 5, now: $now);
      $now += 60;
      $this->process->process('w', $batch, 'default', 600, $now);
      $now += 60;
    }
    $result = $this->driver->drive($this->reload($run), $definition, new RunBudget(), 'w', 50, $source);
    $this->assertSame(DriveStatus::Finished, $result->status);
    return $this->reload($run);
  }

  /**
   * The interrupted import and the plain one agree on everything.
   */
  #[DataProvider('cuts')]
  public function testInterruptionsChangeNothing(int $pageSize, int $batch): void {
    $items = $this->accountItems(1, 30);
    $plain = $this->saveImport(['id' => 'plain', 'label' => 'Plain']);
    $cut = $this->saveImport(['id' => 'cut', 'label' => 'Cut']);
    $pages = $this->pagesOfItems($items, $pageSize);

    $one = $this->runToEnd($plain, new FakeSource($pages));
    $two = $this->runInterrupted($cut, new FakeSource($pages), $batch);

    $this->assertSame(RunStatus::Completed, $one->getStatus());
    $this->assertSame(RunStatus::Completed, $two->getStatus());
    $ids = range(1, 30);
    $this->assertSame($this->writtenBy('plain', $ids), $this->writtenBy('cut', $ids), 'The same accounts, with the same content.');
    foreach (['items_extracted', 'created', 'updated', 'unchanged', 'failed', 'dead'] as $counter) {
      $this->assertSame($one->getCounters()[$counter], $two->getCounters()[$counter], $counter);
    }
    $this->assertCount(60, Node::loadMultiple(), 'Each import wrote its 30 accounts: nothing twice, nothing lost.');
  }

  /**
   * A source that fails once in the middle is read on from where it stopped.
   */
  public function testTransientFailureInTheMiddleIsResumed(): void {
    $definition = $this->saveImport();
    $items = $this->accountItems(1, 30);
    $source = (new FakeSource($this->pagesOfItems($items, 10)))
      ->failOnce(1, SourceException::transient('The server hiccupped.'));
    $run = $this->starter->start($definition, Trigger::Drush);

    $first = $this->driver->drive($run, $definition, new RunBudget(), 'w', 50, $source);
    $this->assertNotSame(RunStatus::Completed, $this->reload($run)->getStatus(), 'The first call did not finish.');
    $this->assertNotSame(DriveStatus::Finished, $first->status);
    $second = $this->driver->drive($this->reload($run), $definition, new RunBudget(), 'w', 50, $source);

    $this->assertSame(DriveStatus::Finished, $second->status);
    $this->assertCount(30, $this->accountsOnSite());
    $this->assertSame(30, $this->reload($run)->getCounters()['created']);
    // Page 1 was asked for twice, and no page was read that did not have to be.
    $this->assertSame([NULL, '1', '1', '2'], $source->calls);
  }

}
