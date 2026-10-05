<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Kernel;

use Drupal\import_engine\Mapper\MappingSuggester;
use Drupal\import_engine\Target\TargetField;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the suggestions of which source value fills which field.
 */
#[Group('import_engine')]
#[RunTestsInSeparateProcesses]
class MappingSuggesterTest extends StorageTestBase {

  /**
   * The suggester.
   */
  protected MappingSuggester $suggester;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->suggester = $this->container->get('import_engine.mapping_suggester');
  }

  /**
   * Builds the fields of a target.
   *
   * @param array<string, string> $types
   *   The type of each field, by name.
   *
   * @return array<string, \Drupal\import_engine\Target\TargetField>
   *   The fields.
   */
  protected function fields(array $types): array {
    $fields = [];
    foreach ($types as $name => $type) {
      $fields[$name] = new TargetField($name, ucfirst($name), $type);
    }
    return $fields;
  }

  /**
   * Builds the paths of a sample from their types.
   *
   * @param array<string, string> $types
   *   The type of each path.
   *
   * @return array<string, array{type: string, example: string}>
   *   The paths.
   */
  protected function paths(array $types): array {
    return array_map(static fn (string $type): array => ['type' => $type, 'example' => 'x'], $types);
  }

  /**
   * The fields of an account are found in a customer.
   */
  public function testCustomerToAccount(): void {
    $fields = $this->fields([
      'name' => 'string',
      'number' => 'string',
      'notes' => 'text_long',
      'source_id' => 'string',
      'status' => 'boolean',
      'created' => 'created',
    ]);
    $paths = $this->paths([
      'id' => 'string',
      'uuid' => 'string',
      'label' => 'string',
      'customerNumber' => 'string',
      'status' => 'boolean',
      'description' => 'string',
    ]);

    $suggestions = $this->suggester->suggest($fields, $paths);

    $this->assertSame([
      'name' => ['mapper' => 'string', 'sources' => ['value' => 'label']],
      'number' => ['mapper' => 'string', 'sources' => ['value' => 'customerNumber']],
      'notes' => ['mapper' => 'text', 'sources' => ['value' => 'description']],
      'source_id' => ['mapper' => 'string', 'sources' => ['value' => 'id']],
      'status' => ['mapper' => 'boolean', 'sources' => ['value' => 'status']],
    ], $suggestions, 'created has nothing to suggest.');
  }

  /**
   * An amount and its currency are found as a pair.
   */
  public function testProductToItem(): void {
    $fields = $this->fields([
      'title' => 'string',
      'list_price' => 'money_field',
      'summary' => 'text_long',
      'sku' => 'string',
    ]);
    $paths = $this->paths([
      'id' => 'string',
      'label' => 'string',
      'description' => 'string',
      'basePrice.number' => 'string',
      'basePrice.currencyCode' => 'string',
    ]);

    $suggestions = $this->suggester->suggest($fields, $paths);

    $this->assertSame([
      'mapper' => 'money',
      'sources' => ['amount' => 'basePrice.number', 'currency' => 'basePrice.currencyCode'],
    ], $suggestions['list_price']);
    $this->assertSame('label', $suggestions['title']['sources']['value']);
    $this->assertSame('description', $suggestions['summary']['sources']['value']);
    $this->assertArrayNotHasKey('sku', $suggestions, 'Nothing in the sample is a SKU.');
  }

  /**
   * Of several amounts the one that belongs to the field is taken.
   */
  public function testTheAmountThatBelongsToTheField(): void {
    $fields = $this->fields(['price' => 'money_field', 'base_price' => 'money_field']);
    $paths = $this->paths([
      'price.number' => 'string',
      'price.currencyCode' => 'string',
      'basePrice.number' => 'string',
      'basePrice.currencyCode' => 'string',
    ]);

    $suggestions = $this->suggester->suggest($fields, $paths);

    $this->assertSame('price.number', $suggestions['price']['sources']['amount']);
    $this->assertSame('price.currencyCode', $suggestions['price']['sources']['currency']);
    $this->assertSame('basePrice.number', $suggestions['base_price']['sources']['amount']);
    $this->assertSame('basePrice.currencyCode', $suggestions['base_price']['sources']['currency']);
  }

  /**
   * An amount without a currency next to it is suggested without one.
   */
  public function testAmountWithoutCurrency(): void {
    $suggestions = $this->suggester->suggest($this->fields(['price' => 'money_field']), $this->paths(['price' => 'string']));

    $this->assertSame(['amount' => 'price'], $suggestions['price']['sources']);
  }

  /**
   * Nested paths, camelCase and underscores are the same words.
   */
  public function testStylesOfNamesAreTheSameWords(): void {
    $fields = $this->fields(['customer_number' => 'string']);

    foreach (['customerNumber', 'customer_number', 'customer.number', 'CustomerNumber'] as $path) {
      $this->assertSame($path, $this->suggester->suggest($fields, $this->paths([$path => 'string']))['customer_number']['sources']['value'], $path);
    }
  }

  /**
   * A weak resemblance is not a suggestion.
   */
  public function testWeakMatchesAreLeftAlone(): void {
    $paths = $this->paths(['product.label' => 'string', 'customer.label' => 'string', 'id' => 'string']);

    $suggestions = $this->suggester->suggest($this->fields(['title' => 'string', 'quantity' => 'integer']), $paths);

    $this->assertSame([], $suggestions, 'A title is not for certain the label of a product.');
  }

  /**
   * What cannot hold one value is not offered, and mapped fields are skipped.
   */
  public function testListsAndMappedFieldsAreSkipped(): void {
    $fields = $this->fields(['tags' => 'string', 'name' => 'string']);
    $paths = $this->paths(['tags' => 'list', 'name' => 'string']);

    $suggestions = $this->suggester->suggest($fields, $paths, ['name']);

    $this->assertSame([], $suggestions);
  }

  /**
   * A flag is not filled from a number that merely has a similar name.
   */
  public function testTypesMustFit(): void {
    $fields = $this->fields(['active' => 'boolean']);

    $this->assertSame([], $this->suggester->suggest($fields, $this->paths(['active' => 'float'])));
    $this->assertSame('enabled', $this->suggester->suggest($fields, $this->paths(['enabled' => 'boolean']))['active']['sources']['value']);
  }

  /**
   * Fields that need a lookup or a join are left to a person.
   */
  public function testReferencesAreNotGuessed(): void {
    $fields = $this->fields(['account' => 'entity_reference']);

    $this->assertSame([], $this->suggester->suggest($fields, $this->paths([
      'customer.customerNumber' => 'string',
      'account' => 'string',
    ])));
  }

  /**
   * Nothing in the sample is nothing to suggest.
   */
  public function testEmptySample(): void {
    $this->assertSame([], $this->suggester->suggest($this->fields(['name' => 'string']), []));
  }

}
