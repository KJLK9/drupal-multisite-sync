<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Unit;

use Drupal\import_engine\Form\TextLists;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests lists and maps as text in a form field.
 */
#[CoversClass(TextLists::class)]
#[Group('import_engine')]
class TextListsTest extends UnitTestCase {

  /**
   * Lines are trimmed and empty ones are dropped, whatever the line ending.
   */
  public function testLines(): void {
    $this->assertSame(['a', 'b', 'c'], TextLists::lines("  a \r\n\n b\n\n\tc\t\n"));
    $this->assertSame([], TextLists::lines(" \n \n"));
    $this->assertSame([], TextLists::lines(''));
  }

  /**
   * A list goes to text and back unchanged.
   */
  public function testListRoundTrip(): void {
    $values = ['yes', 'y', '1'];

    $this->assertSame($values, TextLists::lines(TextLists::formatLines($values)));
    $this->assertSame("1\n2", TextLists::formatLines([1, 2]));
  }

  /**
   * Pairs are split at the first separator only.
   */
  public function testPairs(): void {
    $this->assertSame(
      ['Accept' => 'application/json', 'X-Time' => '12:30'],
      TextLists::pairs("Accept: application/json\n  X-Time:  12:30 \n", ': '),
    );
    $this->assertSame(['a' => 'b=c', 'd' => ''], TextLists::pairs("a=b=c\nd=", '='));
  }

  /**
   * A later line with the same name wins.
   */
  public function testLaterLineWins(): void {
    $this->assertSame(['a' => '2'], TextLists::pairs("a=1\na=2", '='));
  }

  /**
   * A line that is not a pair is an error that names the line.
   */
  public function testInvalidPairs(): void {
    foreach (["a=1\nnonsense", '=value', "ok=1\n\n=x"] as $text) {
      try {
        TextLists::pairs($text, '=');
        $this->fail('Expected an exception for: ' . $text);
      }
      catch (\InvalidArgumentException $exception) {
        $this->assertStringContainsString('must look like name=value', $exception->getMessage());
      }
    }
    $this->expectExceptionMessage('Line 2 ("nonsense")');
    TextLists::pairs("a=1\nnonsense", '=');
  }

  /**
   * Pairs go to text and back unchanged.
   */
  public function testPairsRoundTrip(): void {
    $pairs = ['Accept' => 'application/json', 'X-Key' => 'v'];

    $this->assertSame($pairs, TextLists::pairs(TextLists::formatPairs($pairs, ': '), ': '));
    $this->assertSame('', TextLists::formatPairs([], '='));
  }

}
