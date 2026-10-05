<?php

declare(strict_types=1);

namespace Drupal\import_engine_ui\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Url;
use Drupal\import_engine\ImportConnectionInterface;
use Drupal\import_engine\Storage\ImportConnectionStorage;

/**
 * Lists the connections.
 */
final class ConnectionController extends ControllerBase {

  use AutowireTrait;

  /**
   * Lists the connections with where they go and who uses them.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function list(): array {
    $storage = $this->entityTypeManager()->getStorage('import_connection');
    $rows = [];
    foreach ($storage->loadMultiple() as $connection) {
      if (!$connection instanceof ImportConnectionInterface || !$storage instanceof ImportConnectionStorage) {
        continue;
      }
      $id = (string) $connection->id();
      $used = $storage->usedBy($id);
      $source = $connection->getSource();
      $links = [
        'edit' => [
          'title' => $this->t('Edit'),
          'url' => Url::fromRoute('import_engine_ui.connection_edit', ['import_connection' => $id]),
        ],
      ];
      if ($used === []) {
        $links['delete'] = [
          'title' => $this->t('Delete'),
          'url' => Url::fromRoute('import_engine_ui.connection_delete', ['import_connection' => $id]),
        ];
      }
      $rows[] = [
        $connection->label(),
        $id,
        $source['plugin'],
        (string) ($source['configuration']['url'] ?? ''),
        $connection->getAuthentication()['plugin'],
        $used === [] ? $this->t('Nothing') : implode(', ', $used),
        ['data' => ['#type' => 'operations', '#links' => $links]],
      ];
    }
    return [
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Name'),
          $this->t('ID'),
          $this->t('Source'),
          $this->t('URL'),
          $this->t('Authentication'),
          $this->t('Used by'),
          $this->t('Operations'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('There are no connections yet.'),
      ],
      '#cache' => [
        'tags' => [
          ...$storage->getEntityType()->getListCacheTags(),
          ...$this->entityTypeManager()->getDefinition('import_definition')->getListCacheTags(),
        ],
      ],
    ];
  }

}
