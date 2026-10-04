<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Kernel;

use Drupal\import_engine\Storage\ItemState;
use Drupal\import_engine\Storage\Outcome;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the work queue: adding, claiming, finishing and purging items.
 */
#[Group('import_engine')]
#[RunTestsInSeparateProcesses]
class ItemStorageTest extends StorageTestBase {

  /**
   * Items are added once per run; the same key may recur in another run.
   */
  public function testEnqueueIgnoresDuplicatesWithinRun(): void {
    $result = $this->items->enqueue(1, 'default', $this->makeItems(3), now: 100);
    $this->assertSame(['inserted' => 3, 'duplicates' => 0], $result);

    // Overlap between pages: k2 and k3 are known, k4 is new, k4 repeats.
    $page = [...$this->makeItems(2, 2), ...$this->makeItems(2, 4), ['key' => 'k4', 'payload' => []]];
    $overlap = $this->items->enqueue(1, 'default', $page, now: 100);
    $this->assertSame(['inserted' => 2, 'duplicates' => 3], $overlap);
    $this->assertSame(5, $this->rows('import_item'));

    // Another run has its own copy.
    $this->assertSame(['inserted' => 1, 'duplicates' => 0], $this->items->enqueue(2, 'default', $this->makeItems(1), now: 100));
    $this->assertSame(['inserted' => 0, 'duplicates' => 0], $this->items->enqueue(2, 'default', [], now: 100));
  }

  /**
   * A claim takes items in priority and then id order, with their payload.
   */
  public function testClaimOrderAndPayload(): void {
    $this->items->enqueue(1, 'default', $this->makeItems(3), priority: 0, now: 100);
    $this->items->enqueue(1, 'default', [['key' => 'urgent', 'payload' => ['id' => 99, 'label' => 'Ünïcode ✓']]], priority: 5, now: 100);

    $claimed = $this->items->claim('worker-1', 2, 60, now: 200);

    $this->assertSame(['urgent', 'k1'], array_map(static fn ($item) => $item->key, $claimed));
    $this->assertSame(['id' => 99, 'label' => 'Ünïcode ✓'], $claimed[0]->payload);
    $this->assertSame(ItemState::Processing, $claimed[0]->state);
    $this->assertSame(1, $claimed[0]->attempts);
    $this->assertNotNull($claimed[0]->claimToken);
    $this->assertStringStartsWith('worker-1:', (string) $claimed[0]->claimToken);
  }

  /**
   * Two workers never get the same item.
   */
  public function testWorkersGetDisjointItems(): void {
    $this->items->enqueue(1, 'default', $this->makeItems(10), now: 100);

    $a = $this->items->claim('worker-a', 6, 60, now: 200);
    $b = $this->items->claim('worker-b', 6, 60, now: 200);

    $this->assertCount(6, $a);
    $this->assertCount(4, $b);
    $this->assertSame([], array_intersect(array_column($a, 'key'), array_column($b, 'key')));
    $this->assertSame([], $this->items->claim('worker-c', 6, 60, now: 200));
  }

  /**
   * Specific items are claimed, whatever their pool, when they are waiting.
   */
  public function testClaimIds(): void {
    $this->items->enqueue(1, 'default', $this->makeItems(3), now: 100);
    $this->items->enqueue(1, 'heavy', [['key' => 'heavy', 'payload' => ['id' => 9]]], now: 100);
    $all = $this->items->listItems([1], NULL, 10);
    $ids = array_map(static fn ($item): int => $item->id, $all);
    // One is retrying, due far in the future; one is already claimed.
    $first = $this->items->claim('a', 1, 600, 'default', 100)[0];
    $second = $this->items->claim('a', 1, 600, 'default', 100)[0];
    $this->items->retry($second, 'later', 99999, 100);

    $claimed = $this->items->claimIds('ui', $ids, 600, 200);

    $this->assertCount(3, $claimed, 'The claimed item is left alone.');
    $this->assertNotContains($first->id, array_map(static fn ($item): int => $item->id, $claimed));
    $this->assertContains('heavy', array_map(static fn ($item): string => $item->key, $claimed));
    $this->assertSame(['ui'], array_values(array_unique(array_map(static fn ($item): string => explode(':', (string) $item->claimToken)[0], $claimed))));
    $this->assertSame([], $this->items->claimIds('ui', [], 600, 200));
    $this->assertSame([], $this->items->claimIds('ui', $ids, 600, 200), 'Nothing is left to claim.');
  }

  /**
   * Items can be listed and counted by run and state, without their payload.
   */
  public function testListAndCount(): void {
    $this->items->enqueue(1, 'default', $this->makeItems(3), now: 100);
    $this->items->enqueue(2, 'default', $this->makeItems(2), now: 100);
    $this->items->complete($this->items->claim('a', 1, 600, 'default', 100)[0], Outcome::Created, NULL, 100);

    $this->assertSame(5, $this->items->countItems(NULL, NULL));
    $this->assertSame(3, $this->items->countItems([1], NULL));
    $this->assertSame(0, $this->items->countItems([], NULL));
    $this->assertSame(1, $this->items->countItems(NULL, ItemState::Done));
    $listed = $this->items->listItems([2], ItemState::Pending, 10);
    $this->assertCount(2, $listed);
    $this->assertNull($listed[0]->payload);
    // Newest first, and paged.
    $this->assertGreaterThan($listed[1]->id, $listed[0]->id);
    $this->assertCount(1, $this->items->listItems([2], NULL, 1, 1));
  }

  /**
   * Pools are separate queues.
   */
  public function testPoolsAreSeparate(): void {
    $this->items->enqueue(1, 'default', $this->makeItems(2), now: 100);
    $this->items->enqueue(2, 'heavy', $this->makeItems(1), now: 100);

    $this->assertCount(1, $this->items->claim('w', 10, 60, 'heavy', 200));
    $this->assertCount(2, $this->items->claim('w', 10, 60, 'default', 200));
  }

  /**
   * An item whose worker died is claimed again once the lease has run out.
   */
  public function testExpiredLeaseIsReclaimed(): void {
    $this->items->enqueue(1, 'default', $this->makeItems(1), now: 100);
    $first = $this->items->claim('worker-a', 1, 10, now: 200)[0];

    $this->assertSame([], $this->items->claim('worker-b', 1, 10, now: 205), 'still leased');
    $second = $this->items->claim('worker-b', 1, 10, now: 211);
    $this->assertCount(1, $second);
    $this->assertSame(2, $second[0]->attempts);

    // The first worker is late: it cannot change the item any more.
    $this->assertFalse($this->items->complete($first, Outcome::Created, now: 212));
    $this->assertTrue($this->items->complete($second[0], Outcome::Created, now: 212));
    $this->assertSame(ItemState::Done, $this->items->find($first->id)?->state);
  }

  /**
   * Finishing an item keeps a light row and drops the payload.
   */
  public function testCompleteDropsThePayload(): void {
    $this->items->enqueue(1, 'default', $this->makeItems(1), now: 100);
    $item = $this->items->claim('w', 1, 60, now: 200)[0];
    $hash = str_repeat('ab', 16);

    $this->assertTrue($this->items->complete($item, Outcome::Updated, $hash, 300));

    $done = $this->items->find($item->id);
    $this->assertNotNull($done);
    $this->assertSame(ItemState::Done, $done->state);
    $this->assertSame(Outcome::Updated, $done->outcome);
    $this->assertSame($hash, $done->hash);
    $this->assertNull($done->payload);
    $this->assertNull($done->claimToken);
    // Done items are not claimed again.
    $this->assertSame([], $this->items->claim('w', 1, 60, now: 1000));
  }

  /**
   * A retrying item waits for its time, and each claim counts as an attempt.
   */
  public function testRetryWaitsAndCountsAttempts(): void {
    $this->items->enqueue(1, 'default', $this->makeItems(1), now: 100);
    $item = $this->items->claim('w', 1, 60, now: 200)[0];

    $this->assertTrue($this->items->retry($item, 'timeout', 500, 210));

    $this->assertSame([], $this->items->claim('w', 1, 60, now: 499));
    $again = $this->items->claim('w', 1, 60, now: 500);
    $this->assertCount(1, $again);
    $this->assertSame(2, $again[0]->attempts);
    $this->assertSame('timeout', $again[0]->error);
    $this->assertNotNull($again[0]->payload);
  }

  /**
   * Out of attempts: the dead letter queue keeps the payload, or the item ends.
   */
  public function testFailWithAndWithoutTheDeadLetterQueue(): void {
    $this->items->enqueue(1, 'default', $this->makeItems(2), now: 100);
    [$a, $b] = $this->items->claim('w', 2, 60, now: 200);

    $this->assertTrue($this->items->fail($a, 'invalid data', TRUE, 210));
    $this->assertTrue($this->items->fail($b, 'invalid data', FALSE, 210));

    $dead = $this->items->find($a->id);
    $this->assertSame(ItemState::Dead, $dead?->state);
    $this->assertNotNull($dead->payload, 'a dead item keeps its payload for editing');
    $ended = $this->items->find($b->id);
    $this->assertSame(ItemState::Done, $ended?->state);
    $this->assertSame(Outcome::Failed, $ended->outcome);
    $this->assertNull($ended->payload);
    // Neither is claimed again.
    $this->assertSame([], $this->items->claim('w', 5, 60, now: 10000));
  }

  /**
   * A deferred item does not lose an attempt, as it was not its fault.
   */
  public function testDeferDoesNotCountTheAttempt(): void {
    $this->items->enqueue(1, 'default', $this->makeItems(1), now: 100);
    $item = $this->items->claim('w', 1, 60, now: 200)[0];
    $this->assertSame(1, $item->attempts);

    $this->assertTrue($this->items->defer($item, 400, 210));

    $this->assertSame(0, $this->items->find($item->id)?->attempts);
    $this->assertSame([], $this->items->claim('w', 1, 60, now: 399));
    $this->assertSame(1, $this->items->claim('w', 1, 60, now: 400)[0]->attempts);
  }

  /**
   * Dead items can be edited, requeued with fresh attempts, or discarded.
   */
  public function testDeadLetterQueueActions(): void {
    $this->items->enqueue(1, 'default', $this->makeItems(3), now: 100);
    $claimed = $this->items->claim('w', 3, 60, now: 200);
    foreach ($claimed as $item) {
      $this->items->fail($item, 'bad', TRUE, 210);
    }
    [$edit, $requeue, $discard] = $claimed;

    $this->assertTrue($this->items->updatePayload($edit->id, ['id' => 1, 'label' => 'Fixed']));
    $this->assertSame(2, $this->items->requeue([$edit->id, $requeue->id]));
    $this->assertSame(1, $this->items->discard([$discard->id]));

    $this->assertNull($this->items->find($discard->id));
    $again = $this->items->claim('w', 5, 60, now: 300);
    $this->assertCount(2, $again);
    $payloads = [];
    foreach ($again as $item) {
      $this->assertSame(1, $item->attempts, 'requeued items start with fresh attempts');
      $payloads[$item->id] = $item->payload;
    }
    $this->assertSame(['id' => 1, 'label' => 'Fixed'], $payloads[$edit->id]);

    // Only dead items are discarded; a pending one is left alone.
    $this->items->enqueue(2, 'default', $this->makeItems(1), now: 100);
    $pending_id = (int) $this->field($this->container->get('database')->select('import_item', 'i')->fields('i', ['id'])->condition('run_id', 2));
    $this->assertSame(0, $this->items->discard([$pending_id]));
    $this->assertNotNull($this->items->find($pending_id));
  }

  /**
   * The payload of an item that is being processed or done is not editable.
   */
  public function testPayloadIsOnlyEditableWhileWaiting(): void {
    $this->items->enqueue(1, 'default', $this->makeItems(1), now: 100);
    $item = $this->items->claim('w', 1, 60, now: 200)[0];

    $this->assertFalse($this->items->updatePayload($item->id, ['id' => 2]));
    $this->items->complete($item, Outcome::Created, now: 210);
    $this->assertFalse($this->items->updatePayload($item->id, ['id' => 2]));
  }

  /**
   * Counts by state and by outcome.
   */
  public function testCounts(): void {
    $this->items->enqueue(1, 'default', $this->makeItems(5), now: 100);
    $items = $this->items->claim('w', 4, 60, now: 200);
    $this->items->complete($items[0], Outcome::Created, now: 210);
    $this->items->complete($items[1], Outcome::Created, now: 210);
    $this->items->complete($items[2], Outcome::Unchanged, now: 210);
    $this->items->fail($items[3], 'bad', TRUE, 210);

    $this->assertSame(
      ['pending' => 1, 'processing' => 0, 'done' => 3, 'retrying' => 0, 'dead' => 1],
      $this->items->countByState(1),
    );
    $this->assertSame(
      ['created' => 2, 'updated' => 0, 'unchanged' => 1, 'skipped' => 0, 'failed' => 0],
      $this->items->countByOutcome(1),
    );
  }

  /**
   * The purge removes old items of one state, a batch at a time.
   */
  public function testPurgeInBatches(): void {
    $this->items->enqueue(1, 'default', $this->makeItems(7), now: 100);
    $claimed = $this->items->claim('w', 7, 60, now: 200);
    foreach (array_slice($claimed, 0, 5) as $item) {
      $this->items->complete($item, Outcome::Created, now: 300);
    }
    $this->items->fail($claimed[5], 'bad', TRUE, 300);
    $this->items->complete($claimed[6], Outcome::Created, now: 900);

    // Nothing is old enough yet.
    $this->assertSame(0, $this->items->purge(ItemState::Done, 300, 10));
    // Five are old enough; a batch of two removes two at a time.
    $this->assertSame(2, $this->items->purge(ItemState::Done, 500, 2));
    $this->assertSame(2, $this->items->purge(ItemState::Done, 500, 2));
    $this->assertSame(1, $this->items->purge(ItemState::Done, 500, 2));
    $this->assertSame(0, $this->items->purge(ItemState::Done, 500, 2));
    // The recent done item and the dead one are still there.
    $this->assertSame(2, $this->rows('import_item'));
    $this->assertSame(1, $this->items->purge(ItemState::Dead, 500, 10));
  }

  /**
   * The payload is stored compressed, a fraction of its JSON size.
   */
  public function testPayloadsAreStoredCompressed(): void {
    $payload = [];
    for ($i = 0; $i < 200; $i++) {
      $payload[] = ['id' => $i, 'label' => 'Customer name', 'status' => 'active', 'country' => 'NL'];
    }
    $this->items->enqueue(1, 'default', [['key' => 'big', 'payload' => $payload]], now: 100);

    $stored = (string) $this->field($this->container->get('database')->select('import_item', 'i')->fields('i', ['payload']));

    $this->assertLessThan(strlen(json_encode($payload, JSON_THROW_ON_ERROR)) / 5, strlen($stored));
    $this->assertSame($payload, $this->items->claim('w', 1, 60, now: 200)[0]->payload);
  }

  /**
   * A worker name that could not be stored is refused.
   */
  public function testWorkerNameIsValidated(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->items->claim('not a valid name!', 1, 60);
  }

  /**
   * Items remember the page they came from, and failures are found by page.
   */
  public function testPagesWithFailures(): void {
    $this->items->enqueue(1, 'default', $this->makeItems(2, 1), now: 100, page: 0);
    $this->items->enqueue(1, 'default', $this->makeItems(2, 3), now: 100, page: 1);
    $this->items->enqueue(1, 'default', $this->makeItems(2, 5), now: 100, page: 2);
    $this->items->enqueue(1, 'default', $this->makeItems(2, 7), now: 100, page: 3);
    $claimed = $this->items->claim('w', 8, 60, now: 200);
    $this->assertCount(8, $claimed);

    foreach ($claimed as $item) {
      match ($item->key) {
        // Page 1 has a dead item, page 3 an item that failed without the DLQ.
        'k3' => $this->items->fail($item, 'bad', TRUE, 210),
        'k8' => $this->items->fail($item, 'bad', FALSE, 210),
        default => $this->items->complete($item, Outcome::Created, now: 210),
      };
    }

    $this->assertSame([1, 3], $this->items->pagesWithFailures(1));
    $this->assertSame([], $this->items->pagesWithFailures(2));
  }

}
