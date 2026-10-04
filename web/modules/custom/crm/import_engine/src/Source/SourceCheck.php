<?php

declare(strict_types=1);

namespace Drupal\import_engine\Source;

/**
 * The outcome of checking a source while an import is being set up.
 *
 * Unlike a failure during a run, a check does not throw: it collects messages
 * for the person filling in the form, plus sample items to build the field
 * mapping from.
 */
final class SourceCheck {

  /**
   * The messages, in the order they were added.
   *
   * @var list<array{severity: \Drupal\import_engine\Source\Severity, message: string}>
   */
  private array $messages = [];

  /**
   * Constructs a check result.
   *
   * @param list<array<string, mixed>> $sampleItems
   *   A few items from the source.
   */
  public function __construct(
    public array $sampleItems = [],
  ) {
  }

  /**
   * Adds a message.
   */
  public function add(Severity $severity, string $message): self {
    $this->messages[] = ['severity' => $severity, 'message' => $message];
    return $this;
  }

  /**
   * Returns all messages.
   *
   * @return list<array{severity: \Drupal\import_engine\Source\Severity, message: string}>
   *   The messages.
   */
  public function getMessages(): array {
    return $this->messages;
  }

  /**
   * Returns the messages of one severity.
   *
   * @return list<string>
   *   The message texts.
   */
  public function getMessagesBySeverity(Severity $severity): array {
    $texts = [];
    foreach ($this->messages as $entry) {
      if ($entry['severity'] === $severity) {
        $texts[] = $entry['message'];
      }
    }
    return $texts;
  }

  /**
   * Returns whether the source can be used: no error messages.
   */
  public function isOk(): bool {
    return $this->getMessagesBySeverity(Severity::Error) === [];
  }

}
