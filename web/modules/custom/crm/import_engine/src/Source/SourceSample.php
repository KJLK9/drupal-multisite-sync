<?php

declare(strict_types=1);

namespace Drupal\import_engine\Source;

/**
 * What trying a source gave: messages, and the paths found in sample items.
 *
 * It holds plain values only, so it can be kept in a form state.
 */
final class SourceSample {

  /**
   * Constructs the sample.
   *
   * @param list<array{severity: string, message: string}> $messages
   *   What the check found, by severity (error, warning or info).
   * @param array<string, array{type: string, example: string}> $paths
   *   The dotted paths found in the sample items, with the type of the value
   *   and a short example.
   * @param int $items
   *   How many sample items were read.
   */
  public function __construct(
    public readonly array $messages,
    public readonly array $paths,
    public readonly int $items,
  ) {
  }

  /**
   * Returns whether the source can be used: no error messages.
   */
  public function isOk(): bool {
    foreach ($this->messages as $message) {
      if ($message['severity'] === Severity::Error->value) {
        return FALSE;
      }
    }
    return TRUE;
  }

}
