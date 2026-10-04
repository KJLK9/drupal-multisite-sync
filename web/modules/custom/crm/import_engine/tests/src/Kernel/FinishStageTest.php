<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Kernel;

use Drupal\Core\Lock\PersistentDatabaseLockBackend;
use Drupal\import_engine\Finish\FinishStatus;
use Drupal\import_engine\Reporter\RunReport;
use Drupal\import_engine\Run\RunStatus;
use Drupal\import_engine\Run\Trigger;
use Drupal\node\Entity\Node;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests closing a run: verify, sweep, counters, final status and reports.
 */
#[Group('import_engine')]
#[RunTestsInSeparateProcesses]
class FinishStageTest extends NodeTestBase {

  /**
   * Builds source rows for accounts with the given IDs.
   *
   * @param list<int> $ids
   *   The IDs.
   *
   * @return list<array<string, mixed>>
   *   The rows.
   */
  protected function rowsOf(array $ids): array {
    return array_map(static fn (int $id): array => ['id' => $id, 'name' => "Account $id", 'code' => "C$id"], $ids);
  }

  /**
   * Returns the node an item became.
   */
  protected function nodeOf(int $id): Node {
    $record = $this->container->get('import_engine.mapping_store')->find('customers', '["' . $id . '"]');
    $this->assertNotNull($record);
    $node = Node::load((int) $record->targetId);
    $this->assertInstanceOf(Node::class, $node);
    return $node;
  }

  /**
   * A finished run has its counters, verified pages and a final status.
   */
  public function testFinishesCompletedRun(): void {
    $run = $this->importRows($this->rowsOf([1, 2, 3]));

    $result = $this->finishRun($run);

    $this->assertSame(FinishStatus::Finished, $result->status);
    $this->assertSame(RunStatus::Completed, $result->runStatus);
    $this->assertSame(1, $result->verified);
    $run = $this->reload($run);
    $this->assertSame(RunStatus::Completed, $run->getStatus());
    $counters = $run->getCounters();
    $this->assertSame(3, $counters['items_extracted']);
    $this->assertSame(3, $counters['created']);
    $this->assertSame(0, $counters['failed']);
    $this->assertSame(0, $counters['deleted']);
    $this->assertNotNull($run->get('finished')->value);
    $this->assertTrue($this->pages->get('customers', 0)?->verified);
  }

  /**
   * While items wait or retry the run is not finished.
   */
  public function testWaitsForItems(): void {
    $run = $this->extractRows([['id' => 1, 'name' => 'Acme', 'code' => 'A']]);

    $this->assertSame(FinishStatus::Waiting, $this->finishRun($run)->status);
    $this->assertSame(RunStatus::Processing, $this->reload($run)->getStatus());

    $this->process->process('w', 10);
    $this->assertSame(FinishStatus::Finished, $this->finishRun($run)->status);
    // A finished run is not finished again.
    $this->assertSame(FinishStatus::NotApplicable, $this->finishRun($run)->status);
  }

  /**
   * A run that is still extracting cannot be finished.
   */
  public function testNotWhileExtracting(): void {
    $values = $this->accounts();
    $this->definition($values)->save();
    $definition = $this->definition($values);
    $run = $this->starter->start($definition, Trigger::Drush, NULL, FALSE);

    $this->assertSame(FinishStatus::NotApplicable, $this->finishRun($run)->status);
  }

  /**
   * Another process holding the lock makes the call busy.
   */
  public function testBusyWhenLocked(): void {
    $run = $this->importRows($this->rowsOf([1]));
    // Another process: a second lock backend has its own lock ID.
    $other = new PersistentDatabaseLockBackend($this->container->get('database'));
    $this->assertTrue($other->acquire('import_engine:finish:' . $run->id(), 60));

    $this->assertSame(FinishStatus::Busy, $this->finishRun($run)->status);
  }

  /**
   * An item that went missing is unpublished, and shown again when it returns.
   */
  public function testSweepUnpublishesAndRestores(): void {
    $this->finishRun($this->importRows($this->rowsOf([1, 2, 3, 4, 5])));

    $second = $this->importRows($this->rowsOf([1, 2, 3, 4]));
    $result = $this->finishRun($second);

    $this->assertSame(1, $result->swept);
    $this->assertSame(RunStatus::Completed, $result->runStatus);
    $this->assertFalse($this->nodeOf(5)->isPublished());
    $this->assertTrue($this->nodeOf(4)->isPublished());
    $this->assertSame(1, $this->reload($second)->getCounters()['deleted']);
    $record = $this->container->get('import_engine.mapping_store')->find('customers', '["5"]');
    $this->assertTrue($record?->gone);
    $this->assertSame(1, $this->container->get('import_engine.event_log')->countByEvent((int) $second->id())['unpublished']);

    // Gone items are not swept again.
    $third = $this->importRows($this->rowsOf([1, 2, 3, 4]));
    $this->assertSame(0, $this->finishRun($third)->swept);

    // It comes back: the very same data, and still visible again.
    $fourth = $this->importRows($this->rowsOf([1, 2, 3, 4, 5]));
    $this->assertSame(0, $this->finishRun($fourth)->swept);
    $this->assertTrue($this->nodeOf(5)->isPublished());
    $this->assertFalse($this->container->get('import_engine.mapping_store')->find('customers', '["5"]')?->gone);
  }

  /**
   * Pages that are skipped because they did not change still count as seen.
   */
  public function testSkippedPagesAreNotSwept(): void {
    $this->finishRun($this->importRows($this->rowsOf([1, 2, 3])));

    $second = $this->importRows($this->rowsOf([1, 2, 3]));
    $result = $this->finishRun($second);

    $this->assertSame(0, $result->swept);
    $this->assertSame(3, $this->reload($second)->getCounters()['page_skipped']);
    $this->assertTrue($this->nodeOf(2)->isPublished());
  }

  /**
   * The delete policy delete removes the entity and forgets the item.
   */
  public function testSweepDeletes(): void {
    $this->finishRun($this->importRows($this->rowsOf([1, 2, 3, 4, 5]), ['delete_policy' => 'delete']));
    $id = $this->nodeOf(5)->id();

    $result = $this->finishRun($this->importRows($this->rowsOf([1, 2, 3, 4])));

    $this->assertSame(1, $result->swept);
    $this->assertNull(Node::load($id));
    $this->assertNull($this->container->get('import_engine.mapping_store')->find('customers', '["5"]'));
  }

  /**
   * The delete policy ignore leaves everything alone.
   */
  public function testSweepIgnores(): void {
    $this->finishRun($this->importRows($this->rowsOf([1, 2, 3, 4, 5]), ['delete_policy' => 'ignore']));

    $result = $this->finishRun($this->importRows($this->rowsOf([1, 2, 3, 4])));

    $this->assertSame(0, $result->swept);
    $this->assertTrue($this->nodeOf(5)->isPublished());
  }

  /**
   * A source that suddenly has far fewer items does not empty the target.
   */
  public function testThresholdBlocksSweep(): void {
    $this->finishRun($this->importRows($this->rowsOf([1, 2, 3, 4, 5])));

    $second = $this->importRows($this->rowsOf([1, 2]));
    $result = $this->finishRun($second);

    $this->assertSame(0, $result->swept);
    $this->assertSame(RunStatus::CompletedWithErrors, $result->runStatus);
    $this->assertStringContainsString('3 of 5 known items (60%) are missing', $this->reload($second)->getSummary());
    $this->assertTrue($this->nodeOf(5)->isPublished());
    $this->assertFalse($this->container->get('import_engine.mapping_store')->find('customers', '["5"]')?->gone);
  }

  /**
   * A threshold of 0 means no limit.
   */
  public function testNoThresholdSweepsEverything(): void {
    $this->finishRun($this->importRows($this->rowsOf([1, 2, 3, 4, 5]), ['delete_threshold_percent' => 0]));

    $result = $this->finishRun($this->importRows($this->rowsOf([1])));

    $this->assertSame(4, $result->swept);
    $this->assertFalse($this->nodeOf(3)->isPublished());
  }

  /**
   * A run whose extraction was not complete fails and sweeps nothing.
   */
  public function testIncompleteExtractionFailsWithoutSweep(): void {
    $this->finishRun($this->importRows($this->rowsOf([1, 2, 3, 4, 5])));

    $second = $this->importRows($this->rowsOf([1, 2, 3, 4]));
    $this->reload($second)->setExtractComplete(FALSE)->save();
    $result = $this->finishRun($second);

    $this->assertSame(RunStatus::Failed, $result->runStatus);
    $this->assertSame(0, $result->swept);
    $this->assertTrue($this->nodeOf(5)->isPublished());
  }

  /**
   * Items that failed make the run end with errors, and pages stay unverified.
   */
  public function testFailedItemsEndWithErrors(): void {
    $run = $this->importRows([['id' => 1, 'name' => 'Acme', 'code' => 'A'], ['id' => 2, 'name' => '', 'code' => 'B']]);

    $result = $this->finishRun($run);

    $this->assertSame(RunStatus::CompletedWithErrors, $result->runStatus);
    $this->assertSame(0, $result->verified);
    $counters = $this->reload($run)->getCounters();
    $this->assertSame(1, $counters['created']);
    $this->assertSame(1, $counters['dead']);
    $this->assertFalse($this->pages->get('customers', 0)?->verified);
  }

  /**
   * A mail reporter mails the report; one that only wants problems stays quiet.
   */
  public function testReporters(): void {
    $this->installConfig(['system']);
    $this->config('system.mail')->set('interface.default', 'test_mail_collector')->save();
    $reporters = [
      ['plugin' => 'mail', 'configuration' => ['recipients' => ['ops@example.com'], 'only_on_problems' => FALSE]],
      ['plugin' => 'mail', 'configuration' => ['recipients' => ['alerts@example.com'], 'only_on_problems' => TRUE]],
      ['plugin' => 'log', 'configuration' => ['only_on_problems' => FALSE]],
    ];

    $result = $this->finishRun($this->importRows($this->rowsOf([1, 2]), ['reporters' => $reporters]));

    $this->assertSame(2, $result->reported);
    $mails = $this->container->get('state')->get('system.test_mail_collector', []);
    $this->assertCount(1, $mails);
    $this->assertSame('ops@example.com', $mails[0]['to']);
    $this->assertSame('Import "Customers": completed', $mails[0]['subject']);
    $this->assertStringContainsString('created: 2', (string) $mails[0]['body']);

    // A run with problems reaches both mail reporters.
    $second = $this->importRows([
      ['id' => 1, 'name' => 'Account 1', 'code' => 'C1'],
      ['id' => 9, 'name' => '', 'code' => 'X'],
    ]);
    $this->assertSame(3, $this->finishRun($second)->reported);
    $mails = $this->container->get('state')->get('system.test_mail_collector', []);
    $this->assertCount(3, $mails);
    $this->assertStringContainsString('First problems:', (string) $mails[2]['body']);
  }

  /**
   * A reporter that fails does not stop the others.
   */
  public function testFailingReporterDoesNotStopOthers(): void {
    $definition = $this->definition($this->accounts([
      'reporters' => [
      ['plugin' => 'missing', 'configuration' => []],
      ['plugin' => 'log', 'configuration' => ['only_on_problems' => FALSE]],
      ],
    ]));
    $report = new RunReport(1, 'customers', 'Customers', RunStatus::Completed, ['created' => 1], '', NULL, NULL, []);

    $this->assertSame(1, $this->container->get('import_engine.run_reporter')->report($definition, $report));
  }

}
