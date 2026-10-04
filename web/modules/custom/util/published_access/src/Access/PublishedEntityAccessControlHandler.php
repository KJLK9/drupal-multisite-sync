<?php

declare(strict_types=1);

namespace Drupal\published_access\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access handler for entity types with simple "<operation> <type>" permissions.
 *
 * Use it as the 'access' handler of a content entity type. Permissions are
 * named after the entity type: "view product", "edit product",
 * "delete product" and "create product", and the entity type's admin
 * permission grants everything. Unpublished entities are only visible to
 * administrators, in entity access checks and, through
 * \Drupal\published_access\Hook\QueryAccessHooks, in entity queries with
 * access checking enabled.
 */
class PublishedEntityAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    $admin_permission = $this->entityType->getAdminPermission();
    if ($admin_permission !== FALSE && $account->hasPermission($admin_permission)) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    $type = $this->entityTypeId;
    return match ($operation) {
      'view' => $this->checkViewAccess($entity, $account),
      'update' => AccessResult::allowedIfHasPermission($account, "edit $type"),
      'delete' => AccessResult::allowedIfHasPermission($account, "delete $type"),
      default => AccessResult::neutral()->cachePerPermissions(),
    };
  }

  /**
   * Checks view access: published entities need the view permission.
   */
  protected function checkViewAccess(EntityInterface $entity, AccountInterface $account): AccessResultInterface {
    if ($entity instanceof EntityPublishedInterface && !$entity->isPublished()) {
      return AccessResult::neutral('Unpublished.')
        ->addCacheableDependency($entity)
        ->cachePerPermissions();
    }
    return AccessResult::allowedIfHasPermission($account, 'view ' . $this->entityTypeId)
      ->addCacheableDependency($entity);
  }

  /**
   * {@inheritdoc}
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check.
   * @param array<string, mixed> $context
   *   The creation context.
   * @param string|null $entity_bundle
   *   The bundle, unused: the entity types have no bundles.
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResultInterface {
    $permissions = array_filter([
      'create ' . $this->entityTypeId,
      $this->entityType->getAdminPermission(),
    ]);
    return AccessResult::allowedIfHasPermissions($account, $permissions, 'OR');
  }

}
