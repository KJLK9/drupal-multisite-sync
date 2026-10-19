<?php

declare(strict_types=1);

namespace Drupal\import_audit\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\import_audit\Hook\CronHooks;
use Drupal\import_audit\History\ChangeLog;
use Drupal\import_audit\History\EntityHistory;
use Drupal\import_engine_ui\Help\Chrome;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The page "Import history" of an entity.
 */
final class HistoryController extends ControllerBase {

  use AutowireTrait;

  /**
   * Constructs the controller.
   */
  public function __construct(
    #[Autowire(service: 'import_audit.history')]
    private readonly EntityHistory $history,
    #[Autowire(service: 'date.formatter')]
    private readonly DateFormatterInterface $dateFormatter,
    #[Autowire(service: 'current_route_match')]
    private readonly RouteMatchInterface $routeMatch,
    #[Autowire(service: 'import_audit.change_log')]
    private readonly ChangeLog $changes,
  ) {
  }

  /**
   * Leaves out the fields the person may not see on the entity itself.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param array<string, list<array{field: string, old: string, new: string}>> $changes
   *   The changes, by write.
   *
   * @return array<string, list<array{field: string, old: string, new: string}>>
   *   The changes of the fields that may be seen.
   */
  private function visibleChanges(EntityInterface $entity, array $changes): array {
    if (!$entity instanceof FieldableEntityInterface) {
      return [];
    }
    foreach ($changes as $group => $list) {
      $changes[$group] = array_values(array_filter($list, static fn (array $change): bool => $entity->hasField($change['field'])
        && $entity->get($change['field'])->access('view')));
    }
    return $changes;
  }

  /**
   * Shows the fields of a write as "field: old → new".
   *
   * @param list<array{field: string, old: string, new: string}> $changes
   *   The changes of the write.
   *
   * @return array<string, mixed>
   *   A render array; empty when nothing was kept.
   */
  private function changeList(array $changes): array {
    if ($changes === []) {
      return [];
    }
    $items = [];
    foreach ($changes as $change) {
      $items[] = $this->t('@field: @old → @new', [
        '@field' => $change['field'],
        '@old' => $change['old'] === '' ? $this->t('(empty)') : $change['old'],
        '@new' => $change['new'] === '' ? $this->t('(empty)') : $change['new'],
      ]);
    }
    return ['#theme' => 'item_list', '#items' => $items, '#attributes' => ['class' => ['import-changes']]];
  }

  /**
   * Lists what the imports did to the entity, newest first.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function view(): array {
    $type = (string) $this->routeMatch->getRouteObject()?->getDefault('entity_type_id');
    $entity = $this->routeMatch->getParameter($type);
    if (!$entity instanceof EntityInterface) {
      return [];
    }
    $events = $this->history->forEntity($type, (string) $entity->id());
    $days = (int) $this->config('import_engine.settings')->get('retention_events_days');
    $kept = $days > 0
      ? (string) $this->formatPlural($days, 'Events are kept for 1 day.', 'Events are kept for @count days.')
      : (string) $this->t('Events are kept for ever.');

    $runs = $this->entityTypeManager()->getStorage('import_run')->loadMultiple(array_unique(array_map(static fn ($event): int => $event->runId, $events)));
    $definitions = $this->entityTypeManager()->getStorage('import_definition')->loadMultiple(array_unique(array_map(static fn ($event): string => $event->definitionId, $events)));
    $changes = $this->visibleChanges($entity, $this->changes->forEntity($type, (string) $entity->id()));
    $rows = [];
    foreach ($events as $event) {
      $definition = $definitions[$event->definitionId] ?? NULL;
      $rows[] = [
        $this->dateFormatter->format($event->occurred, 'custom', 'Y-m-d H:i:s'),
        $event->event->name,
        $definition === NULL ? $event->definitionId : $definition->label(),
        isset($runs[$event->runId])
          ? ['data' => Link::fromTextAndUrl($this->t('Run @id', ['@id' => $event->runId]), Url::fromRoute('import_engine_ui.run', ['import_run' => $event->runId]))->toRenderable()]
          : $this->t('Run @id (removed)', ['@id' => $event->runId]),
        $event->key,
        $event->message ?? '',
        ['data' => $this->changeList($changes[ChangeLog::group($event->runId, $event->definitionId, $event->key)] ?? [])],
      ];
    }

    return [
      '#attached' => ['library' => ['import_engine_ui/runs']],
      '#prefix' => '<div class="import-engine-form import-run-page">',
      '#suffix' => '</div>',
      'header' => Chrome::header(
        (string) $this->t('Import history of @label', ['@label' => (string) $entity->label()]),
        [['on', (string) $this->formatPlural(count($events), '1 change', '@count changes')]],
        (string) $this->t('What the imports did to this @type', ['@type' => (string) $entity->getEntityType()->getSingularLabel()]),
      ),
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('When'),
          $this->t('What'),
          $this->t('Import'),
          $this->t('Run'),
          $this->t('Source key'),
          $this->t('Message'),
          $this->t('Changes'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('Nothing is left in the log about this one.'),
      ],
      'about' => Chrome::panel(
        'import-history-about',
        (string) $this->t('What this list is'),
        (string) $this->t('Only what the imports did, and only for a while.'),
        [
          (string) $this->t('It shows what imports created, changed, hid or refused for this one. A change made by hand is not in it.'),
          $kept . ' ' . $this->t('Older changes are gone from this list, even though they happened.'),
          (string) $this->t('The old and new values of the fields are kept for @days days, and only from the moment the audit module was switched on; before that the list says that something changed, not what.', [
            '@days' => (string) ($this->config('import_audit.settings')->get('retention_days') ?? CronHooks::DEFAULT_DAYS),
          ]),
        ],
      ),
      '#cache' => ['max-age' => 0],
    ];
  }

}
