<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Guarantee;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * A small API for the guarantee tests, in the four ways servers do paging.
 *
 * It answers from a list of items, looking at what the request asks for, and
 * keeps a note of every request, so that a test can say how many calls the
 * engine made and which items it was given.
 */
final class FakeApi {

  /**
   * The base address of the API.
   */
  public const URL = 'https://api.test/items';

  /**
   * The requests that were made: query, headers and the items that were sent.
   *
   * @var list<array{query: array<mixed>, headers: array<mixed>, sent: list<int>}>
   */
  public array $requests = [];

  /**
   * Constructs the API.
   *
   * @param list<array<string, mixed>> $items
   *   The items it has.
   * @param string $style
   *   How it pages: none, offset, page, next_url or stuck (a server that
   *   ignores the paging and always sends the first page).
   * @param int $pageSize
   *   How many items it sends at a time.
   * @param bool $tells
   *   Whether it says in its answer how much there is: the total for offset,
   *   the number of pages for page.
   * @param int|null $claimedTotal
   *   A total to claim instead of the true one, for a server that is wrong.
   * @param string|null $requiresKey
   *   The API key (in the header api-key) the server wants; any other gets 401.
   */
  public function __construct(
    private readonly array $items,
    private readonly string $style,
    private readonly int $pageSize = 10,
    private readonly bool $tells = TRUE,
    private readonly ?int $claimedTotal = NULL,
    private readonly ?string $requiresKey = NULL,
  ) {
  }

  /**
   * Returns an HTTP client that talks to this API.
   */
  public function client(): Client {
    return new Client(['handler' => HandlerStack::create(fn (RequestInterface $request): mixed => Create::promiseFor($this->respond($request)))]);
  }

  /**
   * Answers a request.
   */
  private function respond(RequestInterface $request): Response {
    parse_str($request->getUri()->getQuery(), $query);
    $query = array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $query);
    if ($this->requiresKey !== NULL && $request->getHeaderLine('api-key') !== $this->requiresKey) {
      $this->requests[] = ['query' => $query, 'headers' => [], 'sent' => []];
      return new Response(401, ['Content-Type' => 'application/json'], '{"error":"no"}');
    }
    $total = count($this->items);
    $body = ['data' => [], 'meta' => [], 'links' => ['next' => NULL]];

    switch ($this->style) {
      case 'none':
        $slice = $this->items;
        break;

      case 'offset':
        $limit = (int) ($query['limit'] ?? $this->pageSize);
        $offset = (int) ($query['offset'] ?? 0);
        $slice = array_slice($this->items, $offset, $limit);
        if ($this->tells) {
          $body['meta']['total'] = $this->claimedTotal ?? $total;
        }
        break;

      case 'page':
        $page = (int) ($query['page'] ?? 1);
        $slice = array_slice($this->items, ($page - 1) * $this->pageSize, $this->pageSize);
        if ($this->tells) {
          $body['meta']['pages'] = max(1, (int) ceil($total / $this->pageSize));
        }
        break;

      case 'next_url':
        $from = (int) ($query['cursor'] ?? 0);
        $slice = array_slice($this->items, $from, $this->pageSize);
        if ($from + $this->pageSize < $total) {
          $body['links']['next'] = self::URL . '?cursor=' . ($from + $this->pageSize);
        }
        break;

      default:
        $slice = array_slice($this->items, 0, $this->pageSize);
    }

    $body['data'] = array_values($slice);
    $this->requests[] = [
      'query' => $query,
      'headers' => array_map(static fn (array $values): string => implode(',', $values), $request->getHeaders()),
      'sent' => array_map(static fn (array $item): int => (int) $item['id'], $slice),
    ];
    return new Response(200, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
  }

  /**
   * Returns the IDs of the items that were sent, in order, over all requests.
   *
   * @return list<int>
   *   The IDs.
   */
  public function sentIds(): array {
    return array_merge(...array_column($this->requests, 'sent') ?: [[]]);
  }

}
