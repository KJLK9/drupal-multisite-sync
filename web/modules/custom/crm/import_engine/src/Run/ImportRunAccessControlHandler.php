<?php

declare(strict_types=1);

namespace Drupal\import_engine\Run;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access to runs: viewing needs "view import runs", the rest the admin right.
 */
final class ImportRunAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    if ($account->hasPermission('administer import runs')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    if ($operation === 'view') {
      return AccessResult::allowedIfHasPermission($account, 'view import runs');
    }
    return AccessResult::neutral()->cachePerPermissions();
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
    return AccessResult::allowedIfHasPermission($account, 'administer import runs');
  }

}
