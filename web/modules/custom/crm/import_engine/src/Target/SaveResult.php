<?php

declare(strict_types=1);

namespace Drupal\import_engine\Target;

/**
 * The result of writing an item to a target.
 */
final class SaveResult {

  /**
   * Constructs a result.
   *
   * @param string $type
   *   The kind of thing written, for an entity its entity type.
   * @param string $id
   *   The ID of what was written.
   * @param bool $created
   *   Whether it was created, as opposed to updated.
   * @param array<string, array{old: string, new: string}> $changes
   *   The fields that changed, as short text; only filled when the caller
   *   asked for it.
   */
  public function __construct(
    public readonly string $type,
    public readonly string $id,
    public readonly bool $created,
    public readonly array $changes = [],
  ) {
  }

}
