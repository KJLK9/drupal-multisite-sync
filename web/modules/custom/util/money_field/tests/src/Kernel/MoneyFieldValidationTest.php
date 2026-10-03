<?php

declare(strict_types=1);

namespace Drupal\Tests\money_field\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests validation of the money_field field type.
 */
#[Group('money_field')]
#[RunTestsInSeparateProcesses]
class MoneyFieldValidationTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'entity_test',
    'money_field',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    FieldStorageConfig::create([
      'field_name' => 'price',
      'entity_type' => 'entity_test',
      'type' => 'money_field',
    ])->save();
    FieldConfig::create([
      'field_name' => 'price',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
    ])->save();
  }

  /**
   * Valid and invalid amounts and currencies.
   */
  #[DataProvider('valuesProvider')]
  public function testValidation(string $number, string $currency, int $violations): void {
    $entity = EntityTest::create(['price' => ['number' => $number, 'currency_code' => $currency]]);
    // Validate the money field only: entity_test's owner reference is noise.
    $this->assertCount($violations, $entity->get('price')->validate());
  }

  /**
   * Data provider.
   *
   * @return array<string, array{string, string, int}>
   *   Amount, currency code and expected number of violations.
   */
  public static function valuesProvider(): array {
    return [
      'integer' => ['10', 'EUR', 0],
      'two decimals' => ['9.95', 'USD', 0],
      'six decimals' => ['0.000001', 'GBP', 0],
      'seven decimals' => ['1.0000001', 'EUR', 1],
      'not a number' => ['abc', 'EUR', 1],
      'negative' => ['-5', 'EUR', 1],
      'too many digits' => ['12345678901234', 'EUR', 1],
      'unknown currency' => ['10', 'XXX', 1],
    ];
  }

}
