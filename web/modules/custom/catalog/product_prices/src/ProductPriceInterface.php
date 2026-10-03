<?php

declare(strict_types=1);

namespace Drupal\product_prices;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;

/**
 * Provides an interface defining a product prices entity type.
 */
interface ProductPriceInterface extends ContentEntityInterface, EntityChangedInterface {

}
