<?php

declare(strict_types=1);

namespace Drupal\import_engine\Event;

/**
 * Sent after the engine wrote an item to its target: created or changed.
 *
 * Modules that keep an audit trail listen to this; the engine does not know
 * who does. The changes are only worked out when someone listens (see
 * ProcessStage), so without a listener this costs nothing.
 */
final class ItemWritten {

  /**
   * Constructs the event.
   *
   * @param int $runId
   *   The run.
   * @param string $definitionId
   *   The import.
   * @param string $key
   *   The key of the source item.
   * @param string $targetType
   *   What was written, for an entity its entity type.
   * @param string $targetId
   *   The ID of what was written.
   * @param bool $created
   *   Whether it was created, as opposed to changed.
   * @param array<string, array{old: string, new: string}> $changes
   *   The fields that changed, by field name, with the old and new value as
   *   short text. Empty text is an empty field.
   * @param int $occurred
   *   When, as a timestamp.
   */
  public function __construct(
    public readonly int $runId,
    public readonly string $definitionId,
    public readonly string $key,
    public readonly string $targetType,
    public readonly string $targetId,
    public readonly bool $created,
    public readonly array $changes,
    public readonly int $occurred,
  ) {
  }

}
