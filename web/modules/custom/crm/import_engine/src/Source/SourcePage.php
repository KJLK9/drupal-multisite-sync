<?php

declare(strict_types=1);

namespace Drupal\import_engine\Source;

/**
 * One page of items from a source.
 */
final class SourcePage {

  /**
   * Constructs a source page.
   *
   * @param list<array<string, mixed>> $items
   *   The source items on this page.
   * @param string|null $nextCursor
   *   An opaque value that makes the source return the next page, or NULL
   *   when this was the last page. It is stored on the run, so extraction can
   *   resume where it stopped.
   * @param int|null $total
   *   The total number of items in the source, when the source tells.
   */
  public function __construct(
    public readonly array $items,
    public readonly ?string $nextCursor = NULL,
    public readonly ?int $total = NULL,
  ) {
  }

}
