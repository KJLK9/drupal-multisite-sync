<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Guarantee;

use Drupal\import_engine\BackoffStrategy;
use Drupal\import_engine\Retry\RetryPolicy;
use Drupal\import_engine\Run\RunStatus;
use Drupal\import_engine\Run\Trigger;
use Drupal\import_engine\Drive\RunBudget;
use Drupal\import_engine\Source\SourceException;
use Drupal\import_engine\Storage\ItemState;
use Drupal\node\Entity\Node;
use Drupal\Tests\import_engine\Kernel\FakeSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * What the engine does when something goes wrong, and what it never does.
 */
#[Group('guarantee')]
#[RunTestsInSeparateProcesses]
class ResilienceGuaranteeTest extends GuaranteeTestBase {

  /**
   * An import whose items refer to something that is not there.
   *
   * @param array<string, mixed> $values
   *   Values that replace the defaults.
   */
  protected function referencingImport(array $values = []): void {
    $values = $this->accounts($values);
    $values['mapping'][] = [
      'target_field' => 'field_item',
      'mapper' => [
        'plugin' => 'reference',
        'sources' => ['id' => 'item'],
        'settings' => ['definition' => 'items', 'when_missing' => 'wait'],
      ],
    ];
    $this->definition($values)->save();
  }

  /**
   * The strategies, and the delay they give after each attempt.
   *
   * @return array<string, array{string, list<int>}>
   *   The strategy and the delays after each attempt but the last.
   */
  public static function strategies(): array {
    return [
      'fixed' => ['fixed', [60, 60, 60]],
      'linear' => ['linear', [60, 120, 180]],
      'exponential' => ['exponential', [60, 120, 240]],
    ];
  }

  /**
   * An item is tried again exactly when the strategy says, not before.
   *
   * @param string $strategy
   *   The backoff strategy.
   * @param list<int> $delays
   *   The expected delays after the first and the second attempt.
   */
  #[DataProvider('strategies')]
  public function testRetriesFollowTheStrategy(string $strategy, array $delays): void {
    $this->referencingImport([
      'resilience' => [
        'max_attempts' => count($delays) + 1,
        'backoff' => $strategy,
        'retry_delay' => 60,
        'dlq_enabled' => TRUE,
        'max_repeated_pages' => 3,
      ],
    ]);
    $this->extractAll(new FakeSource([[['id' => 1, 'name' => 'Acme', 'code' => 'A', 'item' => 'S-1']]]), $this->accounts());

    $now = 1000;
    foreach ($delays as $delay) {
      $this->assertSame(1, $this->process->process('w', 10, 'default', 600, $now)->claimed, 'It is claimed when due.');
      $item = $this->items->listItems(NULL, ItemState::Retrying, 5)[0];
      $this->assertSame($now + $delay, $item->nextAttempt, 'The next attempt is where the strategy says.');
      $this->assertSame(0, $this->process->process('w', 10, 'default', 600, $now + $delay - 1)->claimed, 'Not a second early.');
      $now += $delay;
    }
    $last = $this->process->process('w', 10, 'default', 600, $now);
    $this->assertSame(1, $last->failed, 'The last attempt is the last.');
    $dead = $this->items->listItems(NULL, ItemState::Dead, 5);
    $this->assertCount(1, $dead);
    $this->assertSame(count($delays) + 1, $dead[0]->attempts, 'Never more attempts than the import allows.');
  }

  /**
   * The policy and the item agree for every number of attempts.
   */
  public function testDelaysAreBoundedAndGrowAsTheyShould(): void {
    $policy = new RetryPolicy();
    foreach (BackoffStrategy::cases() as $strategy) {
      $previous = 0;
      foreach (range(1, 40) as $attempt) {
        $delay = $policy->delay($attempt, $strategy, 60);
        $this->assertLessThanOrEqual(RetryPolicy::MAX_DELAY, $delay, 'A delay never passes six hours.');
        $this->assertGreaterThanOrEqual($previous, $delay, $strategy->value . ' never gets shorter.');
        $previous = $delay;
      }
    }
    $this->assertSame(RetryPolicy::MAX_DELAY, $policy->delay(40, BackoffStrategy::Exponential, 60));
  }

  /**
   * A dead item can be fixed, tried again and ends up on the site.
   */
  public function testDeadLetterQueueRoundTrip(): void {
    $definition = $this->saveImport();
    $items = $this->accountItems(1, 4);
    $items[2]['name'] = '';
    $run = $this->runToEnd($definition, new FakeSource([$items]));

    $this->assertSame(RunStatus::CompletedWithErrors, $run->getStatus());
    $this->assertCount(3, $this->accountsOnSite());
    $dead = $this->items->listItems([(int) $run->id()], ItemState::Dead, 10);
    $this->assertCount(1, $dead);
    $item = $this->items->find($dead[0]->id);
    $this->assertNotNull($item?->payload, 'The data of the item is kept, to fix it.');

    $payload = $item->payload;
    $payload['name'] = 'Fixed';
    $this->assertTrue($this->items->updatePayload($dead[0]->id, $payload));
    $this->assertSame(1, $this->items->requeue([$dead[0]->id]));
    $again = $this->process->process('w', 10, 'default', 600);

    $this->assertSame(1, $again->created);
    $this->assertCount(4, $this->accountsOnSite());
    $this->assertSame('Fixed', $this->accountsOnSite()['C3']['title']);
    $this->assertSame(0, $this->items->countByState((int) $run->id())['dead']);
  }

  /**
   * A discarded item is gone from the queue, and nothing was written for it.
   */
  public function testDiscardedItemLeavesNothingBehind(): void {
    $definition = $this->saveImport();
    $items = $this->accountItems(1, 3);
    $items[0]['name'] = '';
    $run = $this->runToEnd($definition, new FakeSource([$items]));
    $dead = $this->items->listItems([(int) $run->id()], ItemState::Dead, 10);

    $this->assertSame(1, $this->items->discard([$dead[0]->id]));

    $this->assertSame([], $this->items->listItems([(int) $run->id()], ItemState::Dead, 10));
    $this->assertCount(2, $this->accountsOnSite());
  }

  /**
   * Without a dead letter queue a failing item ends as failed, with no payload.
   */
  public function testWithoutTheQueueItemsEndAsFailed(): void {
    $definition = $this->saveImport([
      'resilience' => [
        'max_attempts' => 3,
        'backoff' => 'fixed',
        'retry_delay' => 60,
        'dlq_enabled' => FALSE,
        'max_repeated_pages' => 3,
      ],
    ]);
    $items = $this->accountItems(1, 3);
    $items[1]['name'] = '';

    $run = $this->runToEnd($definition, new FakeSource([$items]));

    $counters = $run->getCounters();
    $this->assertSame(1, $counters['failed']);
    $this->assertSame(0, $counters['dead']);
    $this->assertSame([], $this->items->listItems([(int) $run->id()], ItemState::Dead, 10));
  }

  /**
   * A source that is down for a run does not cost the site anything.
   */
  public function testOutageLeavesTheSiteAlone(): void {
    $definition = $this->saveImport(['delete_threshold_percent' => 0]);
    $this->runToEnd($definition, new FakeSource($this->pagesOfItems($this->accountItems(1, 12), 4)));
    $before = $this->accountsOnSite();

    $down = new FakeSource($this->pagesOfItems($this->accountItems(1, 12), 4));
    $down->down = SourceException::transient('Connection refused.');
    $run = $this->starter->start($definition, Trigger::Drush);
    $this->driver->drive($run, $definition, new RunBudget(), 'w', 50, $down);

    $this->assertNotSame(RunStatus::Completed, $this->reload($run)->getStatus());
    $this->assertSame($before, $this->accountsOnSite(), 'Nothing was changed, unpublished or deleted.');

    // The source comes back: the same run goes on and ends as a normal one.
    $up = new FakeSource($this->pagesOfItems($this->accountItems(1, 12), 4));
    $this->driver->drive($this->reload($run), $definition, new RunBudget(), 'w', 50, $up);
    $this->assertSame(RunStatus::Completed, $this->reload($run)->getStatus());
    $this->assertCount(12, $this->accountsOnSite());
    $this->assertSame(array_column($before, 'title'), array_column($this->accountsOnSite(), 'title'));
  }

  /**
   * An item that is not valid does not stop the others, however many there are.
   */
  public function testBadItemsDoNotStopTheGoodOnes(): void {
    $definition = $this->saveImport();
    $items = $this->accountItems(1, 40);
    foreach ([3, 7, 8, 20, 33] as $index) {
      $items[$index]['name'] = '';
    }

    $run = $this->runToEnd($definition, new FakeSource($this->pagesOfItems($items, 6)));

    $this->assertSame(35, $run->getCounters()['created']);
    $this->assertSame(5, $run->getCounters()['dead']);
    $this->assertCount(35, $this->accountsOnSite());
    $this->assertNull(Node::load(36 + 100), 'No stray nodes.');
  }

}
