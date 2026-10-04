<?php

declare(strict_types=1);

namespace Drupal\import_engine\Source;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Component\Plugin\PluginInspectionInterface;

/**
 * A place the items of an import come from.
 *
 * A source hands out items one page at a time and remembers nothing itself:
 * where it is, is a cursor that the caller keeps and passes back. That keeps
 * extraction resumable, and works for sources with pages (an API) and without
 * (a file).
 */
interface SourceInterface extends PluginInspectionInterface, ConfigurableInterface {

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
   * Checks the source while an import is being set up.
   *
   * Tries to connect and read, and reports what it found as messages and
   * sample items instead of throwing.
   */
  public function check(): SourceCheck;

}
