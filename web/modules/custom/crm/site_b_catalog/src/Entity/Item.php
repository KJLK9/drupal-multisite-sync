<?php

declare(strict_types=1);

namespace Drupal\site_b_catalog\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityPublishedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\published_access\Access\PublishedEntityAccessControlHandler;
use Drupal\site_b_catalog\CatalogListBuilder;
use Drupal\site_b_catalog\CatalogStorageSchema;
use Drupal\site_b_catalog\Fields;
use Drupal\site_b_catalog\Form\CatalogForm;

/**
 * An item: a product of the catalog, as site B keeps it.
 */
#[ContentEntityType(
  id: 'item',
  label: new TranslatableMarkup('Item'),
  label_collection: new TranslatableMarkup('Items'),
  label_singular: new TranslatableMarkup('item'),
  label_plural: new TranslatableMarkup('items'),
  entity_keys: [
    'id' => 'id',
    'label' => 'title',
    'published' => 'status',
    'uuid' => 'uuid',
  ],
  handlers: [
    'list_builder' => CatalogListBuilder::class,
    'access' => PublishedEntityAccessControlHandler::class,
    'storage_schema' => CatalogStorageSchema::class,
    'form' => [
      'add' => CatalogForm::class,
      'edit' => CatalogForm::class,
      'delete' => ContentEntityDeleteForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  links: [
    'collection' => '/admin/site-b/items',
    'add-form' => '/admin/site-b/items/add',
    'canonical' => '/admin/site-b/items/{item}',
    'edit-form' => '/admin/site-b/items/{item}/edit',
    'delete-form' => '/admin/site-b/items/{item}/delete',
  ],
  admin_permission: 'administer item',
  base_table: 'item',
  label_count: [
    'singular' => '@count item',
    'plural' => '@count items',
  ],
)]
class Item extends ContentEntityBase implements EntityChangedInterface, EntityPublishedInterface {

  use EntityChangedTrait;
  use EntityPublishedTrait;

  /**
   * {@inheritdoc}
   *
   * @return array<string, \Drupal\Core\Field\FieldDefinitionInterface>
   *   The base fields.
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['title'] = Fields::text(new TranslatableMarkup('Title'), 0, TRUE);
    $fields['sku'] = Fields::text(new TranslatableMarkup('SKU'), 1, FALSE, 64);
    $fields['list_price'] = Fields::money(new TranslatableMarkup('List price'), 2);
    $fields['summary'] = Fields::longText(new TranslatableMarkup('Summary'), 3);
    $fields += static::publishedBaseFieldDefinitions($entity_type);
    $status = $fields['status'];
    if ($status instanceof BaseFieldDefinition) {
      $status->setDisplayConfigurable('form', TRUE);
    }
    $fields['source_id'] = Fields::text(new TranslatableMarkup('Source ID'), 80, FALSE, 64)
      ->setDescription(new TranslatableMarkup('The ID at site A.'));
    $fields['created'] = Fields::created();
    $fields['changed'] = Fields::changed();
    return $fields;
  }

}
