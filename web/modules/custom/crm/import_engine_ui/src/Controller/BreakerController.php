<?php

declare(strict_types=1);

namespace Drupal\import_engine_ui\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Url;
use Drupal\import_engine\Breaker\BreakerState;
use Drupal\import_engine\Breaker\BreakerStore;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Shows the circuit breakers: which servers are being spared.
 */
final class BreakerController extends ControllerBase {

  use AutowireTrait;

  /**
   * Constructs the controller.
   */
  public function __construct(
    #[Autowire(service: 'import_engine.breaker_store')]
    private readonly BreakerStore $breakers,
    #[Autowire(service: 'date.formatter')]
    private readonly DateFormatterInterface $dateFormatter,
  ) {
  }

  /**
   * Lists the breakers.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function list(): array {
    $may_change = $this->currentUser()->hasPermission('administer import runs');
    $rows = [];
    foreach ($this->breakers->all() as $record) {
      $links = [];
      if ($may_change && $record->state !== BreakerState::Closed) {
        $links['reset'] = [
          'title' => $this->t('Close'),
          'url' => Url::fromRoute('import_engine_ui.breaker_action', [
            'action' => 'reset',
            'endpoint' => $record->endpoint,
          ]),
        ];
      }
      if ($may_change && $record->state === BreakerState::Closed) {
        $links['trip'] = [
          'title' => $this->t('Open'),
          'url' => Url::fromRoute('import_engine_ui.breaker_action', [
            'action' => 'trip',
            'endpoint' => $record->endpoint,
          ]),
        ];
      }
      $rows[] = [
        $record->endpoint,
        str_replace('_', ' ', strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $record->state->name) ?? $record->state->name)) . ($record->manual ? ' (by hand)' : ''),
        $record->failures,
        $record->trips,
        $record->opened > 0 ? $this->dateFormatter->format($record->opened, 'custom', 'Y-m-d H:i:s') : '-',
        $record->state === BreakerState::Open && !$record->manual ? $this->dateFormatter->format($record->nextProbe, 'custom', 'Y-m-d H:i:s') : '-',
        ['data' => ['#type' => 'operations', '#links' => $links]],
      ];
    }
    return [
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Server'),
          $this->t('State'),
          $this->t('Failures in a row'),
          $this->t('Times opened in a row'),
          $this->t('Opened'),
          $this->t('Next probe'),
          $this->t('Operations'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No server has had a problem yet.'),
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

}
