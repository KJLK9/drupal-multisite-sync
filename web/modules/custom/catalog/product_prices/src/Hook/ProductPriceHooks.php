<?php

declare(strict_types=1);

namespace Drupal\product_prices\Hook;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\AlterableInterface;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\Element;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Hook implementations for the product price entity type.
 */
final class ProductPriceHooks {

  /**
   * Constructs a ProductPriceHooks object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly Connection $database,
  ) {
  }

  /**
   * Implements hook_theme().
   *
   * @return array<string, array<string, string>>
   *   The theme hook definitions.
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'product_price' => ['render element' => 'elements'],
    ];
  }

  /**
   * Prepares variables for product price templates.
   *
   * Default template: product-price.html.twig.
   *
   * @param array<string, mixed> $variables
   *   An associative array containing:
   *   - elements: An associative array containing the product price
   *     information and any fields attached to the entity.
   *   - attributes: HTML attributes for the containing element.
   */
  #[Hook('preprocess_product_price')]
  public function preprocessProductPrice(array &$variables): void {
    $variables['view_mode'] = $variables['elements']['#view_mode'];
    foreach (Element::children($variables['elements']) as $key) {
      $variables['content'][$key] = $variables['elements'][$key];
    }
  }

  /**
   * Implements hook_ENTITY_TYPE_delete() for products.
   *
   * A price is meaningless without its product.
   */
  #[Hook('product_delete')]
  public function productDelete(EntityInterface $product): void {
    $this->deletePrices('product_id', $product);
  }

  /**
   * Implements hook_ENTITY_TYPE_delete() for customers.
   *
   * A price is meaningless without its customer.
   */
  #[Hook('customer_delete')]
  public function customerDelete(EntityInterface $customer): void {
    $this->deletePrices('customer', $customer);
  }

  /**
   * Deletes the prices that reference the given entity.
   *
   * @param string $field
   *   The price field holding the reference.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity that is being deleted.
   */
  private function deletePrices(string $field, EntityInterface $entity): void {
    $storage = $this->entityTypeManager->getStorage('product_price');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition($field, $entity->id())
      ->execute();
    if ($ids !== []) {
      $storage->delete($storage->loadMultiple($ids));
    }
  }

  /**
   * Implements hook_query_alter().
   *
   * Makes access-checked entity queries for prices follow ProductPrice
   * access: a price is only returned when the user may view both its
   * product and its customer, so lists, counts and paging agree with entity
   * access. Administrators of the price type are not filtered.
   */
  #[Hook('query_alter')]
  public function queryAlter(AlterableInterface $query): void {
    if (!$query instanceof SelectInterface || $query->getMetaData('entity_type') !== 'product_price'
      || !$query->hasTag('product_price_access')) {
      return;
    }
    $admin_permission = $this->entityTypeManager->getDefinition('product_price')->getAdminPermission();
    if ($admin_permission !== FALSE && $this->currentUser->hasPermission($admin_permission)) {
      return;
    }

    foreach (['product_id' => 'product', 'customer' => 'customer'] as $field => $type) {
      $referenced = $this->entityTypeManager->getDefinition($type);
      $admin = $referenced->getAdminPermission();
      if ($admin !== FALSE && $this->currentUser->hasPermission($admin)) {
        // May view every product or customer, unpublished included.
        continue;
      }
      if (!$this->currentUser->hasPermission('view ' . $type)) {
        $query->where('1 = 0');
        return;
      }
      $published_key = $referenced->getKey('published');
      $table = $referenced->getBaseTable();
      if ($published_key === FALSE || !is_string($table)) {
        continue;
      }
      $visible = $this->database->select($table, 'visible')
        ->fields('visible', [$referenced->getKey('id')])
        ->condition('visible.' . $published_key, 1);
      $query->condition('base_table.' . $field, $visible, 'IN');
    }
  }

}
