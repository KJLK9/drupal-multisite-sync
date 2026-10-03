<?php

declare(strict_types=1);

namespace Drupal\catalog_graphql\GraphQL\Buffers;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\graphql\GraphQL\Buffers\BufferBase;

/**
 * Collects parents and loads all their prices with a single query.
 *
 * A price points at its parents through the `product_id` and `customer`
 * fields, so the same buffer serves Product.prices and Customer.prices.
 * Without it, resolving `prices` for N parents costs N entity queries.
 */
class PriceBuffer extends BufferBase {

  /**
   * Constructs a PriceBuffer object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  /**
   * Adds a parent entity to the buffer.
   *
   * @param string $field
   *   The price field that references the parent: "product_id" or "customer".
   * @param int|string $parentId
   *   The ID of the parent to load the prices for.
   *
   * @return \Closure
   *   Callback returning the prices that reference this parent.
   */
  public function add(string $field, int|string $parentId): \Closure {
    return $this->createBufferResolver(new \ArrayObject([
      'field' => $field,
      'parent_id' => $parentId,
    ]));
  }

  /**
   * {@inheritdoc}
   *
   * @param \ArrayObject<string, mixed> $item
   *   The buffered item.
   */
  protected function getBufferId(\ArrayObject $item): string {
    // One batch (and query) per referencing field.
    return $item['field'];
  }

  /**
   * {@inheritdoc}
   *
   * @param array<int, \ArrayObject<string, mixed>> $buffer
   *   The buffered items, all for the same field.
   *
   * @return array<int, array<int, \Drupal\Core\Entity\EntityInterface>>
   *   The prices per buffered item.
   */
  public function resolveBufferArray(array $buffer): array {
    $first = reset($buffer);
    if ($first === FALSE) {
      return [];
    }
    $field = $first['field'];
    $parent_ids = array_values(array_unique(array_map(
      static fn (\ArrayObject $item): int|string => $item['parent_id'],
      $buffer,
    )));

    $storage = $this->entityTypeManager->getStorage('product_price');
    $price_ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition($field, $parent_ids, 'IN')
      ->sort('id')
      ->execute();

    $prices = $storage->loadMultiple($price_ids);
    $this->preloadReferences($prices);

    // Group the loaded prices by the parent they belong to.
    $by_parent = [];
    foreach ($prices as $price) {
      /** @var \Drupal\Core\Entity\FieldableEntityInterface $price */
      $by_parent[$price->get($field)->target_id][] = $price;
    }

    return array_map(
      static fn (\ArrayObject $item): array => $by_parent[$item['parent_id']] ?? [],
      $buffer,
    );
  }

  /**
   * Loads the products and customers of the prices in one go.
   *
   * The price access check looks at both, which would otherwise load them one
   * by one.
   *
   * @param array<int|string, \Drupal\Core\Entity\EntityInterface> $prices
   *   The loaded product prices.
   */
  protected function preloadReferences(array $prices): void {
    foreach (['product_id' => 'product', 'customer' => 'customer'] as $field => $type) {
      $ids = [];
      foreach ($prices as $price) {
        /** @var \Drupal\Core\Entity\FieldableEntityInterface $price */
        $ids[] = $price->get($field)->target_id;
      }
      $ids = array_values(array_unique(array_filter($ids)));
      if ($ids !== []) {
        $this->entityTypeManager->getStorage($type)->loadMultiple($ids);
      }
    }
  }

}
