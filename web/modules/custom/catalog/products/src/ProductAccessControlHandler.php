<?php

declare(strict_types=1);

namespace Drupal\products;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Defines the access control handler for the products entity type.
 *
 * phpcs:disable Drupal.Arrays.Array.LongLineDeclaration
 *
 * @see https://www.drupal.org/project/coder/issues/3185082
 */
final class ProductAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResult {
    $admin_permission = $this->entityType->getAdminPermission();
    if ($admin_permission !== FALSE && $account->hasPermission($admin_permission)) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    return match($operation) {
      'view' => AccessResult::allowedIfHasPermission($account, 'view product'),
      'update' => AccessResult::allowedIfHasPermission($account, 'edit product'),
      'delete' => AccessResult::allowedIfHasPermission($account, 'delete product'),
      default => AccessResult::neutral(),
    };
  }

  /**
   * {@inheritdoc}
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   * @param array<string, mixed> $context
   *   The access context.
   * @param mixed $entity_bundle
   *   The entity bundle.
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResult {
    return AccessResult::allowedIfHasPermissions($account, ['create product', 'administer product'], 'OR');
  }

}
