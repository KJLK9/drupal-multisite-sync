<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Unit;

use Drupal\import_engine\Source\GraphqlVariables;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests the plain lines in which the variables of a GraphQL query are typed.
 */
#[Group('import_engine')]
class GraphqlVariablesTest extends TestCase {

  /**
   * Lines become typed JSON.
   *
   * @param string $text
   *   What was typed.
   * @param string $json
   *   The JSON that is kept.
   */
  #[DataProvider('lines')]
  public function testLinesBecomeJson(string $text, string $json): void {
    $this->assertSame($json, GraphqlVariables::toJson($text));
  }

  /**
   * Cases of lines.
   *
   * @return array<string, array{string, string}>
   *   The cases.
   */
  public static function lines(): array {
    return [
      'empty' => ['', ''],
      'a number' => ['first = 100', '{"first":100}'],
      'a decimal and a negative' => ["a = 1.5\nb = -3", '{"a":1.5,"b":-3}'],
      'booleans and null' => ["a = true\nb = false\nc = null", '{"a":true,"b":false,"c":null}'],
      'a text' => ['status = published', '{"status":"published"}'],
      'a quoted text stays text' => ['code = "100"', '{"code":"100"}'],
      'blank lines and spaces' => ["\n  first=5  \n\n", '{"first":5}'],
      'a json object as it is' => ['{"a": {"b": 1}}', '{"a":{"b":1}}'],
      'an empty json object' => ['{}', ''],
    ];
  }

  /**
   * A line that is no assignment, and broken JSON, are refused in words.
   */
  public function testMistakesAreRefused(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Line 2: write a variable as name = value.');
    GraphqlVariables::toJson("first = 1\nnonsense");
  }

  /**
   * Broken JSON is refused.
   */
  public function testBrokenJsonIsRefused(): void {
    $this->expectException(\InvalidArgumentException::class);
    GraphqlVariables::toJson('{"a": ');
  }

  /**
   * The stored JSON is shown as lines when it can be, and reads back the same.
   */
  public function testJsonIsShownAsLines(): void {
    $json = '{"first":100,"status":"published","code":"100","on":true,"none":null}';
    $lines = GraphqlVariables::toLines($json);

    $this->assertSame("first = 100\nstatus = published\ncode = \"100\"\non = true\nnone = null", $lines);
    $this->assertSame($json, GraphqlVariables::toJson($lines));
  }

  /**
   * Nested JSON stays JSON, since lines cannot show it.
   */
  public function testNestedJsonStaysJson(): void {
    $this->assertSame('{"a":{"b":1}}', GraphqlVariables::toLines('{"a":{"b":1}}'));
    $this->assertSame('', GraphqlVariables::toLines(''));
  }

}
