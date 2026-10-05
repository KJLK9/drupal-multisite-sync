<?php

declare(strict_types=1);

namespace Drupal\site_b_catalog;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists the entities of a catalog type: label, status and last change.
 */
final class CatalogListBuilder extends EntityListBuilder {

  /**
   * Constructs the list builder.
   */
  public function __construct(
    EntityTypeInterface $entity_type,
    EntityStorageInterface $storage,
    protected DateFormatterInterface $dateFormatter,
  ) {
    parent::__construct($entity_type, $storage);
  }

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type.
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    return new static(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The table header.
   */
  public function buildHeader(): array {
    $header['label'] = $this->t('Name');
    $header['source_id'] = $this->t('Source ID');
    $header['status'] = $this->t('Status');
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
    $row['label'] = $entity->toLink();
    $fieldable = $entity instanceof FieldableEntityInterface ? $entity : NULL;
    $row['source_id'] = $fieldable?->hasField('source_id') ? (string) $fieldable->get('source_id')->value : '';
    $row['status'] = $entity instanceof EntityPublishedInterface && !$entity->isPublished() ? $this->t('unpublished') : $this->t('published');
    $changed = $fieldable?->hasField('changed') ? (int) $fieldable->get('changed')->value : 0;
    $row['changed'] = $changed > 0 ? $this->dateFormatter->format($changed, 'short') : '';
    return $row + parent::buildRow($entity);
  }

}
