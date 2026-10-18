<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Guarantee;

use Drupal\import_engine\Run\RunStatus;
use Drupal\import_engine\Run\Trigger;
use Drupal\import_engine\Drive\RunBudget;
use Drupal\import_engine\Source\SourceException;
use Drupal\node\Entity\Node;
use Drupal\Tests\import_engine\Kernel\FakeSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * What the engine may do to the site when the source loses items.
 *
 * The promise: items that go missing are handled as the import says (unpublish,
 * delete, ignore), and nothing is removed because the engine could not read the
 * source, or because the source suddenly looks nearly empty.
 */
#[Group('guarantee')]
#[RunTestsInSeparateProcesses]
class DeletionGuaranteeTest extends GuaranteeTestBase {

  /**
   * What each policy does to the accounts that left the source.
   *
   * @return array<string, array{string, bool, bool, int}>
   *   The policy, whether the accounts still exist, whether they are
   *   published, and how many the run counts as removed.
   */
  public static function policies(): array {
    return [
      'unpublish' => ['unpublish', TRUE, FALSE, 2],
      'delete' => ['delete', FALSE, FALSE, 2],
      'ignore' => ['ignore', TRUE, TRUE, 0],
    ];
  }

  /**
   * Accounts that left the source are handled as the policy says.
   */
  #[DataProvider('policies')]
  public function testPolicyDecidesWhatHappens(string $policy, bool $exist, bool $published, int $counted): void {
    $definition = $this->saveImport(['delete_policy' => $policy, 'delete_threshold_percent' => 0]);
    $this->runToEnd($definition, new FakeSource($this->pagesOfItems($this->accountItems(1, 10), 5)));
    $leaving = [$this->accountsOnSite()['C9']['id'], $this->accountsOnSite()['C10']['id']];

    $run = $this->runToEnd($definition, new FakeSource($this->pagesOfItems($this->accountItems(1, 8), 5)));

    $this->assertSame(RunStatus::Completed, $run->getStatus());
    $this->assertSame($counted, $run->getCounters()['deleted']);
    foreach ($leaving as $id) {
      $node = Node::load($id);
      $this->assertSame($exist, $node !== NULL, $policy);
      if ($node !== NULL) {
        $this->assertSame($published, $node->isPublished(), $policy);
      }
    }
    // The ones that stayed are untouched.
    $stayed = Node::load($this->accountsOnSite()['C1']['id']);
    $this->assertNotNull($stayed);
    $this->assertTrue($stayed->isPublished());
  }

  /**
   * An account that comes back is the same account, published again.
   */
  public function testReturningAccountIsTheSameOne(): void {
    $definition = $this->saveImport(['delete_threshold_percent' => 0]);
    $this->runToEnd($definition, new FakeSource($this->pagesOfItems($this->accountItems(1, 6), 3)));
    $id = $this->accountsOnSite()['C6']['id'];
    $this->runToEnd($definition, new FakeSource($this->pagesOfItems($this->accountItems(1, 5), 3)));
    $this->assertFalse(Node::load($id)?->isPublished());

    $this->runToEnd($definition, new FakeSource($this->pagesOfItems($this->accountItems(1, 6), 3)));

    $this->assertTrue($this->accountsOnSite()['C6']['status'], 'Published again.');
    $this->assertSame($id, $this->accountsOnSite()['C6']['id'], 'The same account.');
    $this->assertCount(6, $this->accountsOnSite(), 'No second account was made for it.');
  }

  /**
   * A source that cannot be read removes nothing.
   */
  public function testUnreadableSourceRemovesNothing(): void {
    $definition = $this->saveImport(['delete_threshold_percent' => 0]);
    $this->runToEnd($definition, new FakeSource($this->pagesOfItems($this->accountItems(1, 10), 5)));
    $before = $this->accountsOnSite();

    $source = (new FakeSource($this->pagesOfItems($this->accountItems(1, 10), 5)))
      ->failOnce(1, SourceException::permanent('The source says no.'));
    $run = $this->starter->start($definition, Trigger::Drush);
    $this->driver->drive($run, $definition, new RunBudget(), 'w', 50, $source);

    $after = $this->reload($run);
    $this->assertContains($after->getStatus(), [RunStatus::Failed, RunStatus::CompletedWithErrors]);
    $this->assertSame(0, $after->getCounters()['deleted'], 'Nothing was taken for gone.');
    $this->assertSame($before, $this->accountsOnSite(), 'The site is as it was.');
  }

  /**
   * A source that suddenly returns a handful of items is not believed.
   */
  public function testMassDeletionIsRefused(): void {
    $definition = $this->saveImport(['delete_threshold_percent' => 20]);
    $this->runToEnd($definition, new FakeSource($this->pagesOfItems($this->accountItems(1, 50), 10)));
    $before = $this->accountsWithoutTimes();

    $run = $this->runToEnd($definition, new FakeSource($this->pagesOfItems($this->accountItems(1, 5), 10)));

    $this->assertSame(RunStatus::CompletedWithErrors, $run->getStatus());
    $this->assertSame(0, $run->getCounters()['deleted']);
    $this->assertSame($before, $this->accountsWithoutTimes(), 'All 50 accounts are still published.');
    $this->assertStringContainsString('missing', $run->getSummary());
  }

  /**
   * The threshold is a line: just under it sweeps, just over it does not.
   */
  public function testThresholdIsExact(): void {
    $definition = $this->saveImport(['delete_threshold_percent' => 20]);
    $this->runToEnd($definition, new FakeSource($this->pagesOfItems($this->accountItems(1, 50), 10)));

    // 10 of 50 is exactly 20%: allowed.
    $at = $this->runToEnd($definition, new FakeSource($this->pagesOfItems($this->accountItems(1, 40), 10)));
    $this->assertSame(10, $at->getCounters()['deleted']);

    // 9 more of the 40 that are left is 22.5%: refused.
    $over = $this->runToEnd($definition, new FakeSource($this->pagesOfItems($this->accountItems(1, 31), 10)));
    $this->assertSame(0, $over->getCounters()['deleted']);
    $this->assertSame(RunStatus::CompletedWithErrors, $over->getStatus());
  }

}
