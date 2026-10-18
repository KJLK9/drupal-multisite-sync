<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Guarantee;

use Drupal\import_engine\Run\RunStatus;
use Drupal\Tests\import_engine\Kernel\FakeSource;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * A large run stays within time and memory, and does not make the tables grow.
 *
 * The numbers are bounds, not measurements: they are far above what a run takes
 * on a development machine, so that a run that suddenly takes ten times longer
 * (a query per item that was one per page) or keeps everything in memory fails
 * here before it does so for a customer.
 */
#[Group('guarantee')]
#[RunTestsInSeparateProcesses]
class ScaleGuaranteeTest extends GuaranteeTestBase {

  /**
   * How many items the large run has.
   */
  private const ITEMS = 5000;

  /**
   * A run of five thousand items ends right, within bounds.
   */
  public function testLargeRunIsRightAndBounded(): void {
    $definition = $this->saveImport();
    $pages = $this->pagesOfItems($this->accountItems(1, self::ITEMS), 100);
    $started = microtime(TRUE);
    $memory = memory_get_usage(TRUE);

    $run = $this->runToEnd($definition, new FakeSource($pages));

    $seconds = microtime(TRUE) - $started;
    $this->assertSame(RunStatus::Completed, $run->getStatus());
    $counters = $run->getCounters();
    $this->assertSame(self::ITEMS, $counters['items_extracted']);
    $this->assertSame(self::ITEMS, $counters['created']);
    $this->assertSame(0, $counters['failed'] + $counters['dead']);
    $this->assertSame(self::ITEMS, $this->rows('node_field_data'), 'Every item is a node.');
    $this->assertLessThan(180, $seconds, 'Five thousand items take minutes at most.');
    $this->assertLessThan(512 * 1024 * 1024, memory_get_peak_usage(TRUE) - $memory, 'Memory does not follow the number of items.');
  }

  /**
   * Running it again changes nothing, and nothing in the tables grows.
   */
  public function testSecondRunDoesNotGrowTheTables(): void {
    $definition = $this->saveImport();
    $pages = $this->pagesOfItems($this->accountItems(1, 1500), 100);
    $this->runToEnd($definition, new FakeSource($pages));
    $mapping = $this->container->get('import_engine.mapping_store')->count('customers');
    $events = $this->rows('import_event');
    $this->assertSame(1500, $mapping);

    $second = $this->runToEnd($definition, new FakeSource($pages), TRUE);

    $this->assertSame(1500, $second->getCounters()['unchanged']);
    $this->assertSame($mapping, $this->container->get('import_engine.mapping_store')->count('customers'), 'One row per source item, however often it is run.');
    $this->assertSame($events, $this->rows('import_event'), 'An item that did not change leaves no event.');
    $this->assertSame(2, $this->rows('import_run'));
  }

}
