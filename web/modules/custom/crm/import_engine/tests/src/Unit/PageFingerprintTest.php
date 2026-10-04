<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Unit;

use Drupal\import_engine\Page\PageFingerprint;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the fingerprint of a page.
 */
#[CoversClass(PageFingerprint::class)]
#[Group('import_engine')]
class PageFingerprintTest extends UnitTestCase {

  /**
   * Returns a page of items.
   *
   * @return list<array<string, mixed>>
   *   The items.
   */
  protected function page(int $count = 100): array {
    $items = [];
    for ($id = 1; $id <= $count; $id++) {
      $items[] = [
        'id' => $id,
        'label' => "Customer $id",
        'price' => ['number' => '9.95', 'currency' => 'EUR'],
        'tags' => ['a', 'b'],
      ];
    }
    return $items;
  }

  /**
   * The same data gives the same fingerprint: 32 hex characters.
   */
  public function testStable(): void {
    $fingerprint = new PageFingerprint();

    $this->assertSame($fingerprint->fingerprint($this->page()), $fingerprint->fingerprint($this->page()));
    $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $fingerprint->fingerprint($this->page()));
    $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $fingerprint->fingerprint([]));
  }

  /**
   * The order of keys in an object does not matter, the order of items does.
   */
  public function testKeyOrderIsIgnoredItemOrderIsNot(): void {
    $fingerprint = new PageFingerprint();

    $a = [['id' => 1, 'label' => 'x', 'price' => ['n' => 1, 'c' => 'EUR']]];
    $b = [['price' => ['c' => 'EUR', 'n' => 1], 'label' => 'x', 'id' => 1]];
    $this->assertSame($fingerprint->fingerprint($a), $fingerprint->fingerprint($b));

    $two = [['id' => 1], ['id' => 2]];
    $this->assertNotSame($fingerprint->fingerprint($two), $fingerprint->fingerprint(array_reverse($two)));
    $this->assertNotSame(
      $fingerprint->fingerprint([['tags' => ['a', 'b']]]),
      $fingerprint->fingerprint([['tags' => ['b', 'a']]]),
    );
  }

  /**
   * A change anywhere on the page, in the middle too, changes the fingerprint.
   *
   * This is why the whole page is hashed and not a sample of its bytes: a
   * sample would call two such pages the same and an update would be missed.
   */
  public function testChangeAnywhereIsSeen(): void {
    $fingerprint = new PageFingerprint();
    $original = $fingerprint->fingerprint($this->page());

    foreach ([0, 49, 50, 99] as $index) {
      $changed = $this->page();
      $changed[$index]['price']['number'] = '10.95';
      $this->assertNotSame($original, $fingerprint->fingerprint($changed), "A change in item $index was not seen.");
    }
    $this->assertNotSame($original, $fingerprint->fingerprint(array_slice($this->page(), 0, 99)));
  }

  /**
   * Values of different types are different data.
   */
  public function testTypesMatter(): void {
    $fingerprint = new PageFingerprint();

    $this->assertNotSame($fingerprint->fingerprint([['n' => 1]]), $fingerprint->fingerprint([['n' => '1']]));
    $this->assertNotSame($fingerprint->fingerprint([['n' => NULL]]), $fingerprint->fingerprint([['n' => '']]));
    $this->assertSame($fingerprint->fingerprint([['s' => 'é/ü']]), $fingerprint->fingerprint([['s' => 'é/ü']]));
  }

}
