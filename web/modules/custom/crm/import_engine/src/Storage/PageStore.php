<?php

declare(strict_types=1);

namespace Drupal\import_engine\Storage;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;

/**
 * Remembers the fingerprint and keys of each page position of an import.
 *
 * One row per position, overwritten by every run, so the table follows the
 * size of the dataset and not the number of runs. A later run compares a page
 * it just read with the record to see whether processing it can be skipped.
 * The keys are kept so that a skipped page still counts as seen; otherwise the
 * sweep for deleted items would remove everything on it.
 *
 * The fingerprint of the mapping and target configuration is kept once per
 * import: when it changes, all records of that import are void.
 */
final class PageStore {

  use QueryHelpers;

  /**
   * The key value collection for the configuration fingerprints.
   */
  private const COLLECTION = 'import_engine.page_config';

  /**
   * The key value store.
   */
  private readonly KeyValueStoreInterface $configFingerprints;

  /**
   * Constructs the store.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly PayloadCodec $codec,
    private readonly TimeInterface $time,
    KeyValueFactoryInterface $keyValue,
  ) {
    $this->configFingerprints = $keyValue->get(self::COLLECTION);
  }

  /**
   * Returns the record of a page position, if there is one.
   */
  public function get(string $definitionId, int $position): ?PageRecord {
    $rows = $this->rows($this->database->select('import_page', 'p')
      ->fields('p')
      ->condition('definition_id', $definitionId)
      ->condition('position', $position));
    if ($rows === []) {
      return NULL;
    }
    $row = $rows[0];
    /** @var list<string> $keys */
    $keys = $this->codec->decode((string) $row->item_keys);
    return new PageRecord(bin2hex((string) $row->fingerprint), $keys, (int) $row->seen_run, (bool) $row->verified);
  }

  /**
   * Records a page position, replacing what was there.
   *
   * @param string $definitionId
   *   The import definition.
   * @param int $position
   *   The position of the page in the run, counting from 0.
   * @param string $fingerprint
   *   The fingerprint of the page, 32 hex characters.
   * @param list<string> $keys
   *   The keys of the items on the page.
   * @param int $runId
   *   The run that read the page.
   * @param int|null $now
   *   The time, for tests.
   * @param bool $problem
   *   Whether the page had items without a usable key. Such a page is never
   *   verified, so that those items are reported again.
   */
  public function put(string $definitionId, int $position, string $fingerprint, array $keys, int $runId, ?int $now = NULL, bool $problem = FALSE): void {
    if (!preg_match('/^[0-9a-f]{32}$/', $fingerprint)) {
      throw new \InvalidArgumentException('A fingerprint is 32 hex characters.');
    }
    $binary = (string) hex2bin($fingerprint);
    $this->database->merge('import_page')
      ->keys(['definition_id' => $definitionId, 'position' => $position])
      ->fields([
        'fingerprint' => $binary,
        'item_count' => count($keys),
        'item_keys' => $this->codec->encode($keys),
        'seen_run' => $runId,
        // New data has to be handled before the page may be skipped again.
        'verified' => 0,
        'problem' => $problem ? 1 : 0,
        'changed' => $now ?? $this->time->getRequestTime(),
      ])
      ->execute();
  }

  /**
   * Notes that a run read a page that did not change and skipped it.
   */
  public function touch(string $definitionId, int $position, int $runId, ?int $now = NULL): void {
    $this->database->update('import_page')
      ->fields(['seen_run' => $runId, 'changed' => $now ?? $this->time->getRequestTime()])
      ->condition('definition_id', $definitionId)
      ->condition('position', $position)
      ->execute();
  }

  /**
   * Verifies the pages a run read, except those that had failures.
   *
   * Called when the items of the run are handled. Only a verified page is
   * skipped by a later run, so an item that failed is not left behind just
   * because its page looks unchanged.
   *
   * @param string $definitionId
   *   The import definition.
   * @param int $runId
   *   The run.
   * @param list<int> $failedPositions
   *   The positions of pages that had failed or dead items.
   *
   * @return int
   *   How many pages were verified now.
   */
  public function verify(string $definitionId, int $runId, array $failedPositions): int {
    $update = $this->database->update('import_page')
      ->fields(['verified' => 1])
      ->condition('definition_id', $definitionId)
      ->condition('seen_run', $runId)
      ->condition('problem', 0)
      ->condition('verified', 0);
    if ($failedPositions !== []) {
      $update->condition('position', $failedPositions, 'NOT IN');
    }
    return (int) $update->execute();
  }

  /**
   * Returns the fingerprints of the pages a run has read so far.
   *
   * Used to find repeated pages, also when a run resumes after an interruption.
   *
   * @return list<string>
   *   Fingerprints as 32 hex characters.
   */
  public function fingerprintsOfRun(string $definitionId, int $runId): array {
    $rows = $this->rows($this->database->select('import_page', 'p')
      ->fields('p', ['fingerprint'])
      ->condition('definition_id', $definitionId)
      ->condition('seen_run', $runId));
    return array_map(static fn (\stdClass $row): string => bin2hex((string) $row->fingerprint), $rows);
  }

  /**
   * Deletes the records from a position on.
   *
   * Used when a run has fewer pages than the one before, so no stale record
   * lingers behind the end of the data.
   *
   * @return int
   *   How many records were deleted.
   */
  public function deleteFrom(string $definitionId, int $firstPosition): int {
    return (int) $this->database->delete('import_page')
      ->condition('definition_id', $definitionId)
      ->condition('position', $firstPosition, '>=')
      ->execute();
  }

  /**
   * Deletes all records of an import.
   *
   * @return int
   *   How many records were deleted.
   */
  public function reset(string $definitionId): int {
    return (int) $this->database->delete('import_page')
      ->condition('definition_id', $definitionId)
      ->execute();
  }

  /**
   * Compares the configuration fingerprint of an import with the one stored.
   *
   * When it differs, the same source data has to be processed again, so every
   * page record of the import is deleted and the new fingerprint is stored.
   *
   * @return bool
   *   TRUE when the configuration changed and the records were reset.
   */
  public function syncConfigFingerprint(string $definitionId, string $fingerprint): bool {
    if ($this->configFingerprints->get($definitionId) === $fingerprint) {
      return FALSE;
    }
    $this->reset($definitionId);
    $this->configFingerprints->set($definitionId, $fingerprint);
    return TRUE;
  }

}
