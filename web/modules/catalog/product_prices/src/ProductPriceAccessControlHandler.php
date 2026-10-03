<?php

declare(strict_types=1);

namespace Drupal\product_prices;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Defines the access control handler for the product price entity type.
 *
 * A price may be viewed by anyone who may view both its product and its
 * customer. All other operations require the administer permission.
 */
final class ProductPriceAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    $admin_permission = $this->entityType->getAdminPermission();
    if ($admin_permission !== FALSE && $account->hasPermission($admin_permission)) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    if ($operation !== 'view' || !$entity instanceof FieldableEntityInterface) {
      return AccessResult::neutral()->cachePerPermissions();
    }

    $product = $entity->get('product_id')->entity;
    $customer = $entity->get('customer')->entity;
    // A price without both references is only visible to administrators.
    if (!$product instanceof EntityInterface || !$customer instanceof EntityInterface) {
      return AccessResult::neutral()->addCacheableDependency($entity);
    }

    $result = $product->access('view', $account, TRUE)
      ->andIf($customer->access('view', $account, TRUE));
    if ($result instanceof RefinableCacheableDependencyInterface) {
      $result->addCacheableDependency($entity);
    }
    return $result;
  }

  /**
   * {@inheritdoc}
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check.
   * @param array<string, mixed> $context
   *   The creation context.
   * @param string|null $entity_bundle
   *   The bundle, unused: the entity type has no bundles.
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResultInterface {
    return AccessResult::allowedIfHasPermission($account, 'administer product_price');
  }

}
