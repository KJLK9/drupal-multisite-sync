<?php

declare(strict_types=1);

namespace Drupal\catalog_graphql\Plugin\GraphQL\DataProducer;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\graphql\Attribute\DataProducer;
use Drupal\graphql\GraphQL\Execution\FieldContext;
use Drupal\graphql\Plugin\GraphQL\DataProducer\DataProducerPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Loads a page of entities, ordered by id, with the total number of matches.
 *
 * Resolves to the `{ items, totalCount }` shape of the *List GraphQL types.
 * Usable for any entity type: pair it with the PaginationTrait resolvers.
 */
#[DataProducer(
  id: 'catalog_entity_list',
  name: new TranslatableMarkup('Entity list'),
  description: new TranslatableMarkup('Loads a page of entities with the total count.'),
  produces: new ContextDefinition(
    data_type: 'any',
    label: new TranslatableMarkup('Page of entities and total count'),
  ),
  consumes: [
    'type' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Entity type'),
    ),
    'limit' => new ContextDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Page size'),
    ),
    'offset' => new ContextDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Number of entities to skip'),
    ),
  ],
)]
final class EntityList extends DataProducerPluginBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs an EntityList object.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $pluginId
   *   The plugin ID.
   * @param array<string, mixed> $pluginDefinition
   *   The plugin definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    array $configuration,
    string $pluginId,
    array $pluginDefinition,
    protected EntityTypeManagerInterface $entityTypeManager,
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
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Resolves a page of entities.
   *
   * The total counts every entity that matches, before the per-entity view
   * access filter that is applied to the items.
   *
   * @param string $type
   *   The entity type ID.
   * @param int $limit
   *   The page size.
   * @param int $offset
   *   The number of entities to skip.
   * @param \Drupal\graphql\GraphQL\Execution\FieldContext $context
   *   The field context, used for cache metadata.
   *
   * @return array{items: list<\Drupal\Core\Entity\EntityInterface>, totalCount: int}
   *   The viewable entities on the page and the total number of matches.
   */
  public function resolve(string $type, int $limit, int $offset, FieldContext $context): array {
    $storage = $this->entityTypeManager->getStorage($type);
    $context->addCacheTags($storage->getEntityType()->getListCacheTags());

    $total = (int) $storage->getQuery()->accessCheck(TRUE)->count()->execute();
    $id_key = $storage->getEntityType()->getKey('id');
    if ($id_key === FALSE) {
      throw new \InvalidArgumentException(sprintf('Entity type %s has no id key to order by.', $type));
    }
    // Offset paging needs a deterministic order.
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->sort($id_key)
      ->range($offset, $limit)
      ->execute();

    $entities = $storage->loadMultiple($ids);
    $items = [];
    foreach ($ids as $id) {
      $entity = $entities[$id] ?? NULL;
      if (!$entity instanceof EntityInterface) {
        continue;
      }
      $access = $entity->access('view', NULL, TRUE);
      $context->addCacheableDependency($access);
      if ($access->isAllowed()) {
        $context->addCacheableDependency($entity);
        $items[] = $entity;
      }
    }

    return ['items' => $items, 'totalCount' => $total];
  }

}
