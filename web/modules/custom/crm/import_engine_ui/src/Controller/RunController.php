<?php

declare(strict_types=1);

namespace Drupal\import_engine_ui\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Link;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\import_engine\Entity\ImportDefinition;
use Drupal\import_engine\Run\ImportRunInterface;
use Drupal\import_engine\Run\RunCounters;
use Drupal\import_engine\Storage\EventLog;
use Drupal\import_engine\Storage\ItemState;
use Drupal\import_engine\Storage\ItemStorage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * Shows the runs of the imports, and one run with its items and events.
 */
final class RunController extends ControllerBase {

  use AutowireTrait;

  /**
   * The runs, items and events shown per page.
   */
  private const PER_PAGE = 50;

  /**
   * Constructs the controller.
   */
  public function __construct(
    #[Autowire(service: 'import_engine.item_storage')]
    private readonly ItemStorage $items,
    #[Autowire(service: 'import_engine.event_log')]
    private readonly EventLog $events,
    #[Autowire(service: 'import_engine.run_counters')]
    private readonly RunCounters $counters,
    #[Autowire(service: 'date.formatter')]
    private readonly DateFormatterInterface $dateFormatter,
    #[Autowire(service: 'pager.manager')]
    private readonly PagerManagerInterface $pagerManager,
    #[Autowire(service: 'datetime.time')]
    private readonly TimeInterface $clock,
  ) {
  }

  /**
   * Lists the runs, newest first.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function list(): array {
    $storage = $this->entityTypeManager()->getStorage('import_run');
    $total = (int) $storage->getQuery()->accessCheck(TRUE)->count()->execute();
    $pager = $this->pagerManager->createPager($total, self::PER_PAGE);
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->sort('id', 'DESC')
      ->range($pager->getCurrentPage() * self::PER_PAGE, self::PER_PAGE)
      ->execute();

    $rows = [];
    foreach ($storage->loadMultiple($ids) as $run) {
      if (!$run instanceof ImportRunInterface) {
        continue;
      }
      $counters = $this->counters($run);
      $rows[] = [
        Link::createFromRoute((string) $run->id(), 'import_engine_ui.run', ['import_run' => $run->id()]),
        $this->definitionLabel($run),
        $this->statusCell($run),
        $run->getTrigger()->value,
        $this->timestamp($run, 'started'),
        $this->duration($run),
        $counters['items_extracted'],
        $counters['created'],
        $counters['updated'],
        $counters['failed'],
        $counters['dead'],
      ];
    }

    return [
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Run'),
          $this->t('Import'),
          $this->t('Status'),
          $this->t('Started by'),
          $this->t('Started'),
          $this->t('Duration'),
          $this->t('Extracted'),
          $this->t('Created'),
          $this->t('Updated'),
          $this->t('Failed'),
          $this->t('Dead'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('There are no runs yet. Start one with <code>drush import:run</code>.'),
      ],
      'pager' => ['#type' => 'pager'],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Shows one run: its figures, items and events.
   *
   * @param \Drupal\import_engine\Run\ImportRunInterface $import_run
   *   The run.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request, for the item filter.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function view(ImportRunInterface $import_run, Request $request): array {
    $run_id = (int) $import_run->id();
    $filter = $this->stateFilter($request);
    $build = [];

    $build['summary'] = [
      '#type' => 'table',
      '#caption' => $this->t('Run'),
      '#rows' => [
        [$this->t('Import'), $this->definitionLabel($import_run)],
        [$this->t('Status'), $this->statusCell($import_run)],
        [$this->t('Started by'), $import_run->getTrigger()->value . ($import_run->isFullRun() ? ' (full run)' : '')],
        [$this->t('Started'), $this->timestamp($import_run, 'started')],
        [$this->t('Finished'), $this->timestamp($import_run, 'finished')],
        [$this->t('Pages read'), $import_run->getPagesRead()],
        [$this->t('Extraction complete'), $import_run->isExtractComplete() ? $this->t('yes') : $this->t('no')],
        [$this->t('Remarks'), $import_run->getSummary() === '' ? '-' : $import_run->getSummary()],
      ],
    ];

    if (!$import_run->getStatus()->isFinal() && $this->currentUser()->hasPermission('administer import runs')) {
      $build['operations'] = [
        '#type' => 'operations',
        '#links' => [
          'continue' => [
            'title' => $this->t('Continue run'),
            'url' => Url::fromRoute('import_engine_ui.definition_run', ['import_definition' => $import_run->getDefinitionId()]),
          ],
          'cancel' => [
            'title' => $this->t('Cancel run'),
            'url' => Url::fromRoute('import_engine_ui.run_cancel', ['import_run' => $run_id]),
          ],
        ],
      ];
    }

    $counters = $this->counters($import_run);
    $build['counters'] = [
      '#type' => 'table',
      '#caption' => $import_run->getStatus()->isFinal() ? $this->t('Counters') : $this->t('Counters (live)'),
      '#header' => array_map(static fn (string $name): string => str_replace('_', ' ', $name), array_keys($counters)),
      '#rows' => [array_values($counters)],
    ];

    $states = $this->items->countByState($run_id);
    $links = [Link::createFromRoute($this->t('all (@count)', ['@count' => array_sum($states)]), 'import_engine_ui.run', ['import_run' => $run_id])];
    foreach ($states as $name => $count) {
      $links[] = Link::createFromRoute($this->t('@state (@count)', ['@state' => $name, '@count' => $count]), 'import_engine_ui.run', ['import_run' => $run_id], ['query' => ['state' => $name]]);
    }
    $build['filter'] = ['#theme' => 'item_list', '#title' => $this->t('Items'), '#items' => $links];

    $total = $this->items->countItems([$run_id], $filter);
    $pager = $this->pagerManager->createPager($total, self::PER_PAGE, 0);
    $rows = [];
    foreach ($this->items->listItems([$run_id], $filter, self::PER_PAGE, $pager->getCurrentPage() * self::PER_PAGE) as $item) {
      $rows[] = [
        $item->key,
        $item->state->name,
        $item->outcome->name ?? '',
        $item->attempts,
        $item->state === ItemState::Retrying ? $this->dateFormatter->format($item->nextAttempt, 'custom', 'Y-m-d H:i:s') : '',
        $item->error ?? '',
      ];
    }
    $build['items'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Key'),
        $this->t('State'),
        $this->t('Outcome'),
        $this->t('Attempts'),
        $this->t('Next attempt'),
        $this->t('Error'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No items.'),
    ];
    $build['items_pager'] = ['#type' => 'pager', '#element' => 0];

    $event_pager = $this->pagerManager->createPager($this->events->countForRun($run_id), self::PER_PAGE, 1);
    $rows = [];
    foreach ($this->events->forRun($run_id, self::PER_PAGE, $event_pager->getCurrentPage() * self::PER_PAGE) as $event) {
      $rows[] = [
        $this->dateFormatter->format($event->occurred, 'custom', 'Y-m-d H:i:s'),
        $event->event->name,
        $event->key,
        $event->target ?? '',
        $event->message ?? '',
      ];
    }
    $build['events'] = [
      '#type' => 'table',
      '#caption' => $this->t('Events: what changed or went wrong'),
      '#header' => [$this->t('When'), $this->t('Event'), $this->t('Key'), $this->t('Target'), $this->t('Message')],
      '#rows' => $rows,
      '#empty' => $this->t('Nothing changed and nothing went wrong.'),
    ];
    $build['events_pager'] = ['#type' => 'pager', '#element' => 1];
    $build['#cache'] = ['max-age' => 0];
    return $build;
  }

  /**
   * Returns the title of the page of a run.
   */
  public function title(ImportRunInterface $import_run): TranslatableMarkup {
    return $this->t('Run @id of @import', [
      '@id' => (string) $import_run->id(),
      '@import' => $this->definitionLabel($import_run),
    ]);
  }

  /**
   * Returns the counters of a run: the stored snapshot, or live ones.
   *
   * @return array<string, int>
   *   The counters.
   */
  private function counters(ImportRunInterface $run): array {
    return $run->getStatus()->isFinal() ? $run->getCounters() : $this->counters->derive($run);
  }

  /**
   * Returns the label of the import of a run.
   */
  private function definitionLabel(ImportRunInterface $run): string {
    $definition = ImportDefinition::load($run->getDefinitionId());
    return $definition === NULL ? $run->getDefinitionId() : (string) $definition->label();
  }

  /**
   * Returns a table cell with the status of a run.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function statusCell(ImportRunInterface $run): array {
    $status = $run->getStatus();
    return [
      'data' => [
        '#markup' => '<span class="import-run-status import-run-status--' . Html::getClass($status->value) . '">' . str_replace('_', ' ', $status->value) . '</span>',
      ],
    ];
  }

  /**
   * Formats a timestamp field of a run; a dash when it is empty.
   */
  private function timestamp(ImportRunInterface $run, string $field): string {
    $value = $run->get($field)->value;
    return $value === NULL ? '-' : $this->dateFormatter->format((int) $value, 'custom', 'Y-m-d H:i:s');
  }

  /**
   * Returns how long a run took, or how long it has been going.
   */
  private function duration(ImportRunInterface $run): string {
    $started = $run->get('started')->value;
    if ($started === NULL) {
      return '-';
    }
    $finished = $run->get('finished')->value;
    return (string) $this->dateFormatter->formatInterval(max(0, ((int) ($finished ?? $this->clock->getRequestTime())) - (int) $started));
  }

  /**
   * Reads the state filter of the request; an unknown value means no filter.
   */
  private function stateFilter(Request $request): ?ItemState {
    $wanted = $request->query->get('state');
    foreach (ItemState::cases() as $state) {
      if (is_string($wanted) && strtolower($state->name) === $wanted) {
        return $state;
      }
    }
    return NULL;
  }

}
