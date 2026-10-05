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
 * An account: a customer of the catalog, as site B keeps it.
 */
#[ContentEntityType(
  id: 'account',
  label: new TranslatableMarkup('Account'),
  label_collection: new TranslatableMarkup('Accounts'),
  label_singular: new TranslatableMarkup('account'),
  label_plural: new TranslatableMarkup('accounts'),
  entity_keys: [
    'id' => 'id',
    'label' => 'name',
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
    'collection' => '/admin/site-b/accounts',
    'add-form' => '/admin/site-b/accounts/add',
    'canonical' => '/admin/site-b/accounts/{account}',
    'edit-form' => '/admin/site-b/accounts/{account}/edit',
    'delete-form' => '/admin/site-b/accounts/{account}/delete',
  ],
  admin_permission: 'administer account',
  base_table: 'account',
  label_count: [
    'singular' => '@count account',
    'plural' => '@count accounts',
  ],
)]
class Account extends ContentEntityBase implements EntityChangedInterface, EntityPublishedInterface {

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
    $fields['name'] = Fields::text(new TranslatableMarkup('Name'), 0, TRUE);
    $fields['number'] = Fields::text(new TranslatableMarkup('Account number'), 1, TRUE, 64)
      ->setDescription(new TranslatableMarkup('The customer number at site A: what identifies the account.'))
      ->addConstraint('UniqueField');
    $fields['notes'] = Fields::longText(new TranslatableMarkup('Notes'), 2);
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
