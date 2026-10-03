<?php

declare(strict_types=1);

namespace Drupal\product_prices\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\Element;

/**
 * Hook implementations for the product price entity type.
 */
final class ProductPriceHooks {

  /**
   * Constructs a ProductPriceHooks object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
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

}
