<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Unit;

use Drupal\import_engine\Path\PathDiscovery;
use Drupal\import_engine\Path\PathResolver;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests reading values and listing paths with dotted notation.
 */
#[CoversClass(PathResolver::class)]
#[CoversClass(PathDiscovery::class)]
#[Group('import_engine')]
class PathTest extends UnitTestCase {

  /**
   * Returns data to read from.
   *
   * @return array<string, mixed>
   *   A decoded response.
   */
  protected function data(): array {
    return [
      'price' => ['customer' => ['customer_code' => 'CUST-1'], 'number' => '9.95'],
      'tags' => ['red', 'blue'],
      'note' => NULL,
      'active' => FALSE,
      'zero' => 0,
    ];
  }

  /**
   * Values are found by name and by list index.
   */
  public function testGet(): void {
    $paths = new PathResolver();

    $this->assertSame('CUST-1', $paths->get($this->data(), 'price.customer.customer_code'));
    $this->assertSame('blue', $paths->get($this->data(), 'tags.1'));
    $this->assertSame($this->data(), $paths->get($this->data(), ''));
  }

  /**
   * A missing path is told apart from a path whose value is NULL, FALSE or 0.
   */
  public function testHasDistinguishesMissingFromEmpty(): void {
    $paths = new PathResolver();

    $this->assertTrue($paths->has($this->data(), 'note'));
    $this->assertNull($paths->get($this->data(), 'note'));
    $this->assertTrue($paths->has($this->data(), 'active'));
    $this->assertTrue($paths->has($this->data(), 'zero'));
    $this->assertFalse($paths->has($this->data(), 'nope'));
    $this->assertFalse($paths->has($this->data(), 'price.customer.nope'));
    // A path cannot go through a scalar.
    $this->assertFalse($paths->has($this->data(), 'price.number.deeper'));
    $this->assertFalse($paths->has($this->data(), 'tags.5'));
  }

  /**
   * Items are returned as a list, a single object becomes a list of one.
   */
  public function testItems(): void {
    $paths = new PathResolver();

    $list = ['data' => ['rows' => [['id' => 1], ['id' => 2]]]];
    $this->assertSame([['id' => 1], ['id' => 2]], $paths->items($list, 'data.rows'));
    $this->assertSame([['id' => 1], ['id' => 2]], $paths->items([['id' => 1], ['id' => 2]], ''));

    $single = ['data' => ['rows' => ['id' => 1]]];
    $this->assertSame([['id' => 1]], $paths->items($single, 'data.rows'));

    $this->assertSame([], $paths->items(['rows' => []], 'rows'));
    $this->assertNull($paths->items($list, 'data.nope'));
    $this->assertNull($paths->items(['rows' => 'text'], 'rows'));
    $this->assertNull($paths->items(['rows' => ['a', 'b']], 'rows'));
  }

  /**
   * The paths of a sample item are listed with their types.
   */
  public function testDiscovery(): void {
    $paths = (new PathDiscovery())->discover($this->data());

    $this->assertSame([
      'price.customer.customer_code' => 'string',
      'price.number' => 'string',
      'tags' => 'list',
      'note' => 'null',
      'active' => 'boolean',
      'zero' => 'integer',
    ], $paths);
    $this->assertSame('float', (new PathDiscovery())->discover(['rate' => 1.5])['rate']);
  }

  /**
   * Every discovered path is valid and leads back to the value.
   */
  public function testDiscoveredPathsResolve(): void {
    $resolver = new PathResolver();
    foreach (array_keys((new PathDiscovery())->discover($this->data())) as $path) {
      $this->assertMatchesRegularExpression(PathResolver::PATTERN, $path);
      $this->assertTrue($resolver->has($this->data(), $path), $path);
    }
  }

}
