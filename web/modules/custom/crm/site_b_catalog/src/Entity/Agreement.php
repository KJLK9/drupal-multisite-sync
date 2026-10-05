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
use Drupal\Core\Entity\EntityStorageInterface;
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
 * An agreement: the price agreed with one account for one item.
 */
#[ContentEntityType(
  id: 'agreement',
  label: new TranslatableMarkup('Agreement'),
  label_collection: new TranslatableMarkup('Agreements'),
  label_singular: new TranslatableMarkup('agreement'),
  label_plural: new TranslatableMarkup('agreements'),
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
    'collection' => '/admin/site-b/agreements',
    'add-form' => '/admin/site-b/agreements/add',
    'canonical' => '/admin/site-b/agreements/{agreement}',
    'edit-form' => '/admin/site-b/agreements/{agreement}/edit',
    'delete-form' => '/admin/site-b/agreements/{agreement}/delete',
  ],
  admin_permission: 'administer agreement',
  base_table: 'agreement',
  label_count: [
    'singular' => '@count agreement',
    'plural' => '@count agreements',
  ],
  constraints: [
    'UniqueAgreement' => [],
  ],
)]
class Agreement extends ContentEntityBase implements EntityChangedInterface, EntityPublishedInterface {

  use EntityChangedTrait;
  use EntityPublishedTrait;

  /**
   * {@inheritdoc}
   *
   * An agreement without a title is called after what it agrees: the item for
   * the account.
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    if ((string) $this->get('title')->value === '') {
      $item = $this->get('item')->entity;
      $account = $this->get('account')->entity;
      if ($item !== NULL && $account !== NULL) {
        $this->set('title', $item->label() . ' for ' . $account->label());
      }
    }
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, \Drupal\Core\Field\FieldDefinitionInterface>
   *   The base fields.
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['title'] = Fields::text(new TranslatableMarkup('Title'), 0)
      ->setDescription(new TranslatableMarkup('Left empty, it is made of the item and the account.'));
    $fields['account'] = Fields::reference(new TranslatableMarkup('Account'), 'account', 1);
    $fields['item'] = Fields::reference(new TranslatableMarkup('Item'), 'item', 2);
    $fields['price'] = Fields::money(new TranslatableMarkup('Agreed price'), 3, TRUE);
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
