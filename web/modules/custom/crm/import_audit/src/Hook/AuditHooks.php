<?php

declare(strict_types=1);

namespace Drupal\import_audit\Hook;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\import_audit\Routing\HistoryRoutes;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Turns the target of an event on the run pages into a link to the entity.
 */
final class AuditHooks {

  use StringTranslationTrait;

  /**
   * Constructs the hooks.
   */
  public function __construct(
    #[Autowire(service: 'entity_type.manager')]
    private readonly EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'current_user')]
    private readonly AccountInterface $currentUser,
  ) {
  }

  /**
   * Implements hook_import_engine_ui_event_targets_alter().
   *
   * @param array<int, mixed> $targets
   *   The cells of the Target column, by event ID: text such as "account:30".
   * @param list<\Drupal\import_engine\Storage\EventRecord> $events
   *   The events of the page.
   */
  #[Hook('import_engine_ui_event_targets_alter')]
  public function eventTargets(array &$targets, array $events): void {
    $ids = [];
    foreach ($events as $event) {
      $parts = explode(':', (string) $event->target, 2);
      if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '' && $this->entityTypeManager->hasDefinition($parts[0])
        && HistoryRoutes::supports($this->entityTypeManager->getDefinition($parts[0]))) {
        $ids[$parts[0]][$parts[1]] = $parts[1];
      }
    }
    $entities = [];
    foreach ($ids as $type => $type_ids) {
      $entities[$type] = $this->entityTypeManager->getStorage($type)->loadMultiple($type_ids);
    }
    foreach ($events as $event) {
      [$type, $id] = array_pad(explode(':', (string) $event->target, 2), 2, '');
      if (!isset($ids[$type][$id])) {
        continue;
      }
      $entity = $entities[$type][$id] ?? NULL;
      if ($entity === NULL) {
        $targets[$event->id] = (string) $this->t('@target (no longer exists)', ['@target' => (string) $event->target]);
        continue;
      }
      if (!$entity->access('view', $this->currentUser) || !$entity->hasLinkTemplate('canonical')) {
        continue;
      }
      $cell = [Link::fromTextAndUrl((string) $entity->label(), $entity->toUrl())->toRenderable()];
      if ($this->currentUser->hasPermission('view import history')) {
        $cell[] = ['#markup' => ' · '];
        $cell[] = Link::fromTextAndUrl($this->t('history'), Url::fromRoute('import_audit.history.' . $type, [$type => $id]))->toRenderable();
      }
      $targets[$event->id] = $cell;
    }
  }

}
