<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Unit;

use Drupal\import_engine\Target\FieldValueText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests the short text of a field value in the change log.
 */
#[Group('import_engine')]
class FieldValueTextTest extends TestCase {

  /**
   * The cases.
   *
   * @return array<string, array{array<int|string, mixed>, string}>
   *   The items and the text.
   */
  public static function cases(): array {
    return [
      'nothing' => [[], ''],
      'one value' => [[['value' => 'Acme']], 'Acme'],
      'empty parts are left out' => [[['value' => 'Acme', 'format' => '']], 'Acme'],
      'several properties' => [[['number' => '12.50', 'currency_code' => 'EUR']], 'number 12.50, currency_code EUR'],
      'several items' => [[['target_id' => 3], ['target_id' => 4]], '3 | 4'],
      'a flag' => [[['value' => TRUE]], '1'],
      'plain scalars' => [['a', 5], 'a | 5'],
    ];
  }

  /**
   * Values become text.
   *
   * @param array<int|string, mixed> $items
   *   The items of the field.
   * @param string $expected
   *   The text.
   */
  #[DataProvider('cases')]
  public function testText(array $items, string $expected): void {
    $this->assertSame($expected, FieldValueText::of($items));
  }

  /**
   * A long value is cut, and says so.
   */
  public function testLongValuesAreCut(): void {
    $text = FieldValueText::of([['value' => str_repeat('é', 800)]]);

    $this->assertSame(FieldValueText::MAX_LENGTH, mb_strlen($text));
    $this->assertStringEndsWith('…', $text);
  }

}
