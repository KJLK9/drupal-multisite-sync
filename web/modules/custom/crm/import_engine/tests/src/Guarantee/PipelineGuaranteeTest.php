<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Guarantee;

use Drupal\import_engine\Run\RunStatus;
use Drupal\node\Entity\Node;
use Drupal\Tests\import_engine\Kernel\FakeSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * What must be true of a run from the source to the site, whatever its shape.
 */
#[Group('guarantee')]
#[RunTestsInSeparateProcesses]
class PipelineGuaranteeTest extends GuaranteeTestBase {

  /**
   * The shapes of a source: how many items, in pages of what size.
   *
   * @return array<string, array{int, int}>
   *   The number of items and the size of a page.
   */
  public static function shapes(): array {
    return [
      'nothing' => [0, 10],
      'one item' => [1, 10],
      'one page, not full' => [7, 10],
      'exactly one page' => [10, 10],
      'one more than a page' => [11, 10],
      'exact multiple of the page' => [30, 10],
      'a short last page' => [37, 10],
      'a page of one' => [5, 1],
      'many pages' => [100, 7],
    ];
  }

  /**
   * Every item of the source ends up on the site, once, in every shape.
   */
  #[DataProvider('shapes')]
  public function testEveryItemArrivesOnce(int $count, int $size): void {
    $definition = $this->saveImport();
    $items = $this->accountItems(1, $count);

    $run = $this->runToEnd($definition, new FakeSource($this->pagesOfItems($items, $size)));

    $this->assertSame(RunStatus::Completed, $run->getStatus());
    $this->assertCount($count, $this->accountsOnSite());
    $counters = $run->getCounters();
    $this->assertSame($count, $counters['items_extracted']);
    $this->assertSame($count, $counters['created']);
    $this->assertSame(0, $counters['failed'] + $counters['dead'] + $counters['updated']);
    // The numbers add up: every item that was read has one outcome.
    $this->assertSame(
      $counters['items_extracted'],
      $counters['created'] + $counters['updated'] + $counters['unchanged'] + $counters['skipped'] + $counters['failed'] + $counters['dead'],
    );
  }

  /**
   * A second run of the same data changes nothing, not even a timestamp.
   */
  public function testSecondRunChangesNothing(): void {
    $definition = $this->saveImport();
    $pages = $this->pagesOfItems($this->accountItems(1, 25), 10);
    $this->runToEnd($definition, new FakeSource($pages));
    $before = $this->accountsOnSite();

    $second = $this->runToEnd($definition, new FakeSource($pages), TRUE);

    $this->assertSame($before, $this->accountsOnSite(), 'Nothing on the site changed.');
    $counters = $second->getCounters();
    $this->assertSame(25, $counters['unchanged'], 'Every item was seen again, and none was written.');
    $this->assertSame(0, $counters['created'] + $counters['updated']);
  }

  /**
   * A page that did not change is not handled again, unless a full run asks.
   */
  public function testUnchangedPagesAreSkippedUnlessFullRun(): void {
    $definition = $this->saveImport();
    $pages = $this->pagesOfItems($this->accountItems(1, 20), 10);
    $this->runToEnd($definition, new FakeSource($pages));

    $skipping = $this->runToEnd($definition, new FakeSource($pages));
    $full = $this->runToEnd($definition, new FakeSource($pages), TRUE);

    $this->assertSame(0, $skipping->getCounters()['unchanged'], 'No item was handled.');
    $this->assertSame(20, $skipping->getCounters()['page_skipped'], 'The 20 items of the two unchanged pages were skipped.');
    $this->assertSame(20, $full->getCounters()['unchanged'], 'A full run handles every item.');
    $this->assertSame(0, $full->getCounters()['page_skipped']);
  }

  /**
   * One changed field updates one account and no other.
   */
  public function testOneChangeIsOneUpdate(): void {
    $definition = $this->saveImport();
    $items = $this->accountItems(1, 12);
    $this->runToEnd($definition, new FakeSource($this->pagesOfItems($items, 5)));
    $before = $this->accountsOnSite();

    $items[3]['name'] = 'Renamed';
    $run = $this->runToEnd($definition, new FakeSource($this->pagesOfItems($items, 5)));

    $after = $this->accountsOnSite();
    $this->assertSame(1, $run->getCounters()['updated']);
    $this->assertSame('Renamed', $after['C4']['title']);
    unset($before['C4'], $after['C4']);
    $this->assertSame($before, $after, 'The other accounts are exactly as they were.');
  }

  /**
   * The order and the paging of the source make no difference to what is read.
   */
  public function testOrderAndPagingDoNotMatter(): void {
    $items = $this->accountItems(1, 40);
    $shuffled = $items;
    mt_srand(7);
    shuffle($shuffled);
    $first = $this->saveImport();
    $second = $this->saveImport(['id' => 'second', 'label' => 'Second']);

    $one = $this->runToEnd($first, new FakeSource($this->pagesOfItems($items, 10)));
    $two = $this->runToEnd($second, new FakeSource($this->pagesOfItems($shuffled, 3)));

    $this->assertSame(40, $one->getCounters()['created']);
    $this->assertSame(40, $two->getCounters()['created']);
    // Both imports wrote every account, so each code is there twice.
    $codes = array_count_values(array_map(
      static fn ($node): string => (string) $node->get('field_code')->value,
      Node::loadMultiple(),
    ));
    $this->assertCount(40, $codes);
    $this->assertSame([2], array_values(array_unique($codes)));
  }

}
