<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Kernel;

use Drupal\Core\Lock\PersistentDatabaseLockBackend;
use Drupal\import_engine\Entity\ImportRun;
use Drupal\import_engine\Run\RunStatus;
use Drupal\import_engine\Run\Trigger;
use Drupal\import_engine\Source\SourceException;
use Drupal\import_engine\Storage\EventType;
use Drupal\import_engine\Storage\ItemState;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the extract stage: pages into the queue, resuming, repeats, skipping.
 */
#[Group('import_engine')]
#[RunTestsInSeparateProcesses]
class ExtractStageTest extends ExtractTestBase {

  /**
   * Every page is read and its items queued with the page position.
   */
  public function testExtractsAllPages(): void {
    $definition = $this->definition();
    $run = $this->starter->start($definition, Trigger::Drush);
    $source = new FakeSource($this->pagesOf(3, 5));

    $result = $this->stage->extract($run, $definition, $source);

    $this->assertResult($result, 'complete', 3, 15);
    $this->assertSame(RunStatus::Processing, $run->getStatus());
    $this->assertTrue($run->isExtractComplete());
    $this->assertSame(3, $run->getPagesRead());
    $this->assertNull($run->getCursor());
    $this->assertSame(15, $run->getCounters()['items_extracted']);
    $this->assertSame(15, $this->rows('import_item'));
    $this->assertSame(3, $this->rows('import_page'));
    $this->assertSame([NULL, '1', '2'], $source->calls);
    $this->assertSame(['pending' => 15, 'processing' => 0, 'done' => 0, 'retrying' => 0, 'dead' => 0], $this->items->countByState((int) $run->id()));

    // The run was saved: it is in the database as it is in memory.
    $loaded = ImportRun::load($run->id());
    $this->assertSame(RunStatus::Processing, $loaded?->getStatus());
    $this->assertNotNull($loaded->get('started')->value);
  }

  /**
   * A call reads at most its page budget; the next call continues.
   */
  public function testContinuesInPortions(): void {
    $definition = $this->definition();
    $run = $this->starter->start($definition, Trigger::Drush);
    $source = new FakeSource($this->pagesOf(5, 2));

    $this->assertResult($this->stage->extract($run, $definition, $source, 2), 'more_to_do', 2, 4);
    $this->assertSame(RunStatus::Extracting, $run->getStatus());
    $this->assertSame('2', $run->getCursor());
    $this->assertResult($this->stage->extract($run, $definition, $source, 2), 'more_to_do', 2, 4);
    $this->assertResult($this->stage->extract($run, $definition, $source, 2), 'complete', 1, 2);

    $this->assertSame(10, $this->rows('import_item'));
    // Each page was fetched once, in order.
    $this->assertSame([NULL, '1', '2', '3', '4'], $source->calls);
  }

  /**
   * At least one page is read per call, even when the deadline has passed.
   */
  public function testDeadlineStillMakesProgress(): void {
    $definition = $this->definition();
    $run = $this->starter->start($definition, Trigger::Drush);
    $source = new FakeSource($this->pagesOf(3, 2));

    $result = $this->stage->extract($run, $definition, $source, NULL, 1);

    $this->assertResult($result, 'more_to_do', 1, 2);
  }

  /**
   * A temporary problem keeps the position; the next call goes on.
   */
  public function testTransientErrorInterruptsAndResumes(): void {
    $definition = $this->definition();
    $run = $this->starter->start($definition, Trigger::Drush);
    $source = (new FakeSource($this->pagesOf(3, 2)))->failOnce(1, SourceException::transient('timeout'));

    $first = $this->stage->extract($run, $definition, $source);

    $this->assertResult($first, 'interrupted', 1, 2);
    $this->assertSame('timeout', $first->message);
    $this->assertSame(RunStatus::Extracting, $run->getStatus());
    $this->assertSame('1', $run->getCursor());
    $this->assertStringContainsString('Interrupted: timeout', $run->getSummary());

    $second = $this->stage->extract($run, $definition, $source);

    $this->assertResult($second, 'complete', 2, 4);
    $this->assertSame(6, $this->rows('import_item'));
  }

  /**
   * A permanent error ends extraction; what is queued is still processed.
   */
  public function testPermanentErrorEndsExtraction(): void {
    $definition = $this->definition();
    $run = $this->starter->start($definition, Trigger::Drush);
    $source = (new FakeSource($this->pagesOf(3, 2)))->failOnce(1, SourceException::permanent('HTTP 401'));

    $result = $this->stage->extract($run, $definition, $source);

    $this->assertResult($result, 'failed', 1, 2);
    // The run moves on to processing, but without a complete extraction, so
    // the stage that finishes runs knows to end it as failed and not sweep.
    $this->assertSame(RunStatus::Processing, $run->getStatus());
    $this->assertFalse($run->isExtractComplete());
    $this->assertStringContainsString('The source failed: HTTP 401', $run->getSummary());
    $this->assertSame(2, $this->rows('import_item'));
  }

  /**
   * A source that returns the same page again and again is stopped.
   */
  public function testRepeatedPageStopsExtraction(): void {
    $definition = $this->definition();
    $run = $this->starter->start($definition, Trigger::Drush);
    $page = $this->sourceRows(1, 3);
    $source = new FakeSource([$page], [0, 0, 0, 0, 0, 0, 0, 0]);

    $result = $this->stage->extract($run, $definition, $source);

    // One new page, then three repeats in a row: the limit of three.
    $this->assertResult($result, 'failed', 4, 3);
    $this->assertStringContainsString('the same page 3 times in a row', $run->getSummary());
    $this->assertFalse($run->isExtractComplete());
    $this->assertSame(3, $this->rows('import_item'));
    $this->assertSame(1, $this->rows('import_page'));
  }

  /**
   * The limit is a setting of the import.
   */
  public function testRepeatLimitIsConfigurable(): void {
    $definition = $this->definition([
      'resilience' => [
        'max_attempts' => 5,
        'backoff' => 'fixed',
        'dlq_enabled' => TRUE,
        'max_repeated_pages' => 1,
      ],
    ]);
    $run = $this->starter->start($definition, Trigger::Drush);
    $source = new FakeSource([$this->sourceRows(1, 2)], [0, 0, 0]);

    $this->assertResult($this->stage->extract($run, $definition, $source), 'failed', 2, 2);
  }

  /**
   * A cycle of pages is found as well, not only the same page twice.
   */
  public function testCycleOfPagesIsFound(): void {
    $definition = $this->definition();
    $run = $this->starter->start($definition, Trigger::Drush);
    $source = new FakeSource([$this->sourceRows(1, 2), $this->sourceRows(3, 4)], [0, 1, 0, 1, 0, 1, 0, 1]);

    $result = $this->stage->extract($run, $definition, $source);

    // A, B, then A, B, A are three repeats in a row.
    $this->assertResult($result, 'failed', 5, 4);
  }

  /**
   * A new page resets the count of repeats in a row.
   */
  public function testNewPageResetsTheRepeatCount(): void {
    $definition = $this->definition();
    $run = $this->starter->start($definition, Trigger::Drush);
    $pages = [$this->sourceRows(1, 2), $this->sourceRows(3, 4), $this->sourceRows(5, 6)];
    // A, B, A (repeat 1), C (new), A (repeat 1), B (repeat 2): no stop.
    $source = new FakeSource($pages, [0, 1, 0, 2, 0, 1]);

    $this->assertResult($this->stage->extract($run, $definition, $source), 'complete', 6, 6);
  }

  /**
   * An item without a key is logged and counted, not queued.
   */
  public function testItemsWithoutKeyAreLoggedNotQueued(): void {
    $definition = $this->definition();
    $run = $this->starter->start($definition, Trigger::Drush);
    $source = new FakeSource([[
      ['id' => 1, 'name' => 'A'],
      ['name' => 'no id'],
      ['id' => '', 'name' => 'empty'],
      ['id' => 2, 'name' => 'B'],
    ],
    ]);

    $result = $this->stage->extract($run, $definition, $source);

    $this->assertResult($result, 'complete', 1, 2, 0, 2);
    $this->assertSame(2, $this->rows('import_item'));
    $this->assertSame(2, $run->getCounters()['failed']);
    $events = $this->events->forRun((int) $run->id());
    $this->assertCount(2, $events);
    $this->assertSame(EventType::Failed, $events[0]->event);
    $this->assertStringContainsString('Item 2 on page 1 has no usable key: no value at "id"', (string) $events[0]->message);
    $this->assertStringContainsString('the value at "id" is empty', (string) $events[1]->message);
  }

  /**
   * Pages that did not change are skipped, and their items still count as seen.
   */
  public function testUnchangedVerifiedPagesAreSkipped(): void {
    $first = $this->extractAll(new FakeSource($this->pagesOf(3, 4)));
    foreach ($this->sourceRows(1, 12) as $item) {
      $this->container->get('import_engine.mapping_store')->record('customers', '["' . $item['id'] . '"]', 'node', (string) $item['id'], NULL, (int) $first->id(), TRUE);
    }
    $this->handleAll($first);
    $this->assertSame(3, $this->rows('import_page'));

    $definition = $this->definition();
    $second = $this->starter->start($definition, Trigger::Drush);
    $result = $this->stage->extract($second, $definition, new FakeSource($this->pagesOf(3, 4)));

    $this->assertResult($result, 'complete', 3, 0, 12);
    $this->assertSame(12, $second->getCounters()['page_skipped']);
    $this->assertSame(12, $second->getCounters()['items_extracted']);
    $this->assertSame(12, $this->rows('import_item'), 'no items were added by the second run');
    // The sweep must not take them for gone: they are marked as seen.
    $mapping = $this->container->get('import_engine.mapping_store');
    $this->assertSame([], $mapping->notSeenSince('customers', (int) $second->id(), 100));
    $this->assertSame((int) $second->id(), $mapping->find('customers', '["7"]')?->lastSeenRun);
    $this->assertSame(RunStatus::Processing, $second->getStatus());
  }

  /**
   * Only the page that changed is processed again.
   */
  public function testChangedPageIsProcessedAgain(): void {
    $first = $this->extractAll(new FakeSource($this->pagesOf(3, 4)));
    $this->handleAll($first);

    $pages = $this->pagesOf(3, 4);
    $pages[1][2]['name'] = 'Renamed';
    $definition = $this->definition();
    $second = $this->starter->start($definition, Trigger::Drush);
    $result = $this->stage->extract($second, $definition, new FakeSource($pages));

    $this->assertResult($result, 'complete', 3, 4, 8);
    $this->assertSame(['pending' => 4, 'processing' => 0, 'done' => 0, 'retrying' => 0, 'dead' => 0], $this->items->countByState((int) $second->id()));
  }

  /**
   * A page with a failed item is not skipped next time.
   */
  public function testPageWithFailedItemIsNotSkipped(): void {
    $first = $this->extractAll(new FakeSource($this->pagesOf(3, 4)));
    $this->handleAll($first, ['["6"]']);
    $this->assertSame([1], $this->items->pagesWithFailures((int) $first->id()));

    $definition = $this->definition();
    $second = $this->starter->start($definition, Trigger::Drush);
    $result = $this->stage->extract($second, $definition, new FakeSource($this->pagesOf(3, 4)));

    // Page 2 (position 1) had the dead item: it is processed again.
    $this->assertResult($result, 'complete', 3, 4, 8);
  }

  /**
   * A page with an item without a key is never skipped.
   */
  public function testPageWithAnInvalidItemIsNeverVerified(): void {
    $pages = [[['id' => 1, 'name' => 'A'], ['name' => 'no id']]];
    $first = $this->extractAll(new FakeSource($pages));
    $this->handleAll($first);

    $definition = $this->definition();
    $second = $this->starter->start($definition, Trigger::Drush);
    $result = $this->stage->extract($second, $definition, new FakeSource($pages));

    $this->assertResult($result, 'complete', 1, 1, 0, 1);
    // The problem is reported again, so it does not stay unseen.
    $this->assertCount(1, $this->events->forRun((int) $second->id()));
  }

  /**
   * A full run processes every page, also those that did not change.
   */
  public function testFullRunProcessesEverything(): void {
    $first = $this->extractAll(new FakeSource($this->pagesOf(2, 4)));
    $this->handleAll($first);

    $definition = $this->definition();
    $second = $this->starter->start($definition, Trigger::Ui, NULL, TRUE);
    $result = $this->stage->extract($second, $definition, new FakeSource($this->pagesOf(2, 4)));

    $this->assertResult($result, 'complete', 2, 8, 0);
  }

  /**
   * A changed mapping voids what was remembered about the pages.
   */
  public function testChangedMappingVoidsThePages(): void {
    $first = $this->extractAll(new FakeSource($this->pagesOf(2, 4)));
    $this->handleAll($first);

    $changed = $this->definition([
      'mapping' => [
      ['target_field' => 'title', 'mapper' => ['plugin' => 'string', 'sources' => ['value' => 'id'], 'settings' => []]],
      ],
    ]);
    $second = $this->starter->start($changed, Trigger::Drush);
    $result = $this->stage->extract($second, $changed, new FakeSource($this->pagesOf(2, 4)));

    $this->assertResult($result, 'complete', 2, 8, 0);
  }

  /**
   * A run with fewer pages than the one before leaves no stale page records.
   */
  public function testStalePageRecordsAreDeleted(): void {
    $first = $this->extractAll(new FakeSource($this->pagesOf(5, 2)));
    $this->handleAll($first);
    $this->assertSame(5, $this->rows('import_page'));

    $second = $this->extractAll(new FakeSource($this->pagesOf(3, 2)));

    $this->assertSame(3, $this->rows('import_page'));
    $this->assertSame(RunStatus::Processing, $second->getStatus());
  }

  /**
   * The page records follow the dataset, not the number of runs.
   */
  public function testPageRecordsDoNotGrowWithRuns(): void {
    for ($run = 0; $run < 4; $run++) {
      $run_entity = $this->extractAll(new FakeSource($this->pagesOf(3, 2)));
      $this->handleAll($run_entity);
    }

    $this->assertSame(3, $this->rows('import_page'));
  }

  /**
   * Another process that holds the lock keeps this one out.
   */
  public function testOnlyOneExtractionAtOnce(): void {
    $definition = $this->definition();
    $run = $this->starter->start($definition, Trigger::Drush);
    $other = new PersistentDatabaseLockBackend($this->container->get('database'));
    $this->assertTrue($other->acquire('import_engine:extract:customers', 60));

    $result = $this->stage->extract($run, $definition, new FakeSource($this->pagesOf(1, 2)));

    $this->assertResult($result, 'busy', 0, 0);
    $this->assertSame(RunStatus::Queued, $run->getStatus());
    $this->assertSame(0, $this->rows('import_item'));

    $other->release('import_engine:extract:customers');
    $this->assertResult($this->stage->extract($run, $definition, new FakeSource($this->pagesOf(1, 2))), 'complete', 1, 2);
  }

  /**
   * The lock is released after a call, so the next call can take it.
   */
  public function testLockIsReleasedAfterCall(): void {
    $definition = $this->definition();
    $run = $this->starter->start($definition, Trigger::Drush);
    $this->stage->extract($run, $definition, new FakeSource($this->pagesOf(3, 2)), 1);

    $other = new PersistentDatabaseLockBackend($this->container->get('database'));
    $this->assertTrue($other->acquire('import_engine:extract:customers', 60));
  }

  /**
   * Only a run that is queued or extracting can be extracted, of its import.
   */
  public function testRunMustBeExtractableAndOfTheImport(): void {
    $definition = $this->definition();
    $run = $this->starter->start($definition, Trigger::Drush);
    $this->stage->extract($run, $definition, new FakeSource($this->pagesOf(1, 1)));

    try {
      $this->stage->extract($run, $definition, new FakeSource($this->pagesOf(1, 1)));
      $this->fail('A processing run cannot be extracted.');
    }
    catch (\LogicException $exception) {
      $this->assertStringContainsString('"processing" cannot be extracted', $exception->getMessage());
    }

    $other = $this->definition(['id' => 'orders', 'label' => 'Orders']);
    $queued = ImportRun::create(['definition_id' => 'customers', 'trigger' => 'drush']);
    $queued->save();
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('another import');
    $this->stage->extract($queued, $other, new FakeSource($this->pagesOf(1, 1)));
  }

  /**
   * Items are queued in the pool of the import.
   */
  public function testItemsGoToThePoolOfTheImport(): void {
    $definition = $this->definition(['pool' => 'heavy']);
    $run = $this->starter->start($definition, Trigger::Drush);
    $this->stage->extract($run, $definition, new FakeSource($this->pagesOf(1, 3)));

    $this->assertSame([], $this->items->claim('w', 10, 60, 'default'));
    $this->assertCount(3, $this->items->claim('w', 10, 60, 'heavy'));
    $this->assertSame(ItemState::Processing, $this->items->find(1)?->state);
  }

}
