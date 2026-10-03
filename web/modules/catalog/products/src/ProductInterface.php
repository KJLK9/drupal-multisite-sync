<?php

declare(strict_types=1);

namespace Drupal\products;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Provides an interface defining a products entity type.
 */
interface ProductInterface extends ContentEntityInterface, EntityOwnerInterface, EntityChangedInterface {

}
