<?php

declare(strict_types=1);

namespace Drupal\import_engine\Storage;

/**
 * What the latest run that read a page position remembers about it.
 */
final class PageRecord {

  /**
   * Constructs a page record.
   *
   * @param string $fingerprint
   *   The fingerprint of the page, as 32 hex characters.
   * @param list<string> $keys
   *   The keys of the items on the page.
   * @param int $seenRun
   *   The run that last read the page.
   * @param bool $verified
   *   Whether the items of the page were all handled without failures, which
   *   is the condition for skipping the page when it did not change.
   */
  public function __construct(
    public readonly string $fingerprint,
    public readonly array $keys,
    public readonly int $seenRun,
    public readonly bool $verified = FALSE,
  ) {
  }

}
