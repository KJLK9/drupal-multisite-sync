<?php

declare(strict_types=1);

namespace Drupal\catalog_jsonapi\EventSubscriber;

use Drupal\jsonapi\ResourceType\ResourceTypeBuildEvent;
use Drupal\jsonapi\ResourceType\ResourceTypeBuildEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Removes the author (`uid`) from the catalog resources.
 *
 * The author is an internal detail: exposing it would leak the UUIDs of user
 * accounts to API consumers that have nothing to do with them.
 */
final class HideAuthorSubscriber implements EventSubscriberInterface {

  /**
   * The resource types whose author is hidden.
   */
  public const RESOURCE_TYPES = [
    'customer--customer',
    'product--product',
  ];

  /**
   * {@inheritdoc}
   *
   * @return array<string, string>
   *   The subscribed events.
   */
  public static function getSubscribedEvents(): array {
    return [ResourceTypeBuildEvents::BUILD => 'onBuild'];
  }

  /**
   * Disables the `uid` field on the catalog resource types.
   *
   * @param \Drupal\jsonapi\ResourceType\ResourceTypeBuildEvent $event
   *   The resource type build event.
   */
  public function onBuild(ResourceTypeBuildEvent $event): void {
    if (!in_array($event->getResourceTypeName(), self::RESOURCE_TYPES, TRUE)) {
      return;
    }
    foreach ($event->getFields() as $field) {
      if ($field->getInternalName() === 'uid') {
        $event->disableField($field);
      }
    }
  }

}
