<?php

declare(strict_types=1);

namespace Drupal\import_engine_ui\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\import_engine\Breaker\BreakerRecord;
use Drupal\import_engine\Breaker\BreakerState;
use Drupal\import_engine\Breaker\BreakerStore;
use Drupal\import_engine\ImportConnectionInterface;
use Drupal\import_engine_ui\Help\Chrome;
use Drupal\import_engine_ui\Help\Glossary;
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
    #[Autowire(service: 'import_engine_ui.glossary')]
    private readonly Glossary $glossary,
    #[Autowire(service: 'entity_type.manager')]
    private readonly EntityTypeManagerInterface $typeManager,
  ) {
  }

  /**
   * Returns the breakers, and the servers of the connections that have none.
   *
   * A server that never had a problem has no record, but it can be opened by
   * hand to see what an outage does; it is listed as closed.
   *
   * @return list<\Drupal\import_engine\Breaker\BreakerRecord>
   *   The records, by server.
   */
  private function records(): array {
    $records = [];
    foreach ($this->breakers->all() as $record) {
      $records[$record->endpoint] = $record;
    }
    foreach ($this->typeManager->getStorage('import_connection')->loadMultiple() as $connection) {
      if (!$connection instanceof ImportConnectionInterface) {
        continue;
      }
      $parts = parse_url((string) ($connection->getSource()['configuration']['url'] ?? ''));
      if (!is_array($parts) || !isset($parts['host'])) {
        continue;
      }
      $endpoint = strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
      $records[$endpoint] ??= new BreakerRecord($endpoint);
    }
    ksort($records);
    return array_values($records);
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
    $counts = [BreakerState::Closed->value => 0, BreakerState::Open->value => 0, BreakerState::HalfOpen->value => 0];
    $sentences = [];
    foreach ($this->records() as $record) {
      $counts[$record->state->value]++;
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
        ['data' => ['#markup' => $this->statePill($record)]],
        $record->failures,
        $record->trips,
        $record->opened > 0 ? $this->dateFormatter->format($record->opened, 'custom', 'Y-m-d H:i:s') : '-',
        $record->state === BreakerState::Open && !$record->manual ? $this->dateFormatter->format($record->nextProbe, 'custom', 'Y-m-d H:i:s') : '-',
        ['data' => ['#type' => 'operations', '#links' => $links]],
      ];
      $sentence = $this->sentence($record);
      if ($sentence !== '') {
        $sentences[] = $sentence;
      }
    }

    $pills = [];
    if ($counts[BreakerState::Open->value] > 0) {
      $pills[] = ['failed', (string) $this->formatPlural($counts[BreakerState::Open->value], '1 open', '@count open')];
    }
    if ($counts[BreakerState::HalfOpen->value] > 0) {
      $pills[] = ['template', (string) $this->formatPlural(
        $counts[BreakerState::HalfOpen->value],
        '1 being tried again',
        '@count being tried again',
        ),
      ];
    }
    $pills[] = ['on', (string) $this->formatPlural($counts[BreakerState::Closed->value], '1 closed', '@count closed')];
    $explanation = (string) $this->glossary->terms()['breaker']->meaning;
    return [
      '#attached' => ['library' => ['import_engine_ui/runs']],
      '#prefix' => '<div class="import-engine-form import-run-page">',
      '#suffix' => '</div>',
      'header' => Chrome::header(
        (string) $this->t('Servers that are spared'),
        $rows === [] ? [['on', (string) $this->t('No problems')]] : $pills,
        (string) $this->formatPlural(count($rows), '1 server watched', '@count servers watched'),
      ),
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
      'summary_plain' => Chrome::panel(
        'breaker-summary-plain',
        (string) $this->t('In plain words'),
        (string) $this->t('What a circuit breaker does, and where they stand.'),
        [$explanation, ...($sentences ?: [(string) $this->t('No server is being spared right now.')])],
      ),
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Builds the label of the state of a breaker, with its colour.
   */
  private function statePill(BreakerRecord $record): string {
    [$style, $word] = match ($record->state) {
      BreakerState::Closed => ['on', $this->t('closed')],
      BreakerState::Open => ['failed', $this->t('open')],
      BreakerState::HalfOpen => ['template', $this->t('half open')],
    };
    $by_hand = $record->manual ? ' ' . $this->t('(by hand)') : '';
    return '<span class="import-pill import-pill--' . $style . '">' . Html::escape((string) $word . $by_hand) . '</span>';
  }

  /**
   * Says in a sentence where a breaker stands, if it is not simply closed.
   *
   * @return string
   *   A sentence made by t() so that what is put in it is escaped, or an empty
   *   string for a closed breaker.
   */
  private function sentence(BreakerRecord $record): string {
    return match (TRUE) {
      $record->state === BreakerState::Open && $record->manual => (string) $this->t('@server was opened by hand: no calls go to it until someone closes it.', ['@server' => $record->endpoint]),
      $record->state === BreakerState::Open => (string) $this->t('@server is taken to be down, and is left alone. From @time a probe checks whether it is back.', [
        '@server' => $record->endpoint,
        '@time' => $this->dateFormatter->format($record->nextProbe, 'custom', 'H:i:s'),
      ]),
      $record->state === BreakerState::HalfOpen => (string) $this->t('@server is being tried again: the next call decides whether it is closed or opens again.', ['@server' => $record->endpoint]),
      default => '',
    };
  }

}
