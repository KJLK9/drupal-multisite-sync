<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Kernel;

use Drupal\import_engine\Process\ProcessResult;
use Drupal\import_engine\Run\RunStatus;
use Drupal\import_engine\Storage\EventType;
use Drupal\node\Entity\Node;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the process stage with real nodes: extract, then map and write.
 */
#[Group('import_engine')]
#[RunTestsInSeparateProcesses]
class ProcessStageTest extends NodeTestBase {

  /**
   * Asserts what a call did.
   */
  protected function assertProcessed(ProcessResult $result, int $created = 0, int $updated = 0, int $unchanged = 0, int $retried = 0, int $failed = 0): void {
    $this->assertSame(
      [
        'created' => $created,
        'updated' => $updated,
        'unchanged' => $unchanged,
        'retried' => $retried,
        'failed' => $failed,
      ],
      [
        'created' => $result->created,
        'updated' => $result->updated,
        'unchanged' => $result->unchanged,
        'retried' => $result->retried,
        'failed' => $result->failed,
      ],
    );
  }

  /**
   * New items become nodes, and the mapping remembers them.
   */
  public function testCreatesNodes(): void {
    $run = $this->extractRows([
      ['id' => 1, 'name' => 'Acme', 'code' => 'A'],
      ['id' => 2, 'name' => 'Globex', 'code' => 'G'],
    ]);

    $result = $this->process->process('w', 10, 'default', 600, 1000);

    $this->assertProcessed($result, created: 2);
    $this->assertSame([(int) $run->id()], $result->runIds);
    $nodes = Node::loadMultiple();
    $this->assertCount(2, $nodes);
    $record = $this->container->get('import_engine.mapping_store')->find('customers', '["1"]');
    $this->assertNotNull($record);
    $this->assertSame('Acme', Node::load((int) $record->targetId)?->label());
    $this->assertSame(2, $this->container->get('import_engine.event_log')->countByEvent((int) $run->id())['created']);
  }

  /**
   * A second run writes nothing for an item that did not change.
   */
  public function testUnchangedAndUpdated(): void {
    $first = $this->extractRows([
      ['id' => 1, 'name' => 'Acme', 'code' => 'A'],
      ['id' => 2, 'name' => 'Globex', 'code' => 'G'],
    ]);
    $this->process->process('w', 10, 'default', 600, 1000);
    $first->transitionTo(RunStatus::Finishing)->transitionTo(RunStatus::Completed)->save();
    $changed_before = Node::load(1)?->getChangedTime();

    $second = $this->extractAll(new FakeSource([
      [['id' => 1, 'name' => 'Acme', 'code' => 'A'], ['id' => 2, 'name' => 'Globex BV', 'code' => 'G']],
    ]), $this->accounts());
    $result = $this->process->process('w', 10, 'default', 600, 2000);

    $this->assertProcessed($result, updated: 1, unchanged: 1);
    $this->assertSame('Globex BV', Node::load(2)?->label());
    $this->assertCount(2, Node::loadMultiple());
    $this->assertSame($changed_before, Node::load(1)?->getChangedTime());
    // Only the change is an event; an unchanged item is not.
    $counts = $this->container->get('import_engine.event_log')->countByEvent((int) $second->id());
    $this->assertSame(1, $counts['updated']);
    $this->assertSame(0, $counts['created']);
    // Both items were seen in this run.
    $this->assertCount(0, $this->container->get('import_engine.mapping_store')->notSeenSince('customers', (int) $second->id(), 10));
  }

  /**
   * An item that does not validate ends at once, in the dead letter queue.
   */
  public function testInvalidItemIsDead(): void {
    $run = $this->extractRows([['id' => 1, 'name' => 'Acme', 'code' => 'A'], ['id' => 2, 'name' => '', 'code' => 'G']]);

    $result = $this->process->process('w', 10, 'default', 600, 1000);

    $this->assertProcessed($result, created: 1, failed: 1);
    $this->assertCount(1, Node::loadMultiple());
    $counts = $this->items->countByState((int) $run->id());
    $this->assertSame(1, $counts['dead']);
    $events = $this->container->get('import_engine.event_log')->forRun((int) $run->id());
    $dead = array_values(array_filter($events, static fn ($event): bool => $event->event === EventType::Dead));
    $this->assertCount(1, $dead);
    $this->assertSame('["2"]', $dead[0]->key);
  }

  /**
   * Without a dead letter queue a failed item is just done as failed.
   */
  public function testFailedWithoutDeadLetterQueue(): void {
    $run = $this->extractRows([
      ['id' => 2, 'name' => '', 'code' => 'G'],
    ], [
      'resilience' => [
        'max_attempts' => 3,
        'backoff' => 'fixed',
        'retry_delay' => 60,
        'dlq_enabled' => FALSE,
        'max_repeated_pages' => 3,
      ],
    ]);

    $this->process->process('w', 10, 'default', 600, 1000);

    $counts = $this->items->countByState((int) $run->id());
    $this->assertSame(0, $counts['dead']);
    $this->assertSame(1, $counts['done']);
  }

  /**
   * An item that refers to something not imported yet is retried later.
   */
  public function testMissingReferenceIsRetriedThenDead(): void {
    $reference = [
      'target_field' => 'field_item',
      'mapper' => [
        'plugin' => 'reference',
        'sources' => ['id' => 'item'],
        'settings' => ['definition' => 'items', 'required' => TRUE],
      ],
    ];
    $values = $this->accounts();
    $values['mapping'][] = $reference;
    $run = $this->extractRows([['id' => 1, 'name' => 'Acme', 'code' => 'A', 'item' => 'S-1']], $values);

    $result = $this->process->process('w', 10, 'default', 600, 1000);
    $this->assertProcessed($result, retried: 1);
    // Not due yet: nothing to claim.
    $this->assertSame(0, $this->process->process('w', 10, 'default', 600, 1010)->claimed);

    // The referenced item arrives.
    $item = Node::create(['type' => 'item', 'title' => 'Sku', 'uid' => 1]);
    $item->save();
    $this->container->get('import_engine.mapping_store')->record('items', '["S-1"]', 'node', (string) $item->id(), NULL, 1, TRUE, 1020);

    $result = $this->process->process('w', 10, 'default', 600, 1100);
    $this->assertProcessed($result, created: 1);
    $account = Node::load(2);
    $this->assertSame((string) $item->id(), (string) $account?->get('field_item')->target_id);
    $this->assertSame(1, $this->items->countByState((int) $run->id())['done']);
  }

  /**
   * An item that keeps failing is dead after the attempts run out.
   */
  public function testAttemptsRunOut(): void {
    $values = $this->accounts();
    $values['mapping'][] = [
      'target_field' => 'field_item',
      'mapper' => [
        'plugin' => 'reference',
        'sources' => ['id' => 'item'],
        'settings' => ['definition' => 'items', 'required' => TRUE],
      ],
    ];
    $run = $this->extractRows([['id' => 1, 'name' => 'Acme', 'code' => 'A', 'item' => 'S-1']], $values);

    $now = 1000;
    $outcomes = [];
    for ($round = 0; $round < 5; $round++) {
      $result = $this->process->process('w', 10, 'default', 600, $now);
      $outcomes[] = [$result->retried, $result->failed];
      $now += 100;
    }

    // Two retries, the third attempt is the last, then nothing is left.
    $this->assertSame([[1, 0], [1, 0], [0, 1], [0, 0], [0, 0]], $outcomes);
    $this->assertSame(1, $this->items->countByState((int) $run->id())['dead']);
  }

  /**
   * A mapping that names a field the target does not have fails every item.
   */
  public function testBrokenDefinitionFailsItems(): void {
    $values = $this->accounts();
    $values['mapping'][] = [
      'target_field' => 'field_nope',
      'mapper' => ['plugin' => 'string', 'sources' => ['value' => 'name'], 'settings' => []],
    ];
    $run = $this->extractRows([['id' => 1, 'name' => 'Acme', 'code' => 'A']], $values);

    $result = $this->process->process('w', 10, 'default', 600, 1000);

    $this->assertProcessed($result, failed: 1);
    $events = $this->container->get('import_engine.event_log')->forRun((int) $run->id());
    $this->assertStringContainsString('field_nope', (string) $events[0]->message);
  }

  /**
   * The limit and the pool decide which items are handled.
   */
  public function testLimit(): void {
    $this->extractRows([
      ['id' => 1, 'name' => 'A', 'code' => 'A'],
      ['id' => 2, 'name' => 'B', 'code' => 'B'],
      ['id' => 3, 'name' => 'C', 'code' => 'C'],
    ]);

    $this->assertSame(2, $this->process->process('w', 2, 'default', 600, 1000)->claimed);
    $this->assertSame(0, $this->process->process('w', 2, 'other', 600, 1000)->claimed);
    $this->assertSame(1, $this->process->process('w', 2, 'default', 600, 1000)->claimed);
  }

}
