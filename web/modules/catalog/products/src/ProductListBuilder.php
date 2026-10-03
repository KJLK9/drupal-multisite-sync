<?php

declare(strict_types=1);

namespace Drupal\products;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;

/**
 * Provides a list controller for the products entity type.
 */
final class ProductListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The table header.
   */
  public function buildHeader(): array {
    $header['id'] = $this->t('ID');
    $header['label'] = $this->t('Label');
    $header['status'] = $this->t('Status');
    $header['uid'] = $this->t('Author');
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
    /** @var \Drupal\products\ProductInterface $entity */
    $row['id'] = $entity->id();
    $row['label'] = $entity->toLink();
    $row['status'] = $entity->get('status')->value ? $this->t('Enabled') : $this->t('Disabled');
    /** @var \Drupal\user\UserInterface|null $owner */
    $owner = $entity->getOwner();
    $username_options = [
      'label' => 'hidden',
      'settings' => ['link' => $owner?->isAuthenticated() ?? FALSE],
    ];
    $row['uid']['data'] = $entity->get('uid')->view($username_options);
    $row['created']['data'] = $entity->get('created')->view(['label' => 'hidden']);
    $row['changed']['data'] = $entity->get('changed')->view(['label' => 'hidden']);
    return $row + parent::buildRow($entity);
  }

}
