<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Kernel;

use Drupal\import_engine\Storage\EventLog;
use Drupal\import_engine\Storage\EventType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the event log: what happened to an item, and when.
 */
#[Group('import_engine')]
#[RunTestsInSeparateProcesses]
class EventLogTest extends StorageTestBase {

  /**
   * The history of an item is its events, newest first.
   */
  public function testHistoryOfAnItem(): void {
    $this->events->record(1, 'customers', EventType::Created, '["42"]', 'node:10', NULL, 100);
    $this->events->record(2, 'customers', EventType::Updated, '["42"]', 'node:10', NULL, 200);
    $this->events->record(3, 'customers', EventType::Failed, '["42"]', NULL, 'The title is too long.', 300);
    $this->events->record(3, 'customers', EventType::Created, '["43"]', 'node:11', NULL, 300);
    // The same key in another import is another item.
    $this->events->record(3, 'orders', EventType::Created, '["42"]', 'node:90', NULL, 300);

    $history = $this->events->history('customers', '["42"]');

    $this->assertSame([EventType::Failed, EventType::Updated, EventType::Created], array_map(static fn ($record) => $record->event, $history));
    $this->assertSame([300, 200, 100], array_column($history, 'occurred'));
    $this->assertSame('The title is too long.', $history[0]->message);
    $this->assertSame('node:10', $history[1]->target);
    $this->assertCount(1, $this->events->history('orders', '["42"]'));
    $this->assertCount(2, $this->events->history('customers', '["42"]', 2));
    $this->assertSame([], $this->events->history('customers', '["nope"]'));
  }

  /**
   * Events of a run, in order, and counted by type.
   */
  public function testEventsOfRun(): void {
    $this->events->recordMany([
      ['run_id' => 5, 'definition_id' => 'customers', 'event' => EventType::Created, 'key' => 'a'],
      ['run_id' => 5, 'definition_id' => 'customers', 'event' => EventType::Created, 'key' => 'b'],
      ['run_id' => 5, 'definition_id' => 'customers', 'event' => EventType::Unpublished, 'key' => 'c'],
      ['run_id' => 6, 'definition_id' => 'customers', 'event' => EventType::Deleted, 'key' => 'd'],
    ], 100);

    $this->assertSame(['a', 'b', 'c'], array_column($this->events->forRun(5), 'key'));
    $this->assertSame(['b'], array_column($this->events->forRun(5, 1, 1), 'key'));
    $counts = $this->events->countByEvent(5);
    $this->assertSame(2, $counts['created']);
    $this->assertSame(1, $counts['unpublished']);
    $this->assertSame(0, $counts['deleted']);
  }

  /**
   * Many events at the same moment are stored: the key includes the time.
   */
  public function testBulkInsertAcrossChunks(): void {
    $events = [];
    for ($n = 0; $n < 1200; $n++) {
      $events[] = ['run_id' => 1, 'definition_id' => 'customers', 'event' => EventType::Created, 'key' => "k$n"];
    }

    $this->events->recordMany($events, 100);

    $this->assertSame(1200, $this->rows('import_event'));
    $this->assertCount(1, $this->events->history('customers', 'k1199'));
  }

  /**
   * Long messages are cut to fit.
   */
  public function testMessagesAreCut(): void {
    $this->events->record(1, 'customers', EventType::Failed, 'k', NULL, str_repeat('x', 400), 100);

    $this->assertSame(255, strlen((string) $this->events->history('customers', 'k')[0]->message));
  }

  /**
   * The purge removes old events a batch at a time.
   */
  public function testPurge(): void {
    foreach ([100, 100, 100, 500] as $n => $time) {
      $this->events->record(1, 'customers', EventType::Created, "k$n", NULL, NULL, $time);
    }

    $this->assertSame(2, $this->events->purge(300, 2));
    $this->assertSame(1, $this->events->purge(300, 2));
    $this->assertSame(0, $this->events->purge(300, 2));
    $this->assertSame(1, $this->rows('import_event'));
  }

  /**
   * An item is found by a hash of fixed size, whatever the length of its key.
   */
  public function testItemHashHasFixedSize(): void {
    $this->assertSame(16, strlen(EventLog::itemHash('customers', 'short')));
    $this->assertSame(16, strlen(EventLog::itemHash('customers', str_repeat('x', 1000))));
    $this->assertNotSame(EventLog::itemHash('customers', 'k'), EventLog::itemHash('orders', 'k'));
    // The definition and key cannot run together.
    $this->assertNotSame(EventLog::itemHash('ab', 'c'), EventLog::itemHash('a', 'bc'));
  }

}
