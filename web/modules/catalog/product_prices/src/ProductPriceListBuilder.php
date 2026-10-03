<?php

declare(strict_types=1);

namespace Drupal\product_prices;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;

/**
 * Provides a list controller for the product prices entity type.
 */
final class ProductPriceListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The table header.
   */
  public function buildHeader(): array {
    $header['id'] = $this->t('ID');
    $header['created'] = $this->t('Created');
    $header['changed'] = $this->t('Updated');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return array<string, mixed>
   *   The table row.
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\product_prices\ProductPriceInterface $entity */
    $row['id'] = $entity->toLink();
    $row['created']['data'] = $entity->get('created')->view(['label' => 'hidden']);
    $row['changed']['data'] = $entity->get('changed')->view(['label' => 'hidden']);
    return $row + parent::buildRow($entity);
  }

}
