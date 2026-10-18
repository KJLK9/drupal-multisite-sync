<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Guarantee;

use Drupal\import_engine\Entity\ImportDefinition;
use Drupal\import_engine\Run\RunStatus;
use Drupal\import_engine\Storage\ItemState;
use Drupal\node\Entity\Node;
use Drupal\Tests\import_engine\Kernel\FakeSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Items that refer to other items, whichever arrives first.
 *
 * The promise: with "wait", the order in which the imports run does not matter
 * for the end result; with "error" an item that refers to nothing fails; with
 * "ignore" it is saved without the reference.
 */
#[Group('guarantee')]
#[RunTestsInSeparateProcesses]
class ReferenceGuaranteeTest extends GuaranteeTestBase {

  /**
   * Saves the import of items (SKUs) and that of accounts that refer to them.
   */
  protected function saveBoth(string $whenMissing): void {
    ImportDefinition::create($this->accounts([
      'id' => 'items',
      'label' => 'Items',
      'source_key' => ['sku'],
      'target' => ['plugin' => 'entity', 'configuration' => ['entity_type' => 'node', 'bundle' => 'item', 'owner' => 1]],
      'mapping' => [
        $this->stringRow('title', 'name'),
        $this->stringRow('field_sku', 'sku'),
      ],
    ]))->save();
    $values = $this->accounts();
    $values['mapping'][] = [
      'target_field' => 'field_item',
      'mapper' => [
        'plugin' => 'reference',
        'sources' => ['id' => 'item'],
        'settings' => ['definition' => 'items', 'when_missing' => $whenMissing],
      ],
    ];
    $this->saveImport($values);
  }

  /**
   * Builds a mapping row that copies a source value into a text field.
   *
   * @return array<string, mixed>
   *   The row.
   */
  protected function stringRow(string $field, string $path): array {
    return [
      'target_field' => $field,
      'mapper' => ['plugin' => 'string', 'sources' => ['value' => $path], 'settings' => []],
    ];
  }

  /**
   * Items as the source gives them.
   *
   * @return list<array<string, mixed>>
   *   Six SKUs.
   */
  protected function skus(): array {
    return array_map(static fn (int $n): array => ['sku' => "S-$n", 'name' => "Item $n"], range(1, 6));
  }

  /**
   * Accounts that each refer to one SKU.
   *
   * @return list<array<string, mixed>>
   *   Six accounts.
   */
  protected function referring(): array {
    return array_map(static fn (int $n): array => [
      'id' => $n,
      'name' => "Account $n",
      'code' => "C$n",
      'item' => 'S-' . (($n % 6) + 1),
    ], range(1, 6));
  }

  /**
   * Gives the retrying items rounds, later and later, until none is left.
   */
  protected function processUntilQuiet(): void {
    // The first attempts were made now, so a retry is due a little later.
    $now = time() + 1000;
    for ($round = 0; $round < 6; $round++) {
      $this->process->process('w', 100, 'default', 600, $now);
      $now += 5000;
    }
  }

  /**
   * Which item each account refers to, by title.
   *
   * @return array<string, string|null>
   *   The title of the referred item per account.
   */
  protected function referredItems(): array {
    $result = [];
    foreach (Node::loadMultiple() as $node) {
      if ($node->bundle() !== 'account') {
        continue;
      }
      $target = $node->get('field_item')->target_id;
      $result[(string) $node->label()] = $target === NULL ? NULL : (string) Node::load((int) $target)?->label();
    }
    ksort($result);
    return $result;
  }

  /**
   * Accounts first, items later: every account ends up pointing at its item.
   */
  public function testWaitingAccountsFindTheirItemsLater(): void {
    $this->saveBoth('wait');
    $accounts = ImportDefinition::load('customers');
    $items = ImportDefinition::load('items');
    $this->assertNotNull($accounts);
    $this->assertNotNull($items);

    $first = $this->runToEnd($accounts, new FakeSource([$this->referring()]));
    $this->assertNotSame(RunStatus::Completed, $first->getStatus(), 'It waits for the items.');
    $this->assertCount(0, Node::loadMultiple(), 'Nothing was written without its item.');

    $this->runToEnd($items, new FakeSource([$this->skus()]));
    $this->processUntilQuiet();

    $this->assertSame([
      'Account 1' => 'Item 2',
      'Account 2' => 'Item 3',
      'Account 3' => 'Item 4',
      'Account 4' => 'Item 5',
      'Account 5' => 'Item 6',
      'Account 6' => 'Item 1',
    ], $this->referredItems());
  }

  /**
   * Items first, accounts later: the same result, at once.
   */
  public function testItemsFirstGivesTheSameResult(): void {
    $this->saveBoth('wait');
    $accounts = ImportDefinition::load('customers');
    $items = ImportDefinition::load('items');
    $this->assertNotNull($accounts);
    $this->assertNotNull($items);

    $this->runToEnd($items, new FakeSource([$this->skus()]));
    $run = $this->runToEnd($accounts, new FakeSource([$this->referring()]));

    $this->assertSame(RunStatus::Completed, $run->getStatus());
    $this->assertSame('Item 2', $this->referredItems()['Account 1']);
    $this->assertSame('Item 1', $this->referredItems()['Account 6']);
    $this->assertCount(6, $this->referredItems());
  }

  /**
   * What each behaviour does with an item that refers to nothing.
   *
   * @return array<string, array{string, int, int, int}>
   *   The behaviour, and how many accounts are saved, are dead and wait.
   */
  public static function behaviours(): array {
    return [
      'wait' => ['wait', 0, 0, 6],
      'error' => ['error', 0, 6, 0],
      'ignore' => ['ignore', 6, 0, 0],
    ];
  }

  /**
   * Waiting waits, an error fails the item at once, ignoring saves it without.
   */
  #[DataProvider('behaviours')]
  public function testBehaviourWhenTheItemIsMissing(string $behaviour, int $saved, int $dead, int $waiting): void {
    $this->saveBoth($behaviour);
    $accounts = ImportDefinition::load('customers');
    $this->assertNotNull($accounts);

    $run = $this->runToEnd($accounts, new FakeSource([$this->referring()]));

    $this->assertCount($saved, Node::loadMultiple(), $behaviour);
    $counts = $this->items->countByState((int) $run->id());
    $this->assertSame($dead, $counts[strtolower(ItemState::Dead->name)], $behaviour);
    $this->assertSame($waiting, $counts[strtolower(ItemState::Retrying->name)], $behaviour);
    if ($behaviour === 'ignore') {
      $this->assertSame(array_fill_keys(array_map(static fn (int $n): string => "Account $n", range(1, 6)), NULL), $this->referredItems());
    }
  }

}
