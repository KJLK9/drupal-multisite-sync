<?php

declare(strict_types=1);

namespace Drupal\import_engine\Plugin\ImportEngine\Source;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\import_engine\Attribute\ImportSource;
use Drupal\import_engine\Http\RequestSpec;
use Drupal\import_engine\Source\SourceException;

/**
 * Reads items from a GraphQL endpoint.
 *
 * It shares everything with the HTTP source (authentication, paging, decoding,
 * checks) and differs in how the request is described and in what counts as a
 * failure: a GraphQL server answers 200 even when the query failed, with the
 * problem in an "errors" list.
 *
 * Configuration: url, query (the GraphQL document), variables (a JSON object
 * as text), headers, items_path and timeout. Paging plugins with
 * target "body" set variables, for example "variables.offset".
 */
#[ImportSource(
  id: 'graphql',
  label: new TranslatableMarkup('GraphQL'),
  description: new TranslatableMarkup('Reads items from a GraphQL endpoint.'),
)]
final class GraphqlSource extends HttpSource {

  /**
   * The longest GraphQL error message that is passed on.
   */
  private const MAX_ERROR_LENGTH = 200;

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The default configuration.
   */
  public function defaultConfiguration(): array {
    return [
      'url' => '',
      'query' => '',
      'variables' => '',
      'headers' => [],
      'items_path' => 'data',
      'timeout' => 30,
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function baseRequest(): RequestSpec {
    $variables = [];
    if (is_string($this->configuration['variables']) && trim($this->configuration['variables']) !== '') {
      $variables = json_decode($this->configuration['variables'], TRUE);
      if (!is_array($variables)) {
        throw SourceException::permanent('The variables of the source are not valid JSON.');
      }
    }
    $body = ['query' => (string) $this->configuration['query']];
    if ($variables !== []) {
      $body['variables'] = $variables;
    }
    $headers = $this->configuration['headers'] + ['Accept' => 'application/json'];
    return new RequestSpec('POST', (string) $this->configuration['url'], [], $headers, $body);
  }

  /**
   * {@inheritdoc}
   */
  protected function responseFormat(): string {
    return 'json';
  }

  /**
   * {@inheritdoc}
   */
  protected function csvDelimiter(): string {
    return ',';
  }

  /**
   * {@inheritdoc}
   */
  protected function assertValidResponse(array $data, string $label): void {
    $errors = $data['errors'] ?? NULL;
    if (!is_array($errors) || $errors === []) {
      return;
    }
    $first = $errors[0];
    $message = is_array($first) && isset($first['message']) && is_string($first['message']) ? $first['message'] : 'unknown error';
    throw SourceException::permanent(sprintf('%s: GraphQL error: %s', $label, mb_substr($message, 0, self::MAX_ERROR_LENGTH)));
  }

}
