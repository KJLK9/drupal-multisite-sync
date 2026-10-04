<?php

declare(strict_types=1);

namespace Drupal\import_engine\Plugin\ImportEngine\Pagination;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\import_engine\Attribute\ImportPagination;
use Drupal\import_engine\Http\RequestSpec;
use Drupal\import_engine\Pagination\PaginationPluginBase;
use Drupal\import_engine\Source\SourceException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Psr\Http\Message\UriInterface;

/**
 * Follows a link to the next page that the response itself contains.
 *
 * The cursor is that link. Stop condition: the response has no next link.
 *
 * The link comes from the server, so it is not trusted: it must lead to the
 * same scheme, host and port as the configured URL, otherwise the request,
 * which carries the credentials, would be sent to a place we never configured.
 * The link replaces the configured query parameters, as it already holds the
 * ones it needs. A relative link is resolved against the configured URL.
 */
#[ImportPagination(
  id: 'next_url',
  label: new TranslatableMarkup('Next URL in the response'),
  description: new TranslatableMarkup('Follows the link to the next page from the response.'),
)]
final class NextUrlPagination extends PaginationPluginBase {

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The default configuration.
   */
  public function defaultConfiguration(): array {
    return [
      'next_path' => 'links.next',
      'total_path' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function applyCursor(RequestSpec $request, ?string $cursor): RequestSpec {
    if ($cursor === NULL) {
      return $request;
    }
    $base = new Uri($request->url);
    try {
      $next = UriResolver::resolve($base, new Uri($cursor));
    }
    catch (\InvalidArgumentException $exception) {
      throw SourceException::permanent('The link to the next page is not a valid URL.', $exception);
    }
    if ($this->origin($next) !== $this->origin($base)) {
      throw SourceException::permanent('The link to the next page leads to another host than the source; not followed.');
    }
    // The link holds its own query, so the configured one is dropped.
    return $request->withUrl((string) $next)->withQuery([]);
  }

  /**
   * {@inheritdoc}
   */
  public function nextCursor(?string $cursor, array $data, array $items): ?string {
    $link = $this->paths->get($data, (string) $this->configuration['next_path']);
    if ($link === NULL || $link === '') {
      return NULL;
    }
    if (!is_string($link)) {
      throw SourceException::permanent('The link to the next page is not text.');
    }
    if ($link === $cursor) {
      throw SourceException::permanent('The link to the next page is the page that was just read.');
    }
    return $link;
  }

  /**
   * Returns scheme, host and port of a URI, to compare where it points.
   */
  private function origin(UriInterface $uri): string {
    $port = $uri->getPort() ?? ($uri->getScheme() === 'https' ? 443 : 80);
    return strtolower($uri->getScheme()) . '://' . strtolower($uri->getHost()) . ':' . $port;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<string, mixed>
   *   The form.
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['next_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Path of the next page link'),
      '#description' => $this->t('Dotted path in the response of the URL of the next page, for example links.next. It must be on the same server as the source.'),
      '#default_value' => $this->configuration['next_path'],
      '#required' => TRUE,
    ];
    $form['total_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Path of the total'),
      '#description' => $this->t('Dotted path in the response of the total number of items, if it has one.'),
      '#default_value' => $this->configuration['total_path'],
    ];
    return $form;
  }

}
