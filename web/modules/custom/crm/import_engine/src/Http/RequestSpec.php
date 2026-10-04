<?php

declare(strict_types=1);

namespace Drupal\import_engine\Http;

/**
 * An immutable description of one HTTP request.
 *
 * Authentication and pagination plugins adjust a request by returning a
 * changed copy. Keeping the pieces apart (URL, query, headers, body) lets a
 * paging plugin set "offset=100" as a query parameter for a REST API and as a
 * GraphQL variable in the body for a GraphQL API.
 */
final class RequestSpec {

  /**
   * Constructs a request description.
   *
   * @param string $method
   *   The HTTP method, upper case.
   * @param string $url
   *   The URL without a query string.
   * @param array<string, string|int> $query
   *   The query parameters.
   * @param array<string, string> $headers
   *   The request headers.
   * @param array<string, mixed>|null $body
   *   The JSON body, or NULL for none.
   */
  public function __construct(
    public readonly string $method,
    public readonly string $url,
    public readonly array $query = [],
    public readonly array $headers = [],
    public readonly ?array $body = NULL,
  ) {
  }

  /**
   * Returns a copy with another URL.
   */
  public function withUrl(string $url): self {
    return new self($this->method, $url, $this->query, $this->headers, $this->body);
  }

  /**
   * Returns a copy with a query parameter set.
   */
  public function withQueryParameter(string $name, string|int $value): self {
    return new self($this->method, $this->url, [$name => $value] + $this->query, $this->headers, $this->body);
  }

  /**
   * Returns a copy with all query parameters replaced.
   *
   * @param array<string, string|int> $query
   *   The query parameters.
   */
  public function withQuery(array $query): self {
    return new self($this->method, $this->url, $query, $this->headers, $this->body);
  }

  /**
   * Returns a copy with a header set.
   */
  public function withHeader(string $name, string $value): self {
    return new self($this->method, $this->url, $this->query, [$name => $value] + $this->headers, $this->body);
  }

  /**
   * Returns a copy with another body.
   *
   * @param array<string, mixed>|null $body
   *   The JSON body, or NULL for none.
   */
  public function withBody(?array $body): self {
    return new self($this->method, $this->url, $this->query, $this->headers, $body);
  }

  /**
   * Returns the request options for the Guzzle client.
   *
   * @return array<string, mixed>
   *   Guzzle request options (query, headers and json).
   */
  public function toGuzzleOptions(): array {
    $options = [];
    if ($this->query !== []) {
      $options['query'] = $this->query;
    }
    if ($this->headers !== []) {
      $options['headers'] = $this->headers;
    }
    if ($this->body !== NULL) {
      $options['json'] = $this->body;
    }
    return $options;
  }

}
