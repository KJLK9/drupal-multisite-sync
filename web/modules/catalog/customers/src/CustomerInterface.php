<?php

declare(strict_types=1);

namespace Drupal\customers;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Provides an interface defining a customer entity type.
 */
interface CustomerInterface extends ContentEntityInterface, EntityOwnerInterface, EntityChangedInterface {

}
