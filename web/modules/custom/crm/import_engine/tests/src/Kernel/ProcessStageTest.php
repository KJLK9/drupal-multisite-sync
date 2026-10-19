<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Kernel;

use Drupal\import_engine\Event\ItemWritten;
use Drupal\import_engine\Process\ProcessResult;
use Drupal\import_engine\Run\RunStatus;
use Drupal\import_engine\Storage\EventType;
use Drupal\import_engine\Target\TargetException;
use Drupal\import_engine\Target\TargetInterface;
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
   * With a listener, a write says which fields changed from what to what.
   */
  public function testWritesAreAnnouncedWithTheirChanges(): void {
    $heard = [];
    $this->container->get('event_dispatcher')->addListener(ItemWritten::class, static function (ItemWritten $event) use (&$heard): void {
      $heard[] = $event;
    });
    $first = $this->extractRows([['id' => 1, 'name' => 'Acme', 'code' => 'A']]);
    $this->process->process('w', 10, 'default', 600, 1000);
    $first->transitionTo(RunStatus::Finishing)->transitionTo(RunStatus::Completed)->save();

    $this->assertCount(1, $heard);
    $this->assertTrue($heard[0]->created);
    $this->assertSame('customers', $heard[0]->definitionId);
    $this->assertSame('node', $heard[0]->targetType);
    $this->assertSame(['old' => '', 'new' => 'Acme'], $heard[0]->changes['title']);
    $this->assertSame(['old' => '', 'new' => 'A'], $heard[0]->changes['field_code']);

    $second = $this->extractAll(new FakeSource([[['id' => 1, 'name' => 'Acme BV', 'code' => 'A']]]), $this->accounts());
    $this->process->process('w', 10, 'default', 600, 2000);
    $second->transitionTo(RunStatus::Finishing)->transitionTo(RunStatus::Completed)->save();

    $this->assertCount(2, $heard);
    $this->assertFalse($heard[1]->created);
    $this->assertSame(['title' => ['old' => 'Acme', 'new' => 'Acme BV']], $heard[1]->changes, 'Only what changed.');

    $this->extractAll(new FakeSource([[['id' => 1, 'name' => 'Acme BV', 'code' => 'A']]]), $this->accounts());
    $this->process->process('w', 10, 'default', 600, 3000);
    $this->assertCount(2, $heard, 'An item that did not change is not announced.');
  }

  /**
   * A listener that fails does not fail the item, which is written.
   */
  public function testFailingListenerDoesNotFailTheItem(): void {
    $this->container->get('event_dispatcher')->addListener(ItemWritten::class, static function (): void {
      throw new \RuntimeException('The audit table is gone.');
    });
    $run = $this->extractRows([['id' => 1, 'name' => 'Acme', 'code' => 'A']]);

    $result = $this->process->process('w', 10, 'default', 600, 1000);

    $this->assertProcessed($result, created: 1);
    $this->assertCount(1, Node::loadMultiple());
    $this->assertSame(1, $this->container->get('import_engine.event_log')->countByEvent((int) $run->id())['created']);
  }

  /**
   * Tracking changes does not change what a wrong field does.
   */
  public function testUnknownFieldFailsTheSameWhenTracking(): void {
    $target = $this->container->get('plugin.manager.import_engine_target')->createInstance('entity', [
      'entity_type' => 'node',
      'bundle' => 'account',
      'owner' => 1,
    ]);
    $this->assertInstanceOf(TargetInterface::class, $target);

    foreach ([FALSE, TRUE] as $track) {
      try {
        $target->save(['title' => 'X', 'field_nope' => 'Y'], NULL, $track);
        $this->fail('An unknown field must be refused.');
      }
      catch (TargetException) {
        $this->addToAssertionCount(1);
      }
    }
  }

  /**
   * A value written another way but stored the same is not a change.
   */
  public function testSameStoredValueIsNoChange(): void {
    $target = $this->container->get('plugin.manager.import_engine_target')->createInstance('entity', [
      'entity_type' => 'node',
      'bundle' => 'account',
      'owner' => 1,
    ]);
    $this->assertInstanceOf(TargetInterface::class, $target);
    $rate = static fn (string $number): array => [['number' => $number, 'currency_code' => 'EUR']];
    $first = $target->save(['title' => 'Acme', 'field_rate' => $rate('10.5'), 'field_count' => '5'], NULL, TRUE);

    $again = $target->save(['title' => 'Acme', 'field_rate' => $rate('10.50'), 'field_count' => 5], $first->id, TRUE);

    $this->assertSame([], $again->changes, 'Nothing differs in what is stored.');
    $changed = $target->save(['field_rate' => $rate('11')], $first->id, TRUE);
    $this->assertSame(['field_rate'], array_keys($changed->changes));
    $this->assertNotSame($changed->changes['field_rate']['old'], $changed->changes['field_rate']['new']);
  }

  /**
   * Without a listener nothing is worked out.
   */
  public function testNoListenerMeansNoChangesAreTracked(): void {
    $this->extractRows([['id' => 1, 'name' => 'Acme', 'code' => 'A']]);
    $target = $this->container->get('plugin.manager.import_engine_target')->createInstance('entity', [
      'entity_type' => 'node',
      'bundle' => 'account',
      'owner' => 1,
    ]);
    $this->assertInstanceOf(TargetInterface::class, $target);

    $this->assertSame([], $target->save(['title' => 'Plain'], NULL)->changes);
    $this->assertSame(['old' => '', 'new' => 'Tracked'], $target->save(['title' => 'Tracked'], NULL, TRUE)->changes['title']);
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
        'settings' => ['definition' => 'items', 'when_missing' => 'wait'],
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
        'settings' => ['definition' => 'items', 'when_missing' => 'wait'],
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

  /**
   * Chosen items are handled at once, whatever their pool or due time.
   */
  public function testProcessItems(): void {
    $this->extractRows([
      ['id' => 1, 'name' => 'A', 'code' => 'A'],
      ['id' => 2, 'name' => 'B', 'code' => 'B'],
      ['id' => 3, 'name' => 'C', 'code' => 'C'],
    ], ['pool' => 'heavy']);
    $ids = array_map(static fn ($item): int => $item->id, $this->items->listItems(NULL, NULL, 10));

    $result = $this->process->processItems('ui', [$ids[0], $ids[2]], 600, 1000);

    $this->assertProcessed($result, created: 2);
    $this->assertSame(1, $this->items->countByState(1)['pending']);
    $this->assertCount(2, Node::loadMultiple());
  }

}
