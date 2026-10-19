<?php

declare(strict_types=1);

namespace Drupal\import_audit\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\import_audit\History\EntityHistory;

/**
 * Allows the history page only for an entity that an import has written.
 *
 * This is what hides the tab on every other entity. Permission and access to
 * the entity itself are checked by the other requirements of the route.
 */
final class HistoryAccessCheck implements AccessInterface {

  /**
   * How long, in seconds, the answer may be cached.
   */
  public const MAX_AGE = 60;

  /**
   * Constructs the check.
   */
  public function __construct(
    private readonly EntityHistory $history,
  ) {
  }

  /**
   * Checks access.
   */
  public function access(RouteMatchInterface $route_match): AccessResultInterface {
    $type = (string) $route_match->getRouteObject()?->getDefault('entity_type_id');
    $entity = $route_match->getParameter($type);
    if (!$entity instanceof EntityInterface || $entity->id() === NULL) {
      return AccessResult::neutral();
    }
    // The mapping changes with every run and the engine has no cache tag for
    // it (it must not know of this module): a minute is long enough to spare
    // the pages a query each time, and short enough for a new import to show.
    return AccessResult::allowedIf($this->history->isImported($type, (string) $entity->id()))
      ->setCacheMaxAge(self::MAX_AGE)
      ->cachePerPermissions();
  }

}
