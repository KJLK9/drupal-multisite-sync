<?php

declare(strict_types=1);

namespace Drupal\import_engine_ui\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Url;
use Drupal\import_engine\ImportRunSetInterface;

/**
 * Lists the run sets.
 */
final class RunSetController extends ControllerBase {

  use AutowireTrait;

  /**
   * Lists the sets with their imports in order.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function list(): array {
    $storage = $this->entityTypeManager()->getStorage('import_run_set');
    $rows = [];
    foreach ($storage->loadMultiple() as $set) {
      if (!$set instanceof ImportRunSetInterface) {
        continue;
      }
      $id = (string) $set->id();
      $links = [
        'run' => [
          'title' => $this->t('Run now'),
          'url' => Url::fromRoute('import_engine_ui.run_set_run', ['import_run_set' => $id]),
        ],
        'edit' => [
          'title' => $this->t('Edit'),
          'url' => Url::fromRoute('import_engine_ui.run_set_edit', ['import_run_set' => $id]),
        ],
        'delete' => [
          'title' => $this->t('Delete'),
          'url' => Url::fromRoute('import_engine_ui.run_set_delete', ['import_run_set' => $id]),
        ],
      ];
      $rows[] = [
        $set->label(),
        $id,
        implode(' → ', $set->getImports()),
        $set->stopsOnErrors() ? $this->t('Failures and items that went wrong') : $this->t('Failures'),
        ['data' => ['#type' => 'operations', '#links' => $links]],
      ];
    }
    return [
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Name'),
          $this->t('ID'),
          $this->t('Imports, in order'),
          $this->t('Stops at'),
          $this->t('Operations'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('There are no run sets yet.'),
      ],
      '#cache' => ['tags' => $storage->getEntityType()->getListCacheTags()],
    ];
  }

}
