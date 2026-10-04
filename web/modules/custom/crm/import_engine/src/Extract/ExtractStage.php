<?php

declare(strict_types=1);

namespace Drupal\import_engine\Extract;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\import_engine\ImportDefinitionInterface;
use Drupal\import_engine\Key\InvalidKeyException;
use Drupal\import_engine\Key\ItemKey;
use Drupal\import_engine\Page\PageFingerprint;
use Drupal\import_engine\Page\ProcessingFingerprint;
use Drupal\import_engine\Run\ImportRunInterface;
use Drupal\import_engine\Run\RunStatus;
use Drupal\import_engine\Source\SourceException;
use Drupal\import_engine\Source\SourceInterface;
use Drupal\import_engine\Source\SourcePage;
use Drupal\import_engine\Storage\EventLog;
use Drupal\import_engine\Storage\EventType;
use Drupal\import_engine\Storage\ItemStorage;
use Drupal\import_engine\Storage\MappingStore;
use Drupal\import_engine\Storage\PageStore;

/**
 * Reads the pages of a source and puts their items in the work queue.
 *
 * The stage works in portions and can be called again to continue: the run
 * keeps the position of the next page. It stops when the page or time budget
 * of a call runs out, when the source has a temporary problem, or when the
 * source is out of pages.
 *
 * Per page:
 * - A page whose fingerprint was already seen in this run is a repeat; after a
 *   number of repeats in a row the source is taken to ignore its paging and
 *   extraction ends abnormally. Matching a page of an earlier run never does.
 * - A page that did not change since the previous run, and whose items were all
 *   handled without failures then (a verified page), is skipped: its items are
 *   not queued, but they are marked as seen, or the sweep would take them for
 *   deleted.
 * - Otherwise the items are queued, with the position of the page.
 * - An item without a usable key is not queued: it gets an event and counts as
 *   failed, and its page is never verified, so it is reported again next time.
 *
 * Only one extraction of an import runs at a time, held by a persistent lock
 * that is renewed per page and expires if the process dies.
 */
final class ExtractStage {

  /**
   * How long the lock is held without being renewed, in seconds.
   */
  public const LOCK_SECONDS = 300;

  /**
   * Constructs the stage.
   */
  public function __construct(
    private readonly ItemStorage $items,
    private readonly PageStore $pages,
    private readonly MappingStore $mapping,
    private readonly EventLog $events,
    private readonly ItemKey $keys,
    private readonly PageFingerprint $fingerprints,
    private readonly ProcessingFingerprint $processing,
    private readonly LockBackendInterface $lock,
    private readonly TimeInterface $time,
  ) {
  }

  /**
   * Reads pages of the source into the work queue.
   *
   * @param \Drupal\import_engine\Run\ImportRunInterface $run
   *   The run: queued, or extracting when it continues.
   * @param \Drupal\import_engine\ImportDefinitionInterface $definition
   *   The import of the run.
   * @param \Drupal\import_engine\Source\SourceInterface $source
   *   The source to read.
   * @param int|null $maxPages
   *   The most pages to read in this call; at least one is always read.
   * @param int|null $deadline
   *   A timestamp after which no new page is started in this call.
   *
   * @throws \LogicException
   *   When the run is not queued or extracting, or belongs to another import.
   */
  public function extract(ImportRunInterface $run, ImportDefinitionInterface $definition, SourceInterface $source, ?int $maxPages = NULL, ?int $deadline = NULL): ExtractResult {
    if (!in_array($run->getStatus(), [RunStatus::Queued, RunStatus::Extracting], TRUE)) {
      throw new \LogicException(sprintf('A run in status "%s" cannot be extracted.', $run->getStatus()->value));
    }
    if ($run->getDefinitionId() !== $definition->id()) {
      throw new \LogicException('The run belongs to another import.');
    }

    $lock = 'import_engine:extract:' . $definition->id();
    if (!$this->lock->acquire($lock, self::LOCK_SECONDS)) {
      return new ExtractResult(ExtractStatus::Busy, message: 'Another extraction of this import is running.');
    }
    try {
      return $this->work($run, $definition, $source, $lock, $maxPages, $deadline);
    }
    finally {
      $this->lock->release($lock);
    }
  }

  /**
   * Does the work of a call, holding the lock.
   */
  private function work(ImportRunInterface $run, ImportDefinitionInterface $definition, SourceInterface $source, string $lock, ?int $maxPages, ?int $deadline): ExtractResult {
    $runId = (int) $run->id();
    $definitionId = (string) $definition->id();
    if ($run->getStatus() === RunStatus::Queued) {
      $this->begin($run, $definition);
    }

    $position = $run->getPagesRead();
    $cursor = $run->getCursor();
    $seen = array_fill_keys($this->pages->fingerprintsOfRun($definitionId, $runId), TRUE);
    $repeats = 0;
    $totals = ['pages' => 0, 'queued' => 0, 'skipped' => 0, 'invalid' => 0];

    while (TRUE) {
      // Renew the lock; if it was lost, another process took over.
      if (!$this->lock->acquire($lock, self::LOCK_SECONDS)) {
        return $this->result(ExtractStatus::Busy, $totals, 'The lock on this import was lost.');
      }

      try {
        $page = $source->fetchPage($cursor);
      }
      catch (SourceException $exception) {
        if ($exception->retryable) {
          $run->setSummary('Interrupted: ' . $exception->getMessage())->save();
          return $this->result(ExtractStatus::Interrupted, $totals, $exception->getMessage());
        }
        $this->abort($run, 'The source failed: ' . $exception->getMessage());
        return $this->result(ExtractStatus::Failed, $totals, $exception->getMessage());
      }

      $stop = $this->readPage($run, $definition, $page, $position, $seen, $repeats, $totals);
      $position++;
      $cursor = $page->nextCursor;
      $totals['pages']++;

      if ($stop !== NULL) {
        $this->abort($run, $stop);
        return $this->result(ExtractStatus::Failed, $totals, $stop);
      }
      if ($cursor === NULL) {
        $this->complete($run, $definitionId, $position);
        return $this->result(ExtractStatus::Complete, $totals);
      }
      if (($maxPages !== NULL && $totals['pages'] >= $maxPages) || ($deadline !== NULL && $this->time->getCurrentTime() >= $deadline)) {
        return $this->result(ExtractStatus::MoreToDo, $totals);
      }
    }
  }

  /**
   * Starts a queued run: it extracts, and the page records are checked.
   */
  private function begin(ImportRunInterface $run, ImportDefinitionInterface $definition): void {
    $run->transitionTo(RunStatus::Extracting, $this->time->getRequestTime());
    // A changed mapping or target voids every remembered page.
    $this->pages->syncConfigFingerprint((string) $definition->id(), $this->processing->of($definition));
    $run->save();
  }

  /**
   * Handles one page; returns a reason to stop extraction, if there is one.
   *
   * @param \Drupal\import_engine\Run\ImportRunInterface $run
   *   The run.
   * @param \Drupal\import_engine\ImportDefinitionInterface $definition
   *   The import.
   * @param \Drupal\import_engine\Source\SourcePage $page
   *   The page that was read.
   * @param int $position
   *   The position of the page, counting from 0.
   * @param array<string, true> $seen
   *   The fingerprints of the pages of this run so far; updated.
   * @param int $repeats
   *   The number of repeated pages in a row; updated.
   * @param array{pages: int, queued: int, skipped: int, invalid: int} $totals
   *   The totals of this call; updated.
   */
  private function readPage(ImportRunInterface $run, ImportDefinitionInterface $definition, SourcePage $page, int $position, array &$seen, int &$repeats, array &$totals): ?string {
    $runId = (int) $run->id();
    $definitionId = (string) $definition->id();
    $fingerprint = $this->fingerprints->fingerprint($page->items);

    [$valid, $invalid] = $this->splitItems($run, $definitionId, $definition->getSourceKey(), $page->items, $position);
    $keys = array_column($valid, 'key');
    $queued = 0;
    $skipped = 0;
    $stop = NULL;

    if (isset($seen[$fingerprint])) {
      $repeats++;
      if ($repeats >= $definition->getMaxRepeatedPages()) {
        $stop = sprintf('The source returned the same page %d times in a row: it seems to ignore its paging settings.', $repeats);
      }
    }
    else {
      $repeats = 0;
      $seen[$fingerprint] = TRUE;
      $record = $this->pages->get($definitionId, $position);
      if (!$run->isFullRun() && $invalid === 0 && $record !== NULL && $record->verified && $record->fingerprint === $fingerprint) {
        $this->pages->touch($definitionId, $position, $runId);
        $skipped = count($keys);
      }
      else {
        $result = $this->items->enqueue($runId, $definition->getPool(), $valid, 0, NULL, $position);
        $queued = $result['inserted'];
        $this->pages->put($definitionId, $position, $fingerprint, $keys, $runId, NULL, $invalid > 0);
      }
      // Seen either way: an item of a skipped page is still in the source.
      $this->mapping->markSeen($definitionId, $keys, $runId);
    }

    $counters = $run->getCounters();
    $run->setCounters([
      'items_extracted' => $counters['items_extracted'] + count($page->items),
      'failed' => $counters['failed'] + $invalid,
      'page_skipped' => $counters['page_skipped'] + $skipped,
    ]);
    $run->setPagesRead($position + 1)->setCursor($page->nextCursor)->save();

    $totals['queued'] += $queued;
    $totals['skipped'] += $skipped;
    $totals['invalid'] += $invalid;
    return $stop;
  }

  /**
   * Splits the items of a page in those with a key and those without.
   *
   * Items without a usable key get a failed event with the reason.
   *
   * @param \Drupal\import_engine\Run\ImportRunInterface $run
   *   The run.
   * @param string $definitionId
   *   The import definition.
   * @param list<string> $keyPaths
   *   The key paths of the import.
   * @param list<array<string, mixed>> $items
   *   The items of the page.
   * @param int $position
   *   The position of the page, counting from 0.
   *
   * @return array{0: list<array{key: string, payload: array<string, mixed>}>, 1: int}
   *   The items with their key, and the number of items without one.
   */
  private function splitItems(ImportRunInterface $run, string $definitionId, array $keyPaths, array $items, int $position): array {
    $valid = [];
    $invalid = 0;
    foreach ($items as $index => $item) {
      try {
        $valid[] = ['key' => $this->keys->build($item, $keyPaths), 'payload' => $item];
      }
      catch (InvalidKeyException $exception) {
        $invalid++;
        // There is no key to log it under, so the event names the position.
        $this->events->record(
          (int) $run->id(),
          $definitionId,
          EventType::Failed,
          sprintf('#page%d.item%d', $position + 1, $index + 1),
          NULL,
          sprintf('Item %d on page %d has no usable key: %s.', $index + 1, $position + 1, $exception->getMessage()),
        );
      }
    }
    return [$valid, $invalid];
  }

  /**
   * Ends extraction normally: every page was read.
   *
   * @param \Drupal\import_engine\Run\ImportRunInterface $run
   *   The run.
   * @param string $definitionId
   *   The import definition.
   * @param int $pagesRead
   *   The number of pages that were read.
   */
  private function complete(ImportRunInterface $run, string $definitionId, int $pagesRead): void {
    // Records behind the end of the data are stale.
    $this->pages->deleteFrom($definitionId, $pagesRead);
    $run->setExtractComplete(TRUE)->transitionTo(RunStatus::Processing, $this->time->getRequestTime())->save();
  }

  /**
   * Ends extraction abnormally.
   *
   * The run keeps draining what is queued, as that is valid data, but the data
   * is incomplete, so it will end as failed and the sweep is not run.
   */
  private function abort(ImportRunInterface $run, string $reason): void {
    $run->setSummary($reason)->transitionTo(RunStatus::Processing, $this->time->getRequestTime())->save();
  }

  /**
   * Builds a result from the totals of a call.
   *
   * @param \Drupal\import_engine\Extract\ExtractStatus $status
   *   How the call ended.
   * @param array{pages: int, queued: int, skipped: int, invalid: int} $totals
   *   The totals of the call.
   * @param string|null $message
   *   Why the call ended, when it did not complete.
   */
  private function result(ExtractStatus $status, array $totals, ?string $message = NULL): ExtractResult {
    return new ExtractResult($status, $totals['pages'], $totals['queued'], $totals['skipped'], $totals['invalid'], $message);
  }

}
