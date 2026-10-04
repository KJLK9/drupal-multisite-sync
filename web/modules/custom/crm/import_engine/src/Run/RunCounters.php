<?php

declare(strict_types=1);

namespace Drupal\import_engine\Run;

use Drupal\import_engine\Storage\ItemStorage;

/**
 * Works out the counters of a run from its items.
 *
 * The counters are not kept up to date while a run works, since several
 * workers would overwrite each other (ADR 0009). They are derived from the
 * items when they are needed: live for the interface, and once when the run
 * is finished, to be stored on the run as a snapshot.
 */
final class RunCounters {

  /**
   * Constructs the service.
   */
  public function __construct(
    private readonly ItemStorage $items,
  ) {
  }

  /**
   * Derives the counters of a run.
   *
   * The counters the extraction keeps (items extracted, items without a usable
   * key, skipped pages) are taken from the run itself.
   *
   * @param \Drupal\import_engine\Run\ImportRunInterface $run
   *   The run.
   * @param int $deleted
   *   The number of items the sweep unpublished or deleted.
   *
   * @return array<string, int>
   *   The counters, keyed by ImportRunInterface::COUNTERS.
   */
  public function derive(ImportRunInterface $run, int $deleted = 0): array {
    $runId = (int) $run->id();
    $before = $run->getCounters();
    $outcomes = $this->items->countByOutcome($runId);
    $states = $this->items->countByState($runId);
    return [
      'items_extracted' => $before['items_extracted'],
      'created' => $outcomes['created'],
      'updated' => $outcomes['updated'],
      'unchanged' => $outcomes['unchanged'],
      'skipped' => $outcomes['skipped'],
      // The items without a usable key were counted by the extraction.
      'failed' => $before['failed'] + $outcomes['failed'],
      'dead' => $states['dead'],
      'deleted' => $deleted,
      'page_skipped' => $before['page_skipped'],
    ];
  }

}
