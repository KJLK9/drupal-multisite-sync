<?php

declare(strict_types=1);

namespace Drupal\money_field\Plugin\Field\FieldType;

use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\money_field\CurrencyCode;

/**
 * Defines the 'money_field' field type.
 */
#[FieldType(
  id: 'money_field',
  label: new TranslatableMarkup('Money field'),
  description: new TranslatableMarkup('Stores an amount with a currency code.'),
  default_widget: 'money_field',
  default_formatter: 'money_field',
)]
final class MoneyFieldItem extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function mainPropertyName(): string {
    return 'number';
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty(): bool {
    return match ($this->get('number')->getValue()) {
      NULL, '' => TRUE,
      default => FALSE,
    };
  }

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition): array {
    $properties['number'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Amount'))
      ->setRequired(TRUE);

    $properties['currency_code'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Currency code'))
      ->setRequired(TRUE);

    return $properties;
  }

  /**
   * {@inheritdoc}
   *
   * @param \Drupal\Core\Field\FieldStorageDefinitionInterface $field_definition
   *   The field definition.
   *
   * @return array<string, mixed>
   *   The field schema.
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition): array {
    return [
      'columns' => [
        'number' => [
          'type' => 'numeric',
          'precision' => 19,
          'scale' => 6,
        ],
        'currency_code' => [
          'type' => 'varchar',
          'length' => 3,
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The constraints.
   */
  public function getConstraints(): array {
    $constraints = parent::getConstraints();

    $constraints[] = $this->getTypedDataManager()
      ->getValidationConstraintManager()
      ->create('ComplexData', [
        'currency_code' => [
          'Choice' => ['choices' => array_column(CurrencyCode::cases(), 'value')],
        ],
      ]);

    return $constraints;
  }

  /**
   * {@inheritdoc}
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field definition.
   *
   * @return array<string, mixed>
   *   The sample field values.
   */
  public static function generateSampleValue(FieldDefinitionInterface $field_definition): array {
    $values['number'] = (string) (rand(100, 10000) / 100);
    $values['currency_code'] = CurrencyCode::cases()[array_rand(CurrencyCode::cases())]->value;
    return $values;
  }

}
