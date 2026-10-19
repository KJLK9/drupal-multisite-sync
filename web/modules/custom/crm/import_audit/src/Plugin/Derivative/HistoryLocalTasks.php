<?php

declare(strict_types=1);

namespace Drupal\import_audit\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\import_audit\Routing\HistoryRoutes;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A tab "Import history" next to the view and edit tabs of the entities.
 */
final class HistoryLocalTasks extends DeriverBase implements ContainerDeriverInterface {

  /**
   * Constructs the deriver.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id): static {
    return new static($container->get('entity_type.manager'));
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $base_plugin_definition
   *   The definition of the plugin the derivatives come from.
   *
   * @return array<string, array<string, mixed>>
   *   The tabs.
   */
  public function getDerivativeDefinitions($base_plugin_definition): array {
    foreach ($this->entityTypeManager->getDefinitions() as $id => $type) {
      if (!HistoryRoutes::supports($type)) {
        continue;
      }
      $this->derivatives[$id] = [
        'title' => 'Import history',
        'route_name' => 'import_audit.history.' . $id,
        'base_route' => 'entity.' . $id . '.canonical',
        'weight' => 90,
      ] + $base_plugin_definition;
    }
    return $this->derivatives;
  }

}
