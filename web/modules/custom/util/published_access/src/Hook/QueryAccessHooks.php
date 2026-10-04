<?php

declare(strict_types=1);

namespace Drupal\published_access\Hook;

use Drupal\Core\Database\Query\AlterableInterface;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\published_access\Access\PublishedEntityAccessControlHandler;

/**
 * Excludes unpublished entities from access-checked entity queries.
 *
 * Core only does this for entity types with a query access handler (nodes,
 * media). This makes it work for every entity type that uses
 * PublishedEntityAccessControlHandler, so lists, counts and paging agree with
 * entity access.
 */
final class QueryAccessHooks {

  /**
   * Constructs a QueryAccessHooks object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
  ) {
  }

  /**
   * Implements hook_query_alter().
   */
  #[Hook('query_alter')]
  public function queryAlter(AlterableInterface $query): void {
    $type_id = $query->getMetaData('entity_type');
    // Entity queries add this tag only when access checking is enabled.
    if (!$query instanceof SelectInterface || !is_string($type_id) || !$query->hasTag($type_id . '_access')) {
      return;
    }

    $entity_type = $this->entityTypeManager->getDefinition($type_id, FALSE);
    if ($entity_type === NULL) {
      return;
    }
    $published_key = $entity_type->getKey('published');
    $access_handler = $entity_type->getHandlerClass('access');
    if ($published_key === FALSE || !is_string($access_handler)
      || !is_a($access_handler, PublishedEntityAccessControlHandler::class, TRUE)) {
      return;
    }

    $admin_permission = $entity_type->getAdminPermission();
    if ($admin_permission !== FALSE && $this->currentUser->hasPermission($admin_permission)) {
      return;
    }
    $query->condition('base_table.' . $published_key, 1);
  }

}
