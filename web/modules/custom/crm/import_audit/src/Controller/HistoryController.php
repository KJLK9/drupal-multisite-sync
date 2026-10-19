<?php

declare(strict_types=1);

namespace Drupal\import_audit\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
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
  ) {
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
        ],
      ),
      '#cache' => ['max-age' => 0],
    ];
  }

}
