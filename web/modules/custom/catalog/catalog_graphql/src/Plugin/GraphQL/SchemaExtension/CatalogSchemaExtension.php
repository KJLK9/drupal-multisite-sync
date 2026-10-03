<?php

declare(strict_types=1);

namespace Drupal\catalog_graphql\Plugin\GraphQL\SchemaExtension;

use Drupal\catalog_graphql\GraphQL\PaginationTrait;
use Drupal\graphql\Attribute\SchemaExtension;
use Drupal\graphql\GraphQL\ResolverBuilder;
use Drupal\graphql\GraphQL\ResolverRegistryInterface;
use Drupal\graphql\Plugin\GraphQL\SchemaExtension\SdlSchemaExtensionPluginBase;

/**
 * Exposes the catalog entities (customer, product, product_price).
 */
#[SchemaExtension(
  id: 'catalog',
  name: 'Catalog',
  description: 'Read-only access to customers, products and product prices.',
  schema: 'composable',
)]
class CatalogSchemaExtension extends SdlSchemaExtensionPluginBase {

  use PaginationTrait;

  /**
   * The entity type ID behind each GraphQL object type.
   */
  protected const ENTITY_TYPES = [
    'Customer' => 'customer',
    'Product' => 'product',
    'ProductPrice' => 'product_price',
  ];

  /**
   * {@inheritdoc}
   */
  public function registerResolvers(ResolverRegistryInterface $registry): void {
    $builder = new ResolverBuilder();

    $this->addQueryFields($registry, $builder, 'customer', 'customers', 'Customer');
    $this->addQueryFields($registry, $builder, 'product', 'products', 'Product');
    $this->addQueryFields($registry, $builder, 'product_price', 'productPrices', 'ProductPrice', 'productPrice');

    foreach (['Customer', 'Product', 'ProductPrice'] as $type) {
      $registry->addFieldResolver($type, 'id', $builder->produce('entity_id')
        ->map('entity', $builder->fromParent()));
      $registry->addFieldResolver($type, 'uuid', $builder->produce('entity_uuid')
        ->map('entity', $builder->fromParent()));
    }

    foreach (['Customer', 'Product'] as $type) {
      $this->addValueField($registry, $builder, $type, 'label');
      $this->addValueField($registry, $builder, $type, 'status');
      $this->addValueField($registry, $builder, $type, 'description');
    }
    $this->addValueField($registry, $builder, 'Customer', 'customer_number', 'customerNumber');

    $this->addMoneyField($registry, $builder, 'Product', 'basePrice', 'base_price');
    $this->addMoneyField($registry, $builder, 'ProductPrice', 'price', 'price');

    $this->addReferenceField($registry, $builder, 'ProductPrice', 'product', 'product_id', 'product');
    $this->addReferenceField($registry, $builder, 'ProductPrice', 'customer', 'customer', 'customer');

    // Prices are reachable from both sides of the relation.
    $this->addPricesField($registry, $builder, 'Product', 'product_id');
    $this->addPricesField($registry, $builder, 'Customer', 'customer');
  }

  /**
   * Resolves the prices referencing a product or customer, batched.
   */
  protected function addPricesField(ResolverRegistryInterface $registry, ResolverBuilder $builder, string $type, string $price_field): void {
    $registry->addFieldResolver($type, 'prices', $builder->produce('catalog_prices')
      ->map('entity', $builder->fromParent())
      ->map('field', $builder->fromValue($price_field))
      ->map('limit', $this->limitResolver($builder)));
  }

  /**
   * Adds the single and list query fields for an entity type.
   */
  protected function addQueryFields(ResolverRegistryInterface $registry, ResolverBuilder $builder, string $entity_type, string $list_field, string $type, ?string $single_field = NULL): void {
    $registry->addFieldResolver('Query', $single_field ?? $entity_type, $builder->produce('entity_load')
      ->map('type', $builder->fromValue($entity_type))
      ->map('id', $builder->fromArgument('id')));

    $registry->addFieldResolver('Query', $list_field, $builder->compose(
      $builder->produce('entity_query')
        ->map('type', $builder->fromValue($entity_type))
        ->map('limit', $this->limitResolver($builder))
        ->map('offset', $this->offsetResolver($builder))
        // Offset paging needs a deterministic order.
        ->map('sorts', $builder->fromValue([['field' => 'id', 'direction' => 'ASC']])),
      $builder->produce('entity_load_multiple')
        ->map('type', $builder->fromValue($entity_type))
        ->map('ids', $builder->fromParent()),
    ));
  }

  /**
   * Maps a simple field to the "value" property of the entity field.
   */
  protected function addValueField(ResolverRegistryInterface $registry, ResolverBuilder $builder, string $type, string $field, ?string $graphql_field = NULL): void {
    $this->addPathField($registry, $builder, $type, $graphql_field ?? $field, $field . '.value', 'entity:' . self::ENTITY_TYPES[$type]);
  }

  /**
   * Resolves a field from a typed data property path on the parent value.
   */
  protected function addPathField(ResolverRegistryInterface $registry, ResolverBuilder $builder, string $type, string $field, string $path, ?string $data_type = NULL): void {
    $registry->addFieldResolver($type, $field, $builder->produce('property_path')
      ->map('type', $builder->fromValue($data_type))
      ->map('value', $builder->fromParent())
      ->map('path', $builder->fromValue($path)));
  }

  /**
   * Resolves a money_field to a Money value (number and currency code).
   */
  protected function addMoneyField(ResolverRegistryInterface $registry, ResolverBuilder $builder, string $type, string $field, string $entity_field): void {
    $registry->addFieldResolver($type, $field, $builder->produce('catalog_money')
      ->map('entity', $builder->fromParent())
      ->map('field', $builder->fromValue($entity_field)));
  }

  /**
   * Loads the entity a single-value reference field points at.
   *
   * Goes through entity_load so entity access is checked.
   */
  protected function addReferenceField(ResolverRegistryInterface $registry, ResolverBuilder $builder, string $type, string $field, string $entity_field, string $target_type): void {
    $registry->addFieldResolver($type, $field, $builder->compose(
      $builder->produce('property_path')
        ->map('type', $builder->fromValue('entity:' . self::ENTITY_TYPES[$type]))
        ->map('value', $builder->fromParent())
        ->map('path', $builder->fromValue($entity_field . '.target_id')),
      $builder->produce('entity_load')
        ->map('type', $builder->fromValue($target_type))
        ->map('id', $builder->fromParent()),
    ));
  }

}
