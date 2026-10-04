<?php

declare(strict_types=1);

namespace Drupal\import_engine\Plugin\ImportEngine\Pagination;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\import_engine\Attribute\ImportPagination;
use Drupal\import_engine\Http\RequestSpec;
use Drupal\import_engine\Pagination\PaginationPluginBase;
use Drupal\import_engine\Source\SourceException;

/**
 * Pages by page number.
 *
 * The cursor is the number of the next page to ask for. Stop conditions: an
 * empty page, or the last page (when "last_page_path" tells which that is) was
 * read. The page size is only sent when "size_param" is set.
 */
#[ImportPagination(
  id: 'page',
  label: new TranslatableMarkup('Page number'),
  description: new TranslatableMarkup('Asks for page 1, 2, 3 and so on.'),
)]
final class PagePagination extends PaginationPluginBase {

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The default configuration.
   */
  public function defaultConfiguration(): array {
    return [
      'target' => 'query',
      'page_param' => 'page',
      'size_param' => '',
      'page_size' => 50,
      'first_page' => 1,
      'total_path' => '',
      'last_page_path' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function applyCursor(RequestSpec $request, ?string $cursor): RequestSpec {
    if ((string) $this->configuration['size_param'] !== '') {
      $request = $this->setParameter($request, (string) $this->configuration['size_param'], (int) $this->configuration['page_size']);
    }
    return $this->setParameter($request, (string) $this->configuration['page_param'], $this->page($cursor));
  }

  /**
   * {@inheritdoc}
   */
  public function nextCursor(?string $cursor, array $data, array $items): ?string {
    if ($items === []) {
      return NULL;
    }
    $page = $this->page($cursor);

    $last_path = (string) $this->configuration['last_page_path'];
    if ($last_path !== '' && $this->paths->has($data, $last_path)) {
      $last = $this->paths->get($data, $last_path);
      if (is_numeric($last) && $page >= (int) $last) {
        return NULL;
      }
    }
    return (string) ($page + 1);
  }

  /**
   * Returns the page number a cursor stands for.
   */
  private function page(?string $cursor): int {
    if ($cursor === NULL) {
      return (int) $this->configuration['first_page'];
    }
    if (!ctype_digit($cursor)) {
      throw SourceException::permanent('The paging position is not a number.');
    }
    return (int) $cursor;
  }

}
