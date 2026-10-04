<?php

declare(strict_types=1);

namespace Drupal\import_engine\Extract;

/**
 * The outcome of one call of the extract stage.
 */
final class ExtractResult {

  /**
   * Constructs a result.
   *
   * @param \Drupal\import_engine\Extract\ExtractStatus $status
   *   How the call ended.
   * @param int $pages
   *   The pages read in this call.
   * @param int $queued
   *   The items added to the work queue in this call.
   * @param int $skipped
   *   The items on unchanged pages that were not queued.
   * @param int $invalid
   *   The items without a usable key.
   * @param string|null $message
   *   Why the call ended, when it did not complete.
   */
  public function __construct(
    public readonly ExtractStatus $status,
    public readonly int $pages = 0,
    public readonly int $queued = 0,
    public readonly int $skipped = 0,
    public readonly int $invalid = 0,
    public readonly ?string $message = NULL,
  ) {
  }

}
