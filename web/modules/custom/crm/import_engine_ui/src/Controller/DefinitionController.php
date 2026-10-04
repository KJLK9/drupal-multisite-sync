<?php

declare(strict_types=1);

namespace Drupal\import_engine_ui\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Url;
use Drupal\import_engine\ImportDefinitionInterface;

/**
 * Lists the import definitions.
 */
final class DefinitionController extends ControllerBase {

  use AutowireTrait;

  /**
   * Lists the imports with what they read and write.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function list(): array {
    $rows = [];
    foreach ($this->entityTypeManager()->getStorage('import_definition')->loadMultiple() as $definition) {
      if (!$definition instanceof ImportDefinitionInterface) {
        continue;
      }
      $target = $definition->getTarget();
      $configuration = (array) ($target['configuration'] ?? []);
      $rows[] = [
        $definition->label(),
        $definition->id(),
        $definition->getSource()['plugin'],
        $target['plugin'] . (isset($configuration['entity_type']) ? ': ' . $configuration['entity_type'] . '/' . ($configuration['bundle'] ?? '') : ''),
        $definition->status() ? $this->t('enabled') : $this->t('disabled'),
        [
          'data' => [
            '#type' => 'operations',
            '#links' => [
              'edit' => [
                'title' => $this->t('Edit'),
                'url' => Url::fromRoute('import_engine_ui.definition_edit', ['import_definition' => $definition->id()]),
              ],
              'run' => [
                'title' => $this->t('Run now'),
                'url' => Url::fromRoute('import_engine_ui.definition_run', ['import_definition' => $definition->id()]),
              ],
              'runs' => [
                'title' => $this->t('Dead letter queue'),
                'url' => Url::fromRoute('import_engine_ui.dead_letter', [], [
                  'query' => ['import' => $definition->id()],
                ]),
              ],
              'delete' => [
                'title' => $this->t('Delete'),
                'url' => Url::fromRoute('import_engine_ui.definition_delete', [
                  'import_definition' => $definition->id(),
                ]),
              ],
            ],
          ],
        ],
      ];
    }
    return [
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Name'),
          $this->t('ID'),
          $this->t('Source'),
          $this->t('Target'),
          $this->t('Status'),
          $this->t('Operations'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('There are no imports yet.'),
      ],
      '#cache' => ['tags' => ['config:import_engine.import_definition_list']],
    ];
  }

}
