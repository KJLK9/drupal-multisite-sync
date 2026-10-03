<?php

declare(strict_types=1);

namespace Drupal\product_prices\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\Element;

/**
 * Hook implementations for the product price entity type.
 */
final class ProductPriceHooks {

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

}
