<?php

declare(strict_types=1);

namespace Drupal\import_engine\Source;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Plugin\PluginFormInterface;

/**
 * A place the items of an import come from.
 *
 * A source hands out items one page at a time and remembers nothing itself:
 * where it is, is a cursor that the caller keeps and passes back. That keeps
 * extraction resumable, and works for sources with pages (an API) and without
 * (a file).
 */
interface SourceInterface extends PluginInspectionInterface, ConfigurableInterface, PluginFormInterface {

  /**
   * Fetches one page of items during a run.
   *
   * @param string|null $cursor
   *   NULL for the first page, otherwise the next cursor of the previous page.
   *
   * @throws \Drupal\import_engine\Source\SourceException
   *   When fetching fails; the exception says whether retrying can help.
   */
  public function fetchPage(?string $cursor = NULL): SourcePage;

  /**
   * Returns what the circuit breaker is kept per: the server this talks to.
   *
   * Imports that read from the same server share one breaker, so an outage
   * is noticed once and none of them keeps hammering it.
   */
  public function getEndpoint(): string;

  /**
   * Checks that the source answers, cheaply.
   *
   * Used by the circuit breaker to find out whether a source that was down is
   * back. It does not return data.
   *
   * @throws \Drupal\import_engine\Source\SourceException
   *   When the source does not answer properly. A transient one means it is
   *   still down; a permanent one means it answered, but with an error that
   *   waiting does not fix (for example bad credentials).
   */
  public function probe(): void;

  /**
   * Checks the source while an import is being set up.
   *
   * Tries to connect and read, and reports what it found as messages and
   * sample items instead of throwing.
   */
  public function check(): SourceCheck;

}
