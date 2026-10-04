<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Kernel;

use Drupal\import_engine\Entity\ImportDefinition;
use Drupal\import_engine\Source\SourceSample;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests trying a source while an import is being set up.
 */
#[Group('import_engine')]
#[RunTestsInSeparateProcesses]
class SourceSamplerTest extends HttpSourceTestBase {

  /**
   * Builds a definition that is not saved, and may be incomplete.
   *
   * @param array<string, mixed> $values
   *   Values that replace the defaults.
   */
  protected function unsaved(array $values = []): ImportDefinition {
    return ImportDefinition::create($values + [
      'id' => 'draft',
      'label' => 'Draft',
      'source' => [
        'plugin' => 'http',
        'configuration' => [
          'url' => 'https://site-a.test/api',
          'method' => 'GET',
          'headers' => [],
          'query' => [],
          'body' => '',
          'items_path' => 'data',
          'timeout' => 10,
          'format' => 'json',
          'csv_delimiter' => ',',
        ],
      ],
      'pagination' => ['plugin' => 'none', 'configuration' => []],
      'authentication' => ['plugin' => 'none', 'configuration' => []],
      'source_key' => ['code'],
    ]);
  }

  /**
   * Tries a definition.
   *
   * @param array<string, mixed> $values
   *   Values that replace the defaults of the definition.
   */
  protected function sample(array $values = []): SourceSample {
    return $this->container->get('import_engine.source_sampler')->sample($this->unsaved($values));
  }

  /**
   * The paths of the sample items are listed, with their type and an example.
   */
  public function testListsPathsWithExamples(): void {
    $this->mockResponses([$this->json([
      'data' => [
      [
        'code' => 'C-1',
        'name' => 'Acme',
        'active' => TRUE,
        'price' => ['amount' => '9.95', 'currency' => 'EUR'],
        'tags' => ['a', 'b'],
      ],
      ['code' => 'C-2', 'name' => 'Globex', 'note' => 'only on the second', 'count' => 3, 'gap' => NULL],
      ],
    ]),
    ]);

    $sample = $this->sample();

    $this->assertTrue($sample->isOk());
    $this->assertSame(2, $sample->items);
    $this->assertSame(['type' => 'string', 'example' => 'C-1'], $sample->paths['code']);
    $this->assertSame(['type' => 'boolean', 'example' => 'true'], $sample->paths['active']);
    $this->assertSame(['type' => 'string', 'example' => '9.95'], $sample->paths['price.amount']);
    $this->assertSame(['type' => 'list', 'example' => '[list of 2]'], $sample->paths['tags']);
    // A path that only some items have is found too, and so are null values.
    $this->assertSame('only on the second', $sample->paths['note']['example']);
    $this->assertSame(['type' => 'integer', 'example' => '3'], $sample->paths['count']);
    $this->assertSame(['type' => 'null', 'example' => 'null'], $sample->paths['gap']);
    $messages = array_column($sample->messages, 'message');
    $this->assertContains('Connected: the first page holds 2 items.', $messages);
  }

  /**
   * A long value is cut short in the example.
   */
  public function testLongExamplesAreCut(): void {
    $this->mockResponses([$this->json(['data' => [['code' => 'C-1', 'text' => str_repeat('x', 100)]]])]);

    $example = $this->sample()->paths['text']['example'];

    $this->assertSame(40, mb_strlen($example));
    $this->assertStringEndsWith('…', $example);
  }

  /**
   * A source that does not answer is a message, not an exception.
   */
  public function testErrorsAreMessages(): void {
    $this->mockResponses([new Response(403)]);

    $sample = $this->sample();

    $this->assertFalse($sample->isOk());
    $this->assertSame([], $sample->paths);
    $this->assertSame('error', $sample->messages[0]['severity']);
    $this->assertStringContainsString('HTTP 403', $sample->messages[0]['message']);
  }

  /**
   * Without a key chosen yet, the keys are not judged.
   */
  public function testNoKeyYetIsNotAnError(): void {
    $this->mockResponses([$this->json(['data' => [['code' => 'C-1']]])]);

    $sample = $this->sample(['source_key' => []]);

    $this->assertTrue($sample->isOk());
    $this->assertContains('No key is chosen yet, so the keys of the items were not checked.', array_column($sample->messages, 'message'));
  }

  /**
   * A key that the items do not have is reported as an error.
   */
  public function testMissingKeyIsAnError(): void {
    $this->mockResponses([$this->json(['data' => [['code' => 'C-1']]])]);

    $this->assertFalse($this->sample(['source_key' => ['id']])->isOk());
  }

  /**
   * A source without items says so, and has no paths to offer.
   */
  public function testEmptySource(): void {
    $this->mockResponses([$this->json(['data' => []])]);

    $sample = $this->sample();

    $this->assertTrue($sample->isOk());
    $this->assertSame([], $sample->paths);
    $this->assertContains('The source returned no items, so the field mapping cannot be built from a sample.', array_column($sample->messages, 'message'));
  }

  /**
   * A source that cannot be created at all is a message too.
   */
  public function testUnknownSourcePlugin(): void {
    $sample = $this->sample(['source' => ['plugin' => 'carrier_pigeon', 'configuration' => []]]);

    $this->assertFalse($sample->isOk());
    $this->assertStringContainsString('The source cannot be used', $sample->messages[0]['message']);
  }

}
