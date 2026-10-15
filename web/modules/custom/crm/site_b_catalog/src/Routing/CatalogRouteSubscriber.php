<?php

declare(strict_types=1);

namespace Drupal\site_b_catalog\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Lets a person who may only look see the lists of the catalog.
 *
 * The entity types make their lists administration: only those who administer
 * the type may open them. To show the catalog to someone (a demonstration, an
 * auditor) without the rights to change it, the permission "access catalog
 * lists" opens the lists as well. What is in a list is still decided per
 * entity, as before: unpublished entities are for administrators, and the
 * links to change or delete only appear for those who may.
 */
final class CatalogRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    foreach (['account', 'item', 'agreement'] as $type) {
      $route = $collection->get("entity.$type.collection");
      if ($route !== NULL) {
        $route->setRequirement('_permission', "administer $type+access catalog lists");
      }
    }
  }

}
