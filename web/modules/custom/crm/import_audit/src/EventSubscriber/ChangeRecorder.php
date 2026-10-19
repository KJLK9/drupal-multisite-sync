<?php

declare(strict_types=1);

namespace Drupal\import_audit\EventSubscriber;

use Drupal\import_audit\History\ChangeLog;
use Drupal\import_engine\Event\ItemWritten;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Keeps what the engine says it wrote.
 *
 * Because this class listens, the engine works out the changes of every write.
 * Without the module, nobody listens and it does not.
 */
final class ChangeRecorder implements EventSubscriberInterface {

  /**
   * Constructs the recorder.
   */
  public function __construct(
    private readonly ChangeLog $log,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [ItemWritten::class => 'onWritten'];
  }

  /**
   * Keeps the changes.
   */
  public function onWritten(ItemWritten $event): void {
    $this->log->record($event);
  }

}
