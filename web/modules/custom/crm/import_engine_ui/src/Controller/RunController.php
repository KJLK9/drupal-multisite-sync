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
      '#attached' => ['library' => ['import_engine_ui/runs']],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Shows one run: what it is, how it went, and the buttons that fit.
   *
   * The items and the events of a run are on their own pages, behind the
   * navigation at the top; they are long, and the page of the run is meant to
   * be taken in at a glance.
   *
   * @param \Drupal\import_engine\Run\ImportRunInterface $import_run
   *   The run.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function view(ImportRunInterface $import_run): array {
    $build = ['#attached' => ['library' => ['import_engine_ui/runs']]];
    $build['nav'] = $this->runNavigation($import_run, 'overview');

    if (!$import_run->getStatus()->isFinal() && $this->currentUser()->hasPermission('administer import runs')) {
      $build['actions'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['import-run-actions']],
        'continue' => [
          '#type' => 'link',
          '#title' => $this->t('Continue run'),
          '#url' => Url::fromRoute('import_engine_ui.definition_run', ['import_definition' => $import_run->getDefinitionId()]),
          '#attributes' => ['class' => ['button', 'button--primary']],
        ],
        'cancel' => [
          '#type' => 'link',
          '#title' => $this->t('Cancel run'),
          '#url' => Url::fromRoute('import_engine_ui.run_cancel', ['import_run' => $import_run->id()]),
          '#attributes' => ['class' => ['button', 'button--danger']],
        ],
      ];
    }

    $build['summary'] = [
      '#type' => 'inline_template',
      '#template' => '<dl class="import-run-facts">{% for fact in facts %}<div class="import-run-fact"><dt>{{ fact.label }}</dt><dd>{{ fact.value }}</dd></div>{% endfor %}</dl>',
      '#context' => [
        'facts' => $this->facts($import_run),
      ],
    ];

    $counters = $this->counters($import_run);
    $tiles = [];
    foreach ($counters as $name => $count) {
      // What went wrong stands out as soon as it is not zero.
      $tiles[] = [
        'label' => str_replace('_', ' ', $name),
        'count' => $count,
        'class' => in_array($name, ['failed', 'dead'], TRUE) && $count > 0 ? 'is-problem' : '',
      ];
    }
    $build['counters'] = [
      '#type' => 'inline_template',
      '#template' => '<h2 class="import-run-heading">{{ title }}</h2><dl class="import-run-counters">{% for tile in tiles %}<div class="import-run-counter {{ tile.class }}"><dd>{{ tile.count }}</dd><dt>{{ tile.label }}</dt></div>{% endfor %}</dl>',
      '#context' => [
        'title' => $import_run->getStatus()->isFinal() ? $this->t('Counters') : $this->t('Counters (live)'),
        'tiles' => $tiles,
      ],
    ];
    $build['#cache'] = ['max-age' => 0];
    return $build;
  }

  /**
   * Shows the items of a run, with the states as buttons to filter by.
   *
   * @param \Drupal\import_engine\Run\ImportRunInterface $import_run
   *   The run.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request, for the item filter.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function items(ImportRunInterface $import_run, Request $request): array {
    $run_id = (int) $import_run->id();
    $filter = $this->stateFilter($request);
    $build = ['#attached' => ['library' => ['import_engine_ui/runs']]];
    $build['nav'] = $this->runNavigation($import_run, 'items');

    $states = $this->items->countByState($run_id);
    $buttons = [
      ['title' => $this->t('All'), 'count' => array_sum($states), 'query' => [], 'active' => $filter === NULL],
    ];
    foreach ($states as $name => $count) {
      $buttons[] = [
        'title' => ucfirst((string) $name),
        'count' => $count,
        'query' => ['state' => $name],
        'active' => $filter !== NULL && strtolower($filter->name) === $name,
      ];
    }
    $build['filter'] = ['#type' => 'container', '#attributes' => ['class' => ['import-run-filter']]];
    foreach ($buttons as $delta => $button) {
      $build['filter'][$delta] = [
        '#type' => 'link',
        '#title' => $this->t('@title (@count)', ['@title' => $button['title'], '@count' => $button['count']]),
        '#url' => Url::fromRoute('import_engine_ui.run_items', ['import_run' => $run_id], ['query' => $button['query']]),
        '#attributes' => ['class' => ['import-run-pill', $button['active'] ? 'is-active' : '']],
      ];
    }

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
    $build['pager'] = ['#type' => 'pager', '#element' => 0];
    $build['#cache'] = ['max-age' => 0];
    return $build;
  }

  /**
   * Shows the events of a run: what changed or went wrong.
   *
   * @param \Drupal\import_engine\Run\ImportRunInterface $import_run
   *   The run.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function events(ImportRunInterface $import_run): array {
    $run_id = (int) $import_run->id();
    $build = ['#attached' => ['library' => ['import_engine_ui/runs']]];
    $build['nav'] = $this->runNavigation($import_run, 'events');

    $pager = $this->pagerManager->createPager($this->events->countForRun($run_id), self::PER_PAGE, 0);
    $rows = [];
    foreach ($this->events->forRun($run_id, self::PER_PAGE, $pager->getCurrentPage() * self::PER_PAGE) as $event) {
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
      '#header' => [$this->t('When'), $this->t('Event'), $this->t('Key'), $this->t('Target'), $this->t('Message')],
      '#rows' => $rows,
      '#empty' => $this->t('Nothing changed and nothing went wrong.'),
    ];
    $build['pager'] = ['#type' => 'pager', '#element' => 0];
    $build['#cache'] = ['max-age' => 0];
    return $build;
  }

  /**
   * Builds the buttons that go between the pages of a run.
   *
   * @param \Drupal\import_engine\Run\ImportRunInterface $run
   *   The run.
   * @param string $current
   *   The page that is shown: overview, items or events.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function runNavigation(ImportRunInterface $run, string $current): array {
    $run_id = (int) $run->id();
    $item_count = array_sum($this->items->countByState($run_id));
    $event_count = $this->events->countForRun($run_id);
    $pages = [
      'overview' => [$this->t('Overview'), 'import_engine_ui.run'],
      'items' => [$this->t('Items (@count)', ['@count' => $item_count]), 'import_engine_ui.run_items'],
      'events' => [$this->t('Events (@count)', ['@count' => $event_count]), 'import_engine_ui.run_events'],
    ];
    $nav = ['#type' => 'container', '#attributes' => ['class' => ['import-run-nav']]];
    foreach ($pages as $name => [$title, $route]) {
      $nav[$name] = [
        '#type' => 'link',
        '#title' => $title,
        '#url' => Url::fromRoute($route, ['import_run' => $run_id]),
        '#attributes' => ['class' => ['import-run-pill', $name === $current ? 'is-active' : '']],
      ];
    }
    return $nav;
  }

  /**
   * Returns what the overview says about a run, as label and value.
   *
   * @return list<array{label: \Drupal\Core\StringTranslation\TranslatableMarkup, value: mixed}>
   *   The facts.
   */
  private function facts(ImportRunInterface $run): array {
    $remarks = $run->getSummary();
    $full = $run->isFullRun() ? ' (full run)' : '';
    $complete = $run->isExtractComplete() ? $this->t('yes') : $this->t('no');
    return [
      ['label' => $this->t('Import'), 'value' => $this->definitionLabel($run)],
      ['label' => $this->t('Status'), 'value' => $this->statusCell($run)['data']],
      ['label' => $this->t('Started by'), 'value' => $run->getTrigger()->value . $full],
      ['label' => $this->t('Started'), 'value' => $this->timestamp($run, 'started')],
      ['label' => $this->t('Finished'), 'value' => $this->timestamp($run, 'finished')],
      ['label' => $this->t('Duration'), 'value' => $this->duration($run)],
      ['label' => $this->t('Pages read'), 'value' => $run->getPagesRead()],
      ['label' => $this->t('Extraction complete'), 'value' => $complete],
      ['label' => $this->t('Remarks'), 'value' => $remarks === '' ? '-' : $remarks],
    ];
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
