<?php

declare(strict_types=1);

namespace Drupal\import_engine\Storage;

/**
 * A known source item: which target entity it became, and when it was seen.
 */
final class MappingRecord {

  /**
   * Constructs a mapping record.
   *
   * @param string $key
   *   The key of the source item.
   * @param string|null $targetType
   *   The entity type of the target entity.
   * @param string|null $targetId
   *   The ID of the target entity.
   * @param string|null $hash
   *   The hash of the mapped data in hex, to see whether an item changed.
   * @param int $firstSeenRun
   *   The run that first saw the item.
   * @param int $lastSeenRun
   *   The run that last saw the item.
   * @param int $lastChangedRun
   *   The run that last changed the target.
   * @param bool $gone
   *   Whether the item went missing in the source and was unpublished.
   */
  public function __construct(
    public readonly string $key,
    public readonly ?string $targetType,
    public readonly ?string $targetId,
    public readonly ?string $hash,
    public readonly int $firstSeenRun,
    public readonly int $lastSeenRun,
    public readonly int $lastChangedRun,
    public readonly bool $gone = FALSE,
  ) {
  }

}
