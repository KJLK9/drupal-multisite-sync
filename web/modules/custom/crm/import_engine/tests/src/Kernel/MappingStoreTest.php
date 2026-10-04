<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the mapping store: which target entity a source item became.
 */
#[Group('import_engine')]
#[RunTestsInSeparateProcesses]
class MappingStoreTest extends StorageTestBase {

  /**
   * An unknown item is told apart from a known one.
   */
  public function testKnownAndUnknownItems(): void {
    $mapping = $this->container->get('import_engine.mapping_store');
    $this->assertNull($mapping->find('customers', '["1"]'));

    $mapping->record('customers', '["1"]', 'node', '10', str_repeat('ab', 16), 3, TRUE, 100);

    $record = $mapping->find('customers', '["1"]');
    $this->assertNotNull($record);
    $this->assertSame('node', $record->targetType);
    $this->assertSame('10', $record->targetId);
    $this->assertSame(str_repeat('ab', 16), $record->hash);
    $this->assertSame([3, 3, 3], [$record->firstSeenRun, $record->lastSeenRun, $record->lastChangedRun]);
    // The same key in another import is another item.
    $this->assertNull($mapping->find('orders', '["1"]'));
  }

  /**
   * Seeing an item again keeps when it was first seen; a change is noted.
   */
  public function testRecordKeepsFirstSeenAndTracksChanges(): void {
    $mapping = $this->container->get('import_engine.mapping_store');
    $mapping->record('customers', 'k', 'node', '10', NULL, 1, TRUE, 100);

    // Seen again in run 2 without a change, then changed in run 3.
    $mapping->record('customers', 'k', 'node', '10', NULL, 2, FALSE, 200);
    $unchanged = $mapping->find('customers', 'k');
    $this->assertSame([1, 2, 1], [$unchanged?->firstSeenRun, $unchanged?->lastSeenRun, $unchanged?->lastChangedRun]);

    $mapping->record('customers', 'k', 'node', '10', NULL, 3, TRUE, 300);
    $changed = $mapping->find('customers', 'k');
    $this->assertSame([1, 3, 3], [$changed?->firstSeenRun, $changed?->lastSeenRun, $changed?->lastChangedRun]);
    $this->assertSame(1, $mapping->count('customers'));
  }

  /**
   * Marking keys as seen only moves forward and ignores unknown keys.
   */
  public function testMarkSeen(): void {
    $mapping = $this->container->get('import_engine.mapping_store');
    foreach (['a', 'b', 'c'] as $key) {
      $mapping->record('customers', $key, 'node', $key, NULL, 1, TRUE, 100);
    }

    $this->assertSame(2, $mapping->markSeen('customers', ['a', 'b', 'unknown'], 5));
    // A run that is older than what the item already shows changes nothing.
    $this->assertSame(0, $mapping->markSeen('customers', ['a'], 4));
    $this->assertSame(5, $mapping->find('customers', 'a')?->lastSeenRun);
    $this->assertSame(1, $mapping->find('customers', 'c')?->lastSeenRun);
    $this->assertNull($mapping->find('customers', 'unknown'));
  }

  /**
   * The sweep finds everything not seen since a run, and nothing else.
   */
  public function testNotSeenSince(): void {
    $mapping = $this->container->get('import_engine.mapping_store');
    foreach (['a', 'b', 'c', 'd'] as $key) {
      $mapping->record('customers', $key, 'node', $key, NULL, 1, TRUE, 100);
    }
    $mapping->markSeen('customers', ['a', 'c'], 2);
    $mapping->record('orders', 'x', 'node', 'x', NULL, 1, TRUE, 100);

    $gone = $mapping->notSeenSince('customers', 2, 10);

    $this->assertSame(['b', 'd'], array_map(static fn ($record) => $record->key, $gone));
    $this->assertCount(1, $mapping->notSeenSince('customers', 2, 1), 'bounded by the limit');
  }

  /**
   * Many keys are handled in several statements.
   */
  public function testMarkSeenWithManyKeys(): void {
    $mapping = $this->container->get('import_engine.mapping_store');
    $keys = [];
    for ($n = 0; $n < 1200; $n++) {
      $keys[] = "k$n";
      $mapping->record('customers', "k$n", 'node', (string) $n, NULL, 1, FALSE, 100);
    }

    $this->assertSame(1200, $mapping->markSeen('customers', $keys, 2));
    $this->assertSame([], $mapping->notSeenSince('customers', 2, 10));
  }

  /**
   * The table follows the dataset: handling items again adds no rows.
   */
  public function testRowsDoNotGrowWithRuns(): void {
    $mapping = $this->container->get('import_engine.mapping_store');
    for ($run = 1; $run <= 5; $run++) {
      foreach (range(1, 20) as $n) {
        $mapping->record('customers', "k$n", 'node', (string) $n, NULL, $run, $run === 1, 100 + $run);
      }
    }

    $this->assertSame(20, $this->rows('import_mapping'));
  }

}
