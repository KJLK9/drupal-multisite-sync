<?php

declare(strict_types=1);

namespace Drupal\catalog_graphql\Plugin\GraphQL\DataProducer;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\graphql\Attribute\DataProducer;
use Drupal\graphql\Plugin\GraphQL\DataProducer\DataProducerPluginBase;

/**
 * Reads a money_field of an entity as a GraphQL Money value.
 */
#[DataProducer(
  id: 'catalog_money',
  name: new TranslatableMarkup('Money'),
  description: new TranslatableMarkup('Reads a money field as number and currency code.'),
  produces: new ContextDefinition(
    data_type: 'any',
    label: new TranslatableMarkup('Money'),
  ),
  consumes: [
    'entity' => new ContextDefinition(
      data_type: 'entity',
      label: new TranslatableMarkup('Entity'),
    ),
    'field' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Field name'),
    ),
  ],
)]
final class Money extends DataProducerPluginBase {

  /**
   * Resolves the money value.
   *
   * @param mixed $entity
   *   The entity holding the field.
   * @param string $field
   *   The money field name.
   *
   * @return array{number: string|null, currencyCode: string|null}|null
   *   The amount and currency code, or NULL when the field is empty.
   */
  public function resolve(mixed $entity, string $field): ?array {
    if (!$entity instanceof FieldableEntityInterface || !$entity->hasField($field)) {
      return NULL;
    }
    $item = $entity->get($field)->first();
    if ($item === NULL) {
      return NULL;
    }
    return [
      'number' => $item->get('number')->getValue(),
      'currencyCode' => $item->get('currency_code')->getValue(),
    ];
  }

}
