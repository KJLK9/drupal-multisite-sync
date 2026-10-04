<?php

declare(strict_types=1);

namespace Drupal\import_engine\Plugin\ImportEngine\Pagination;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\import_engine\Attribute\ImportPagination;
use Drupal\import_engine\Http\RequestSpec;
use Drupal\import_engine\Pagination\PaginationPluginBase;
use Drupal\import_engine\Source\SourceException;

/**
 * Pages with an offset and a limit.
 *
 * The cursor is the offset. The next offset is the current one plus the number
 * of items that actually came back, not the requested page size, because a
 * server may return fewer than asked for (a maximum page size).
 *
 * Stop conditions: an empty page, or the total (when "total_path" tells it) is
 * reached. A page shorter than the page size is only taken as the last page
 * when "stop_on_short_page" is on, since a server that caps the limit would
 * otherwise end the import after the first page without any error.
 */
#[ImportPagination(
  id: 'offset_limit',
  label: new TranslatableMarkup('Offset and limit'),
  description: new TranslatableMarkup('Asks for a number of items starting at an offset.'),
)]
final class OffsetLimitPagination extends PaginationPluginBase {

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The default configuration.
   */
  public function defaultConfiguration(): array {
    return [
      'target' => 'query',
      'offset_param' => 'offset',
      'limit_param' => 'limit',
      'page_size' => 50,
      'total_path' => '',
      'stop_on_short_page' => FALSE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function applyCursor(RequestSpec $request, ?string $cursor): RequestSpec {
    $offset = $this->offset($cursor);
    $request = $this->setParameter($request, (string) $this->configuration['limit_param'], (int) $this->configuration['page_size']);
    return $this->setParameter($request, (string) $this->configuration['offset_param'], $offset);
  }

  /**
   * {@inheritdoc}
   */
  public function nextCursor(?string $cursor, array $data, array $items): ?string {
    $count = count($items);
    if ($count === 0) {
      return NULL;
    }
    $next = $this->offset($cursor) + $count;

    $total = $this->total($data);
    if ($total !== NULL) {
      return $next >= $total ? NULL : (string) $next;
    }
    if ($this->configuration['stop_on_short_page'] && $count < (int) $this->configuration['page_size']) {
      return NULL;
    }
    return (string) $next;
  }

  /**
   * Returns the offset a cursor stands for.
   */
  private function offset(?string $cursor): int {
    if ($cursor === NULL) {
      return 0;
    }
    if (!ctype_digit($cursor)) {
      throw SourceException::permanent('The paging position is not a number.');
    }
    return (int) $cursor;
  }

}
