<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the page store: one record per page position, overwritten each run.
 */
#[Group('import_engine')]
#[RunTestsInSeparateProcesses]
class PageStoreTest extends StorageTestBase {

  /**
   * A page position is stored and read back with its keys.
   */
  public function testRoundTrip(): void {
    $fingerprint = hash('xxh128', 'page 0');

    $this->pages->put('customers', 0, $fingerprint, ['["1"]', '["2"]', '["Ünï"]'], 7, 100);

    $record = $this->pages->get('customers', 0);
    $this->assertNotNull($record);
    $this->assertSame($fingerprint, $record->fingerprint);
    $this->assertSame(['["1"]', '["2"]', '["Ünï"]'], $record->keys);
    $this->assertSame(7, $record->seenRun);
    $this->assertNull($this->pages->get('customers', 1));
    $this->assertNull($this->pages->get('orders', 0));
  }

  /**
   * Writing a position again replaces it: the table does not grow with runs.
   */
  public function testPutOverwrites(): void {
    foreach ([1, 2, 3, 4] as $run) {
      $this->pages->put('customers', 0, hash('xxh128', "run $run"), ["k$run"], $run, 100 + $run);
      $this->pages->put('customers', 1, hash('xxh128', "other $run"), ["m$run"], $run, 100 + $run);
    }

    $this->assertSame(2, $this->rows('import_page'));
    $record = $this->pages->get('customers', 0);
    $this->assertNotNull($record);
    $this->assertSame(['k4'], $record->keys);
    $this->assertSame(4, $record->seenRun);
  }

  /**
   * The fingerprint takes 16 bytes in the table, not 32 characters.
   */
  public function testFingerprintIsStoredAsSixteenBytes(): void {
    $this->pages->put('customers', 0, hash('xxh128', 'x'), ['k'], 1);

    $stored = (string) $this->field($this->container->get('database')->select('import_page', 'p')->fields('p', ['fingerprint']));

    $this->assertSame(16, strlen($stored));
  }

  /**
   * An invalid fingerprint is refused.
   */
  public function testInvalidFingerprint(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->pages->put('customers', 0, 'not hex', ['k'], 1);
  }

  /**
   * Records behind the end of the data are removed; reset removes them all.
   */
  public function testDeleteFromAndReset(): void {
    foreach (range(0, 4) as $position) {
      $this->pages->put('customers', $position, hash('xxh128', (string) $position), ["k$position"], 1);
    }
    $this->pages->put('orders', 0, hash('xxh128', 'o'), ['o'], 1);

    $this->assertSame(2, $this->pages->deleteFrom('customers', 3));
    $this->assertNotNull($this->pages->get('customers', 2));
    $this->assertNull($this->pages->get('customers', 3));

    $this->assertSame(3, $this->pages->reset('customers'));
    $this->assertNull($this->pages->get('customers', 0));
    $this->assertNotNull($this->pages->get('orders', 0), 'other imports are not touched');
  }

  /**
   * A changed configuration voids the records of that import, and only those.
   */
  public function testConfigFingerprintResetsThePages(): void {
    $this->pages->put('customers', 0, hash('xxh128', 'p'), ['k'], 1);
    $this->pages->put('orders', 0, hash('xxh128', 'o'), ['o'], 1);

    // The first time the fingerprint is new.
    $this->assertTrue($this->pages->syncConfigFingerprint('customers', 'config-a'));
    $this->pages->put('customers', 0, hash('xxh128', 'p'), ['k'], 2);

    $this->assertFalse($this->pages->syncConfigFingerprint('customers', 'config-a'));
    $this->assertNotNull($this->pages->get('customers', 0));

    $this->assertTrue($this->pages->syncConfigFingerprint('customers', 'config-b'));
    $this->assertNull($this->pages->get('customers', 0));
    $this->assertNotNull($this->pages->get('orders', 0));
  }

}
