<?php

declare(strict_types=1);

namespace Drupal\import_engine\Storage;

/**
 * An entry of the event log.
 */
final class EventRecord {

  /**
   * Constructs an event record.
   *
   * @param int $id
   *   The row ID.
   * @param int $occurred
   *   When it happened, as a timestamp.
   * @param int $runId
   *   The run it happened in.
   * @param string $definitionId
   *   The import definition.
   * @param \Drupal\import_engine\Storage\EventType $event
   *   What happened.
   * @param string $key
   *   The key of the source item.
   * @param string|null $target
   *   The target entity, as "type:id", if there is one.
   * @param string|null $message
   *   A message, for example the error.
   */
  public function __construct(
    public readonly int $id,
    public readonly int $occurred,
    public readonly int $runId,
    public readonly string $definitionId,
    public readonly EventType $event,
    public readonly string $key,
    public readonly ?string $target,
    public readonly ?string $message,
  ) {
  }

}
