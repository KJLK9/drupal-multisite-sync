<?php

declare(strict_types=1);

namespace Drupal\product_prices\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Form\DeleteMultipleForm;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\product_prices\Form\ProductPriceForm;
use Drupal\product_prices\ProductPriceAccessControlHandler;
use Drupal\product_prices\ProductPriceInterface;
use Drupal\product_prices\ProductPriceListBuilder;
use Drupal\views\EntityViewsData;

/**
 * Defines the product prices entity class.
 */
#[ContentEntityType(
  id: 'product_price',
  label: new TranslatableMarkup('Product price'),
  label_collection: new TranslatableMarkup('Product prices'),
  label_singular: new TranslatableMarkup('Product price'),
  label_plural: new TranslatableMarkup('product prices'),
  entity_keys: [
    'id' => 'id',
    'label' => 'id',
    'uuid' => 'uuid',
  ],
  handlers: [
    'list_builder' => ProductPriceListBuilder::class,
    'views_data' => EntityViewsData::class,
    'access' => ProductPriceAccessControlHandler::class,
    'form' => [
      'add' => ProductPriceForm::class,
      'edit' => ProductPriceForm::class,
      'delete' => ContentEntityDeleteForm::class,
      'delete-multiple-confirm' => DeleteMultipleForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  links: [
    'collection' => '/admin/content/product-price',
    'add-form' => '/product-price/add',
    'canonical' => '/product-price/{product_price}',
    'edit-form' => '/product-price/{product_price}/edit',
    'delete-form' => '/product-price/{product_price}/delete',
    'delete-multiple-form' => '/admin/content/product-price/delete-multiple',
  ],
  admin_permission: 'administer product_price',
  base_table: 'product_price',
  label_count: [
    'singular' => '@count product price',
    'plural' => '@count product prices',
  ],
  field_ui_base_route: 'entity.product_price.settings',
)]
class ProductPrice extends ContentEntityBase implements ProductPriceInterface {

  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {

    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Authored on'))
      ->setDescription(t('The time that the product prices was created.'))
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'timestamp',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('form', [
        'type' => 'datetime_timestamp',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time that the product prices was last edited.'));

    $fields['product_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Product'))
      ->setDescription(t('The ID of the product.'))
      ->setSetting('target_type', 'product')
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['customer'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Customer'))
      ->setDescription(t('The ID of the customer.'))
      ->setSetting('target_type', 'customer')
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['price'] = BaseFieldDefinition::create('money_field')
      ->setLabel(t('Price'))
      ->setDescription(t('The price of the product.'))
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }

}
