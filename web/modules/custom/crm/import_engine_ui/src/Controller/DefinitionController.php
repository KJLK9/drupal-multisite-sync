<?php

declare(strict_types=1);

namespace Drupal\import_engine_ui\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Url;
use Drupal\import_engine\ImportDefinitionInterface;
use Drupal\import_engine\Schedule\ScheduleRules;

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
        ScheduleRules::describe($definition->getSchedule()) ?: '-',
        $definition->status() ? $this->t('enabled') : $this->t('disabled'),
        [
          'data' => [
            '#type' => 'operations',
            '#links' => $this->allowed([
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
            ]),
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
          $this->t('Schedule'),
          $this->t('Status'),
          $this->t('Operations'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('There are no imports yet.'),
      ],
      '#cache' => [
        'tags' => ['config:import_engine.import_definition_list'],
        'contexts' => ['user.permissions'],
      ],
    ];
  }

  /**
   * Keeps the operations that the person may use.
   *
   * "Run now" and the dead letter queue need other permissions than the list
   * does; a link to a page that says "access denied" is not an operation.
   *
   * @param array<string, array<string, mixed>> $links
   *   The operations, each with a URL.
   *
   * @return array<string, array<string, mixed>>
   *   The operations that lead somewhere the person may go.
   */
  private function allowed(array $links): array {
    return array_filter($links, static fn (array $link): bool => $link['url'] instanceof Url && $link['url']->access());
  }

}
