<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Unit;

use Drupal\import_engine\Key\InvalidKeyException;
use Drupal\import_engine\Key\ItemKey;
use Drupal\import_engine\Path\PathResolver;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the key that identifies a source item.
 */
#[CoversClass(ItemKey::class)]
#[Group('import_engine')]
class ItemKeyTest extends UnitTestCase {

  /**
   * Returns the key builder.
   */
  protected function key(): ItemKey {
    return new ItemKey(new PathResolver());
  }

  /**
   * A key is a JSON list of the values, as text.
   */
  public function testSingleAndCompositeKeys(): void {
    $item = ['id' => 42, 'customer' => ['code' => 'CUST-1'], 'site' => 'a'];

    $this->assertSame('["42"]', $this->key()->build($item, ['id']));
    $this->assertSame('["CUST-1","a"]', $this->key()->build($item, ['customer.code', 'site']));
  }

  /**
   * The same value as a number and as text is the same key.
   */
  public function testNumbersAndTextAreTheSameValue(): void {
    $this->assertSame(
      $this->key()->build(['id' => 1], ['id']),
      $this->key()->build(['id' => '1'], ['id']),
    );
    $this->assertSame('["true"]', $this->key()->build(['flag' => TRUE], ['flag']));
  }

  /**
   * Values cannot run together: ["a", "bc"] is not ["ab", "c"].
   */
  public function testValuesCannotRunTogether(): void {
    $this->assertNotSame(
      $this->key()->build(['a' => 'a', 'b' => 'bc'], ['a', 'b']),
      $this->key()->build(['a' => 'ab', 'b' => 'c'], ['a', 'b']),
    );
    // The order of the key paths is part of the key.
    $this->assertNotSame(
      $this->key()->build(['a' => '1', 'b' => '2'], ['a', 'b']),
      $this->key()->build(['a' => '1', 'b' => '2'], ['b', 'a']),
    );
  }

  /**
   * A key that does not fit is replaced by a hash of fixed length.
   */
  public function testLongKeysAreHashed(): void {
    $long = str_repeat('x', 300);

    $key = $this->key()->build(['id' => $long], ['id']);

    $this->assertMatchesRegularExpression('/^h:[0-9a-f]{32}$/', $key);
    $this->assertSame($key, $this->key()->build(['id' => $long], ['id']));
    $this->assertNotSame($key, $this->key()->build(['id' => $long . 'y'], ['id']));
    $this->assertLessThanOrEqual(ItemKey::MAX_LENGTH, strlen($key));
    // A key just inside the limit is kept as it is.
    $inside = $this->key()->build(['id' => str_repeat('x', 180)], ['id']);
    $this->assertStringStartsWith('["xxx', $inside);
  }

  /**
   * Missing, empty and non-scalar values are refused with a clear reason.
   *
   * @param array<string, mixed> $item
   *   The item.
   * @param list<string> $paths
   *   The key paths.
   * @param string $message
   *   The expected message.
   */
  #[DataProvider('invalidProvider')]
  public function testInvalidKeys(array $item, array $paths, string $message): void {
    $this->expectException(InvalidKeyException::class);
    $this->expectExceptionMessage($message);
    $this->key()->build($item, $paths);
  }

  /**
   * Data provider.
   *
   * @return array<string, array{array<string, mixed>, list<string>, string}>
   *   The item, the key paths and the expected message.
   */
  public static function invalidProvider(): array {
    return [
      'missing' => [['name' => 'x'], ['id'], 'no value at "id"'],
      'null' => [['id' => NULL], ['id'], 'the value at "id" is empty'],
      'empty text' => [['id' => ''], ['id'], 'the value at "id" is empty'],
      'a list' => [['id' => [1, 2]], ['id'], 'not a single value'],
      'an object' => [['id' => ['a' => 1]], ['id'], 'not a single value'],
      'one of several missing' => [['a' => '1'], ['a', 'b'], 'no value at "b"'],
      'no key paths' => [['id' => 1], [], 'no key paths'],
    ];
  }

}
