<?php

declare(strict_types=1);

namespace Drupal\import_audit\Routing;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Makes the route of the history page for every entity type with a page.
 *
 * The page is only there for an entity that an import has written (see
 * HistoryAccessCheck), so the other types are not affected.
 */
final class HistoryRoutes implements ContainerInjectionInterface {

  use AutowireTrait;

  /**
   * Constructs the route provider.
   */
  public function __construct(
    #[Autowire(service: 'entity_type.manager')]
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  /**
   * Whether an entity type gets a history page (and so a tab and links).
   *
   * Content entities with a page of their own, whose path holds the entity
   * under the name of its type; the routes, the tabs and the links all go by
   * this one test.
   */
  public static function supports(EntityTypeInterface $type): bool {
    return $type instanceof ContentEntityTypeInterface
      && $type->hasLinkTemplate('canonical')
      && str_contains($type->getLinkTemplate('canonical'), '{' . $type->id() . '}');
  }

  /**
   * Returns the routes.
   */
  public function routes(): RouteCollection {
    $routes = new RouteCollection();
    foreach ($this->entityTypeManager->getDefinitions() as $id => $type) {
      if (!self::supports($type)) {
        continue;
      }
      $canonical = (string) $type->getLinkTemplate('canonical');
      $routes->add('import_audit.history.' . $id, new Route(
        rtrim($canonical, '/') . '/import-history',
        [
          '_controller' => 'Drupal\import_audit\Controller\HistoryController::view',
          '_title' => 'Import history',
          'entity_type_id' => $id,
        ],
        [
          '_permission' => 'view import history',
          '_entity_access' => $id . '.view',
          '_import_audit_history' => 'TRUE',
        ],
        ['parameters' => [$id => ['type' => 'entity:' . $id]]],
      ));
    }
    return $routes;
  }

}
