<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Kernel;

use Drupal\import_engine\Mapper\DependencyNotReadyException;
use Drupal\import_engine\Mapper\MapperInterface;
use Drupal\import_engine\Mapper\MappingException;
use Drupal\import_engine\Target\TargetField;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the mapper plugins: choosing by field type and mapping values.
 */
#[Group('import_engine')]
#[RunTestsInSeparateProcesses]
class MapperTest extends StorageTestBase {

  /**
   * Creates a mapper with settings.
   *
   * @param string $id
   *   The mapper plugin ID.
   * @param array<string, mixed> $settings
   *   Settings that replace the defaults.
   */
  protected function mapper(string $id, array $settings = []): MapperInterface {
    $mapper = $this->container->get('plugin.manager.import_engine_mapper')->createInstance($id, $settings);
    $this->assertInstanceOf(MapperInterface::class, $mapper);
    return $mapper;
  }

  /**
   * Maps values for a field of a type.
   *
   * @param \Drupal\import_engine\Mapper\MapperInterface $mapper
   *   The mapper.
   * @param array<string, mixed> $sources
   *   The named source values.
   * @param string $fieldType
   *   The field type of the target field.
   */
  protected function map(MapperInterface $mapper, array $sources, string $fieldType = 'string'): mixed {
    return $mapper->map($sources, new TargetField('field_x', 'X', $fieldType));
  }

  /**
   * The mapper is chosen from the type of the field.
   */
  public function testMappersAreChosenByFieldType(): void {
    $manager = $this->container->get('plugin.manager.import_engine_mapper');

    // A plain text field can be filled from one value or from several.
    $this->assertEqualsCanonicalizing(['string', 'join'], $manager->idsForFieldType('string'));
    $this->assertSame(['string'], $manager->idsForFieldType('email'));
    $this->assertSame(['text'], $manager->idsForFieldType('text_long'));
    $this->assertSame(['number'], $manager->idsForFieldType('integer'));
    $this->assertSame(['number'], $manager->idsForFieldType('decimal'));
    $this->assertSame(['boolean'], $manager->idsForFieldType('boolean'));
    $this->assertSame(['timestamp'], $manager->idsForFieldType('created'));
    $this->assertSame(['money'], $manager->idsForFieldType('money_field'));
    $this->assertSame(['reference'], $manager->idsForFieldType('entity_reference'));
    $this->assertSame([], $manager->idsForFieldType('map'));
    // The sources a mapper reads are part of its definition.
    $this->assertSame(['amount' => TRUE, 'currency' => FALSE], $manager->getDefinition('money')['sources']);
  }

  /**
   * Text is trimmed and an empty text is no value, unless told otherwise.
   */
  public function testString(): void {
    $this->assertSame('Acme', $this->map($this->mapper('string'), ['value' => '  Acme ']));
    $this->assertNull($this->map($this->mapper('string'), ['value' => '   ']));
    $this->assertNull($this->map($this->mapper('string'), []));
    $this->assertSame('42', $this->map($this->mapper('string'), ['value' => 42]));
    $this->assertSame('true', $this->map($this->mapper('string'), ['value' => TRUE]));
    $this->assertSame(' Acme ', $this->map($this->mapper('string', ['trim' => FALSE]), ['value' => ' Acme ']));
    $this->assertSame('', $this->map($this->mapper('string', ['empty_as_null' => FALSE]), ['value' => '']));
  }

  /**
   * A list or object is not a single text.
   */
  public function testStringRefusesLists(): void {
    $this->expectException(MappingException::class);
    $this->map($this->mapper('string'), ['value' => ['a', 'b']]);
  }

  /**
   * Formatted text gets its format; a summary only where the field has one.
   */
  public function testText(): void {
    $mapper = $this->mapper('text', ['format' => 'basic_html']);

    $this->assertSame([
      'value' => '<p>Hi</p>',
      'format' => 'basic_html',
    ], $this->map($mapper, ['value' => '<p>Hi</p>', 'summary' => 'S'], 'text_long'));
    $this->assertSame(
      ['value' => 'Body', 'format' => 'basic_html', 'summary' => 'Sum'],
      $this->map($mapper, ['value' => 'Body', 'summary' => 'Sum'], 'text_with_summary'),
    );
    $this->assertNull($this->map($mapper, ['value' => ''], 'text_long'));
    $this->assertSame('plain_text', $this->map($this->mapper('text'), ['value' => 'x'], 'text_long')['format']);
  }

  /**
   * Numbers become the right kind of number; wrong values are refused.
   */
  public function testNumber(): void {
    $mapper = $this->mapper('number');

    $this->assertSame(5, $this->map($mapper, ['value' => '5'], 'integer'));
    $this->assertSame(5, $this->map($mapper, ['value' => 5.0], 'integer'));
    $this->assertSame(7, $this->map($mapper, ['value' => ' 7 '], 'integer'));
    $this->assertSame(1.5, $this->map($mapper, ['value' => '1.5'], 'float'));
    // A decimal keeps its exact text.
    $this->assertSame('9.950', $this->map($mapper, ['value' => '9.950'], 'decimal'));
    $this->assertNull($this->map($mapper, ['value' => ''], 'integer'));
    $this->assertNull($this->map($mapper, ['value' => NULL], 'integer'));
  }

  /**
   * Values that are not numbers, or have decimals for a whole number.
   *
   * @param mixed $value
   *   The value.
   * @param string $type
   *   The field type.
   * @param string $message
   *   The expected message.
   */
  #[DataProvider('invalidNumbersProvider')]
  public function testNumberRefusals(mixed $value, string $type, string $message): void {
    $this->expectException(MappingException::class);
    $this->expectExceptionMessage($message);
    $this->map($this->mapper('number'), ['value' => $value], $type);
  }

  /**
   * Data provider.
   *
   * @return array<string, array{mixed, string, string}>
   *   The value, the field type and the expected message.
   */
  public static function invalidNumbersProvider(): array {
    return [
      'text' => ['abc', 'integer', '"abc" is not a number'],
      'decimals for a whole number' => ['5.5', 'integer', '"5.5" is not a whole number'],
      'text for a decimal' => ['12,5', 'decimal', 'is not a number'],
    ];
  }

  /**
   * Yes and no words are read without regard to case, and can be changed.
   */
  public function testBoolean(): void {
    $mapper = $this->mapper('boolean');

    foreach (['1', 'true', 'TRUE', 'Yes', 'y', 'on', 1, TRUE] as $yes) {
      $this->assertTrue($this->map($mapper, ['value' => $yes], 'boolean'), (string) json_encode($yes));
    }
    foreach (['0', 'false', 'No', 'n', 'OFF', 0, FALSE] as $no) {
      $this->assertFalse($this->map($mapper, ['value' => $no], 'boolean'), (string) json_encode($no));
    }
    $custom = $this->mapper('boolean', ['true_values' => ['actief'], 'false_values' => ['inactief']]);
    $this->assertTrue($this->map($custom, ['value' => 'Actief'], 'boolean'));
    $this->assertFalse($this->map($custom, ['value' => 'inactief'], 'boolean'));
  }

  /**
   * A missing value means what is configured; an unknown word is refused.
   */
  public function testBooleanEmptyAndUnknown(): void {
    $this->assertFalse($this->map($this->mapper('boolean'), [], 'boolean'));
    $this->assertTrue($this->map($this->mapper('boolean', ['when_empty' => 'true']), ['value' => ''], 'boolean'));

    try {
      $this->map($this->mapper('boolean', ['when_empty' => 'fail']), [], 'boolean');
      $this->fail('Expected a MappingException.');
    }
    catch (MappingException) {
      $this->addToAssertionCount(1);
    }

    $this->expectException(MappingException::class);
    $this->expectExceptionMessage('neither a yes nor a no');
    $this->map($this->mapper('boolean'), ['value' => 'maybe'], 'boolean');
  }

  /**
   * Timestamps, ISO dates and dates in a given format all become a timestamp.
   */
  public function testTimestamp(): void {
    $mapper = $this->mapper('timestamp');
    $iso = new \DateTimeImmutable('2026-10-04T12:00:00+00:00');

    $this->assertSame(1700000000, $this->map($mapper, ['value' => '1700000000'], 'timestamp'));
    $this->assertSame(1700000000, $this->map($mapper, ['value' => 1700000000], 'timestamp'));
    $this->assertSame($iso->getTimestamp(), $this->map($mapper, ['value' => '2026-10-04T12:00:00+00:00'], 'timestamp'));
    $this->assertNull($this->map($mapper, ['value' => ''], 'timestamp'));

    $formatted = $this->mapper('timestamp', ['format' => 'd/m/Y']);
    $expected = \DateTimeImmutable::createFromFormat('!d/m/Y', '04/10/2026');
    $this->assertNotFalse($expected);
    $this->assertSame($expected->getTimestamp(), $this->map($formatted, ['value' => '04/10/2026'], 'timestamp'));
  }

  /**
   * Text that is not a date, or does not follow the format, is refused.
   */
  public function testTimestampRefusals(): void {
    foreach ([['', 'not a date'], ['d/m/Y', '2026-10-04']] as [$format, $value]) {
      try {
        $this->map($this->mapper('timestamp', ['format' => $format]), ['value' => $value], 'timestamp');
        $this->fail('Expected a MappingException for ' . $value);
      }
      catch (MappingException) {
        $this->addToAssertionCount(1);
      }
    }
  }

  /**
   * An amount and a currency fill a money field.
   */
  public function testMoney(): void {
    $mapper = $this->mapper('money');

    $this->assertSame([
      'number' => '9.95',
      'currency_code' => 'USD',
    ], $this->map($mapper, ['amount' => '9.95', 'currency' => 'usd'], 'money_field'));
    $this->assertSame(['number' => '10', 'currency_code' => 'EUR'], $this->map($mapper, ['amount' => 10], 'money_field'));
    $this->assertSame('GBP', $this->map($this->mapper('money', ['default_currency' => 'GBP']), ['amount' => '1'], 'money_field')['currency_code']);
    $this->assertNull($this->map($mapper, ['amount' => ''], 'money_field'));
    $this->assertNull($this->map($mapper, ['currency' => 'EUR'], 'money_field'));
  }

  /**
   * An amount that is not a number, or a currency that is not a code, fails.
   */
  public function testMoneyRefusals(): void {
    foreach ([['amount' => 'ten'], ['amount' => '5', 'currency' => 'EURO']] as $sources) {
      try {
        $this->map($this->mapper('money'), $sources, 'money_field');
        $this->fail('Expected a MappingException.');
      }
      catch (MappingException) {
        $this->addToAssertionCount(1);
      }
    }
  }

  /**
   * Several values are joined; parts that are missing are left out.
   */
  public function testJoin(): void {
    $join = $this->mapper('join', ['separator' => ' / ']);

    $this->assertSame('Widget / Acme', $this->map($join, ['first' => ' Widget ', 'second' => 'Acme']));
    $this->assertSame('Widget / Acme / 7', $this->map($join, ['first' => 'Widget', 'second' => 'Acme', 'third' => 7]));
    $this->assertSame('Widget', $this->map($join, ['first' => 'Widget', 'second' => NULL, 'third' => '  ']));
    $this->assertNull($this->map($join, ['first' => '', 'second' => NULL]));
    // A part that is missing can stay in as an empty part.
    $keep = $this->mapper('join', ['separator' => ' / ', 'skip_empty' => FALSE]);
    $this->assertSame('Widget / ', $this->map($keep, ['first' => 'Widget', 'second' => '']));
    $this->assertSame('Widget /  / 7', $this->map($keep, ['first' => 'Widget', 'second' => '', 'third' => 7]));
    $this->assertSame('Widget - Acme', $this->map($this->mapper('join'), ['first' => 'Widget', 'second' => 'Acme']));
    // The sources are part of its definition.
    $this->assertSame(['first' => TRUE, 'second' => TRUE, 'third' => FALSE], $this->container->get('plugin.manager.import_engine_mapper')->getDefinition('join')['sources']);
  }

  /**
   * A reference finds what another import wrote; one not yet there is retried.
   */
  public function testReference(): void {
    $this->container->get('import_engine.mapping_store')->record('products', '["P-1"]', 'node', '42', NULL, 1, TRUE);
    $mapper = $this->mapper('reference', ['definition' => 'products', 'required' => TRUE]);

    $this->assertSame(['target_id' => '42'], $this->map($mapper, ['id' => ' P-1 '], 'entity_reference'));

    try {
      $this->map($mapper, ['id' => 'P-2'], 'entity_reference');
      $this->fail('Expected a DependencyNotReadyException.');
    }
    catch (DependencyNotReadyException $exception) {
      $this->assertStringContainsString('"P-2" of the import "products" is not imported yet', $exception->getMessage());
    }

    try {
      $this->map($mapper, ['id' => ''], 'entity_reference');
      $this->fail('Expected a MappingException.');
    }
    catch (MappingException) {
      $this->addToAssertionCount(1);
    }
  }

  /**
   * An optional reference is empty when there is nothing to refer to.
   */
  public function testOptionalReference(): void {
    $mapper = $this->mapper('reference', ['definition' => 'products', 'required' => FALSE]);

    $this->assertNull($this->map($mapper, ['id' => 'unknown'], 'entity_reference'));
    $this->assertNull($this->map($mapper, [], 'entity_reference'));
  }

  /**
   * Settings are validated by the schema of each mapper.
   */
  public function testSettingsAreValidated(): void {
    $typed = $this->container->get('config.typed');
    $violations = static function (string $type, array $data) use ($typed): array {
      $paths = [];
      foreach ($typed->createFromNameAndData('import_engine.mapper.' . $type, $data)->validate() as $violation) {
        $paths[] = $violation->getPropertyPath();
      }
      return $paths;
    };

    $this->assertSame([], $violations('money', ['default_currency' => 'EUR']));
    $this->assertSame(['default_currency'], $violations('money', ['default_currency' => 'euro']));
    // The three words are texts; YAML would read true and false as booleans.
    foreach (['true', 'false', 'fail'] as $when_empty) {
      $this->assertSame([], $violations('boolean', [
        'true_values' => ['1'],
        'false_values' => ['0'],
        'when_empty' => $when_empty,
      ]), $when_empty);
    }
    $this->assertSame([
      'when_empty',
    ], $violations('boolean', ['true_values' => ['1'], 'false_values' => ['0'], 'when_empty' => 'maybe']));
    $this->assertSame(['definition'], $violations('reference', ['definition' => 'Bad Id', 'required' => TRUE]));
    $this->assertSame(['format'], $violations('text', ['format' => 'Basic HTML']));
  }

}
