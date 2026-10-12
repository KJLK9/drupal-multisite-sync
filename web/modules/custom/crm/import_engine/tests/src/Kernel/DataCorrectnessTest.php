<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Kernel;

use Drupal\import_engine\Entity\ImportDefinition;
use Drupal\import_engine\Extract\ExtractStatus;
use Drupal\import_engine\Run\RunStatus;
use Drupal\import_engine\Run\Trigger;
use Drupal\import_engine\Storage\ItemState;
use Drupal\import_engine\Storage\MappingStore;
use Drupal\import_engine\Storage\Outcome;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the cases a review found where an import could do the wrong thing.
 *
 * Each test sets up the situation, and asserts what must hold.
 */
#[Group('import_engine')]
#[RunTestsInSeparateProcesses]
class DataCorrectnessTest extends NodeTestBase {

  /**
   * Returns the mapping store.
   */
  protected function mappings(): MappingStore {
    $store = $this->container->get('import_engine.mapping_store');
    assert($store instanceof MappingStore);
    return $store;
  }

  /**
   * Returns two source items.
   *
   * @return list<array<string, mixed>>
   *   The items.
   */
  protected function twoAccounts(): array {
    return [
      ['id' => 1, 'name' => 'Acme', 'code' => 'A'],
      ['id' => 2, 'name' => 'Globex', 'code' => 'G'],
    ];
  }

  /**
   * Ends a run the way the finishing stage would, without a sweep.
   */
  protected function complete(mixed $run): void {
    $run->transitionTo(RunStatus::Finishing)->transitionTo(RunStatus::Completed)->save();
  }

  /**
   * A dead item of an older run, retried, must not undo a later run.
   *
   * Run 1 leaves item 2 dead (its payload does not validate). Run 2 reads the
   * source again, where item 2 is fixed, and imports it. The old dead item is
   * then retried: its payload is older than what the node holds.
   */
  public function testRetriedDeadItemLeavesLaterRunAlone(): void {
    $first = $this->extractRows([
      ['id' => 1, 'name' => 'Acme', 'code' => 'A'],
      ['id' => 2, 'name' => '', 'code' => 'G'],
    ]);
    $this->process->process('w', 10, 'default', 600, 1000);
    $this->complete($first);
    $this->assertSame(1, $this->items->countByState((int) $first->id())['dead']);

    $second = $this->extractAll(new FakeSource([
      [['id' => 1, 'name' => 'Acme', 'code' => 'A'], ['id' => 2, 'name' => 'Globex', 'code' => 'G']],
    ]), $this->accounts());
    $this->process->process('w', 10, 'default', 600, 2000);
    $this->complete($second);
    $mapping = $this->mappings();
    $this->assertSame((int) $second->id(), $mapping->find('customers', '["2"]')?->lastSeenRun);
    $before = $mapping->find('customers', '["2"]');
    $this->assertSame('Globex', Node::load((int) $before->targetId)?->label());

    // The dead item of run 1 is requeued and handled.
    $this->assertSame(1, $this->container->get('import_engine.run_manager')->retryDead('customers'));
    $result = $this->process->process('w', 10, 'default', 600, 3000);

    $this->assertSame(1, $result->skipped);
    $record = $mapping->find('customers', '["2"]');
    $this->assertSame((int) $second->id(), $record->lastSeenRun, 'A later run keeps its mark.');
    $this->assertSame('Globex', Node::load((int) $record->targetId)?->label());
    // So the sweep of run 2 would not take it for gone.
    $this->assertCount(0, $mapping->notSeenSince('customers', (int) $second->id(), 10));
    $events = $this->container->get('import_engine.event_log')->forRun((int) $first->id());
    $this->assertContains('Skipped', array_map(static fn ($event): string => $event->event->name, $events));
  }

  /**
   * The mapping never takes back what a later run has seen.
   */
  public function testMappingKeepsTheLatestRun(): void {
    $mapping = $this->mappings();
    $hash = str_repeat('a', 32);
    $mapping->record('customers', '["1"]', 'node', '1', $hash, 5, TRUE, 100);

    $mapping->record('customers', '["1"]', 'node', '1', $hash, 3, TRUE, 200);

    $record = $mapping->find('customers', '["1"]');
    $this->assertSame(5, $record?->lastSeenRun);
    $this->assertSame(5, $record->lastChangedRun);
    $mapping->record('customers', '["1"]', 'node', '1', $hash, 7, TRUE, 300);
    $this->assertSame(7, $mapping->find('customers', '["1"]')?->lastSeenRun);
  }

  /**
   * What an editor deleted is made again, although the source did not change.
   */
  public function testDeletedEntityIsMadeAgain(): void {
    $first = $this->extractRows([['id' => 1, 'name' => 'Acme', 'code' => 'A']]);
    $this->process->process('w', 10, 'default', 600, 1000);
    $this->complete($first);
    $mapping = $this->mappings();
    $old_id = (string) $mapping->find('customers', '["1"]')?->targetId;
    Node::load((int) $old_id)?->delete();
    $this->assertNull(Node::load((int) $old_id));

    $second = $this->extractAll(new FakeSource([[['id' => 1, 'name' => 'Acme', 'code' => 'A']]]), $this->accounts());
    $result = $this->process->process('w', 10, 'default', 600, 2000);

    $this->assertSame(1, $result->created);
    $this->assertSame(0, $result->unchanged);
    $new_id = (string) $mapping->find('customers', '["1"]')?->targetId;
    $this->assertNotSame($old_id, $new_id);
    $this->assertSame('Acme', Node::load((int) $new_id)?->label());
    $events = $this->container->get('import_engine.event_log')->forRun((int) $second->id());
    $this->assertStringContainsString('deleted', (string) $events[0]->message);
  }

  /**
   * An entity that is there and unchanged is still left alone.
   */
  public function testExistingEntityStaysUnchanged(): void {
    $first = $this->extractRows([['id' => 1, 'name' => 'Acme', 'code' => 'A']]);
    $this->process->process('w', 10, 'default', 600, 1000);
    $this->complete($first);

    $this->extractAll(new FakeSource([[['id' => 1, 'name' => 'Acme', 'code' => 'A']]]), $this->accounts());
    $result = $this->process->process('w', 10, 'default', 600, 2000);

    $this->assertSame(1, $result->unchanged);
    $this->assertSame(0, $result->created);
    $this->assertCount(1, Node::loadMultiple());
  }

  /**
   * The same values for another target setting are another write.
   */
  public function testChangedTargetIsWrittenAgain(): void {
    $first = $this->extractRows([['id' => 1, 'name' => 'Acme', 'code' => 'A']]);
    $this->process->process('w', 10, 'default', 600, 1000);
    $this->complete($first);

    // The import now writes the same values with another owner.
    $values = $this->accounts();
    User::create(['name' => 'second', 'status' => 1])->save();
    $values['target']['configuration']['owner'] = 2;
    ImportDefinition::load('customers')?->set('target', $values['target'])->save();
    $this->extractAll(new FakeSource([[['id' => 1, 'name' => 'Acme', 'code' => 'A']]]), $values);
    $result = $this->process->process('w', 10, 'default', 600, 2000);

    $this->assertSame(0, $result->unchanged, 'Another owner is not "unchanged".');
    $this->assertSame(1, $result->updated);
    $this->assertCount(1, Node::loadMultiple());
  }

  /**
   * An item written as another entity type is not touched as this one.
   */
  public function testMappingOfAnotherEntityTypeIsNotTouched(): void {
    $first = $this->extractRows([['id' => 1, 'name' => 'Acme', 'code' => 'A']]);
    $this->process->process('w', 10, 'default', 600, 1000);
    $this->complete($first);
    $mapping = $this->mappings();
    $node_id = (string) $mapping->find('customers', '["1"]')?->targetId;
    // As if the import had written something else before.
    $mapping->record('customers', '["1"]', 'taxonomy_term', $node_id, str_repeat('b', 32), (int) $first->id(), FALSE, 1500);

    $this->extractAll(new FakeSource([[['id' => 1, 'name' => 'Acme 2', 'code' => 'A']]]), $this->accounts());
    $result = $this->process->process('w', 10, 'default', 600, 2000);

    // A new node is made, and the one with the same number is not overwritten.
    $this->assertSame(1, $result->created);
    $this->assertSame('Acme', Node::load((int) $node_id)?->label());
    $record = $mapping->find('customers', '["1"]');
    $this->assertSame('node', $record?->targetType);
    $this->assertNotSame($node_id, (string) $record->targetId);
  }

  /**
   * Renewing extends only claims that are still held with their token.
   */
  public function testRenewExtendsTheClaimsThatAreHeld(): void {
    $this->extractRows([['id' => 1, 'name' => 'Acme', 'code' => 'A'], ['id' => 2, 'name' => 'Globex', 'code' => 'G']]);
    $claimed = $this->items->claim('slow', 10, 100, 'default', 1000);

    // The first item is done; the second is still held and renewed at 1090.
    $this->items->complete($claimed[0], Outcome::Created, NULL, 1050);
    $this->assertSame(1, $this->items->renew([$claimed[1]], 100, 1090));

    $this->assertSame([], $this->items->claim('other', 10, 100, 'default', 1150), 'Renewed: free only after 1190.');
    $taken = $this->items->claim('other', 10, 100, 'default', 1191);
    $this->assertCount(1, $taken);
    $this->assertSame($claimed[1]->id, $taken[0]->id);
    // The claim of the slow worker was lost with that, and its renewal is not.
    $this->assertSame(0, $this->items->renew([$claimed[1]], 100, 1200));
  }

  /**
   * Items a worker holds when the run is cancelled are not written.
   */
  public function testHeldItemsOfCancelledRunAreNotWritten(): void {
    $run = $this->extractRows($this->twoAccounts());
    // A worker has claimed both; the person cancels before it gets to them.
    $claimed = $this->items->claim('w', 10, 600, 'default', 1000);
    $this->assertCount(2, $claimed);
    $this->container->get('import_engine.run_manager')->cancel($run);
    // Cancelling skips what nobody holds: these are held.
    $this->assertSame(ItemState::Processing, $claimed[0]->state);

    $result = $this->container->get('import_engine.process_stage')->processItems('w', array_map(static fn ($item): int => $item->id, $claimed), 600, 1100);

    $this->assertCount(0, Node::loadMultiple(), 'Nothing was written after the cancel.');
    $this->assertSame(0, $result->created);
  }

  /**
   * A cancelled run whose items a worker handles through the batch is skipped.
   */
  public function testItemsOfCancelledRunAreSkipped(): void {
    $run = $this->extractRows($this->twoAccounts());
    $run->transitionTo(RunStatus::Cancelled)->save();

    $result = $this->process->process('w', 10, 'default', 600, 1000);

    $this->assertSame(2, $result->skipped);
    $this->assertCount(0, Node::loadMultiple());
    $counts = $this->items->countByState((int) $run->id());
    $this->assertSame(2, $counts['done']);
  }

  /**
   * A run that another process has moved on is not extracted from a stale copy.
   */
  public function testStaleRunIsNotExtracted(): void {
    $definition = $this->definition($this->accounts());
    $definition->save();
    $run = $this->starter->start($definition, Trigger::Drush);
    // This process holds the run as it is now; another one extracts it.
    $stale = clone $run;
    $this->stage->extract($run, $definition, new FakeSource([[['id' => 1, 'name' => 'Acme', 'code' => 'A']]]));
    $this->assertNotSame(RunStatus::Queued, $this->reload($run)->getStatus());

    $result = $this->stage->extract($stale, $definition, new FakeSource([[['id' => 1, 'name' => 'Acme', 'code' => 'A']]]));

    $this->assertSame(ExtractStatus::Busy, $result->status);
    $this->assertStringContainsString('changed by another process', (string) $result->message);
    // Nothing was queued twice.
    $this->assertSame(1, array_sum($this->items->countByState((int) $run->id())));
  }

}
