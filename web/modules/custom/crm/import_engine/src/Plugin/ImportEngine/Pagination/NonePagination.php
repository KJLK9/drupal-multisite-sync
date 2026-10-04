<?php

declare(strict_types=1);

namespace Drupal\import_engine\Plugin\ImportEngine\Pagination;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\import_engine\Attribute\ImportPagination;
use Drupal\import_engine\Http\RequestSpec;
use Drupal\import_engine\Pagination\PaginationPluginBase;
use Drupal\import_engine\Source\SourceException;

/**
 * The source returns everything in one response.
 */
#[ImportPagination(
  id: 'none',
  label: new TranslatableMarkup('None'),
  description: new TranslatableMarkup('The source returns all items at once.'),
)]
final class NonePagination extends PaginationPluginBase {

  /**
   * {@inheritdoc}
   */
  public function applyCursor(RequestSpec $request, ?string $cursor): RequestSpec {
    if ($cursor !== NULL) {
      throw SourceException::permanent('A source without paging has no second page.');
    }
    return $request;
  }

  /**
   * {@inheritdoc}
   */
  public function nextCursor(?string $cursor, array $data, array $items): ?string {
    return NULL;
  }

}
