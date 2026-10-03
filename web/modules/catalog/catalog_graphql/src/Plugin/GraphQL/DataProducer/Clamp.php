<?php

declare(strict_types=1);

namespace Drupal\catalog_graphql\Plugin\GraphQL\DataProducer;

use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\graphql\Attribute\DataProducer;
use Drupal\graphql\Plugin\GraphQL\DataProducer\DataProducerPluginBase;

/**
 * Clamps an integer argument between a minimum and a maximum.
 */
#[DataProducer(
  id: 'catalog_clamp',
  name: new TranslatableMarkup('Clamp'),
  description: new TranslatableMarkup('Clamps an integer between a minimum and a maximum.'),
  produces: new ContextDefinition(
    data_type: 'integer',
    label: new TranslatableMarkup('Clamped value'),
  ),
  consumes: [
    'value' => new ContextDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Value'),
    ),
    'min' => new ContextDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Minimum'),
    ),
    'max' => new ContextDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Maximum'),
    ),
  ],
)]
final class Clamp extends DataProducerPluginBase {

  /**
   * Resolves the clamped value.
   *
   * @param int $value
   *   The value to clamp.
   * @param int $min
   *   The lowest allowed value.
   * @param int $max
   *   The highest allowed value.
   *
   * @return int
   *   The value, limited to the range from $min to $max.
   */
  public function resolve(int $value, int $min, int $max): int {
    return max($min, min($max, $value));
  }

}
