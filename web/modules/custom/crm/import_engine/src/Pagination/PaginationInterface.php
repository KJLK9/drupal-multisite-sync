<?php

declare(strict_types=1);

namespace Drupal\import_engine\Pagination;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\import_engine\Http\RequestSpec;

/**
 * Steps a request-based source through its pages.
 *
 * A pagination plugin keeps no state. Where it is, is the cursor: NULL for the
 * first page, otherwise the value an earlier call returned from nextCursor().
 */
interface PaginationInterface extends PluginInspectionInterface, ConfigurableInterface {

  /**
   * Returns a copy of the request that asks for the page at the cursor.
   *
   * @param \Drupal\import_engine\Http\RequestSpec $request
   *   The request for the first page.
   * @param string|null $cursor
   *   NULL for the first page.
   *
   * @throws \Drupal\import_engine\Source\SourceException
   *   When the cursor is not valid for this plugin or leads somewhere it must
   *   not.
   */
  public function applyCursor(RequestSpec $request, ?string $cursor): RequestSpec;

  /**
   * Returns the cursor of the next page, or NULL when this was the last.
   *
   * @param string|null $cursor
   *   The cursor of the page that was just read.
   * @param array<mixed> $data
   *   The decoded response of that page.
   * @param list<array<string, mixed>> $items
   *   The items on that page.
   *
   * @throws \Drupal\import_engine\Source\SourceException
   *   When the response makes no sense, for example a next cursor that does
   *   not move forward.
   */
  public function nextCursor(?string $cursor, array $data, array $items): ?string;

  /**
   * Returns the total number of items in the source, when the response tells.
   *
   * @param array<mixed> $data
   *   The decoded response.
   */
  public function total(array $data): ?int;

}
