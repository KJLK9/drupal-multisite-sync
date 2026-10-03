<?php

declare(strict_types=1);

namespace Drupal\catalog_graphql\Plugin\GraphQL\DataProducer;

use Drupal\catalog_graphql\GraphQL\Buffers\PriceBuffer;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\graphql\Attribute\DataProducer;
use Drupal\graphql\GraphQL\Execution\FieldContext;
use Drupal\graphql\Plugin\GraphQL\DataProducer\DataProducerPluginBase;
use GraphQL\Deferred;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Loads the (viewable) prices that point at a product or customer, batched.
 */
#[DataProducer(
  id: 'catalog_prices',
  name: new TranslatableMarkup('Prices'),
  description: new TranslatableMarkup('Loads the prices that reference a product or customer.'),
  produces: new ContextDefinition(
    data_type: 'entity',
    label: new TranslatableMarkup('Product prices'),
    multiple: TRUE,
  ),
  consumes: [
    'entity' => new ContextDefinition(
      data_type: 'entity',
      label: new TranslatableMarkup('Parent entity'),
    ),
    'field' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Price field referencing the parent'),
    ),
    'limit' => new ContextDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Maximum number of prices'),
    ),
  ],
)]
final class Prices extends DataProducerPluginBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a Prices object.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $pluginId
   *   The plugin ID.
   * @param array<string, mixed> $pluginDefinition
   *   The plugin definition.
   * @param \Drupal\catalog_graphql\GraphQL\Buffers\PriceBuffer $buffer
   *   The product price buffer.
   */
  public function __construct(
    array $configuration,
    string $pluginId,
    array $pluginDefinition,
    protected PriceBuffer $buffer,
  ) {
    parent::__construct($configuration, $pluginId, $pluginDefinition);
  }

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param array<string, mixed> $plugin_definition
   *   The plugin definition.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('catalog_graphql.buffer.prices'),
    );
  }

  /**
   * Resolves the prices that reference an entity.
   *
   * The lookup is deferred and batched across all parents in the query.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The product or customer.
   * @param string $field
   *   The price field that references it: "product_id" or "customer".
   * @param int $limit
   *   The maximum number of prices to return.
   * @param \Drupal\graphql\GraphQL\Execution\FieldContext $context
   *   The field context, used for cache metadata.
   *
   * @return \GraphQL\Deferred
   *   Resolves to at most $limit prices the current user may view.
   */
  public function resolve(EntityInterface $entity, string $field, int $limit, FieldContext $context): Deferred {
    $resolver = $this->buffer->add($field, (int) $entity->id());

    return new Deferred(function () use ($resolver, $limit, $context): array {
      $prices = [];
      foreach ($resolver() as $price) {
        if (count($prices) >= $limit) {
          break;
        }
        $access = $price->access('view', NULL, TRUE);
        $context->addCacheableDependency($access);
        if ($access->isAllowed()) {
          $context->addCacheableDependency($price);
          $prices[] = $price;
        }
      }

      // New prices must invalidate this result.
      $context->addCacheTags(['product_price_list']);

      return $prices;
    });
  }

}
