<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Kernel;

use Drupal\import_engine\Entity\ImportDefinition;
use Drupal\import_engine\Source\SourceInterface;
use Drupal\KernelTests\KernelTestBase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Base class for tests of sources that talk HTTP, with a mocked client.
 */
abstract class HttpSourceTestBase extends KernelTestBase {

  /**
   * The name of the environment variable that holds the test API key.
   */
  protected const ENV_VAR = 'IMPORT_ENGINE_TEST_API_KEY';

  /**
   * The test API key; checked never to appear in messages.
   */
  protected const KEY = 'k3y-that-must-stay-secret';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'import_engine'];

  /**
   * The requests the source sent.
   *
   * @var list<array{request: \Psr\Http\Message\RequestInterface, options: array<string, mixed>}>
   */
  protected array $history = [];

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv(self::ENV_VAR);
    parent::tearDown();
  }

  /**
   * The key paths of the import under test.
   *
   * @var list<string>
   */
  protected array $sourceKey = ['id'];

  /**
   * Returns a request the source sent.
   */
  protected function request(int $index): RequestInterface {
    return $this->history[$index]['request'];
  }

  /**
   * Replaces the HTTP client with one that answers from a queue.
   *
   * @param array<\Psr\Http\Message\ResponseInterface|\Throwable> $queue
   *   The responses, or exceptions to throw, in order.
   */
  protected function mockResponses(array $queue): void {
    $stack = HandlerStack::create(new MockHandler($queue));
    // Record every request the source sends, with the options it used.
    $stack->push(function (callable $handler): callable {
      return function (RequestInterface $request, array $options) use ($handler) {
        $this->history[] = ['request' => $request, 'options' => $options];
        return $handler($request, $options);
      };
    });
    $this->container->set('http_client', new Client(['handler' => $stack]));
  }

  /**
   * Creates the source of a definition with the given source settings.
   *
   * @param array<string, mixed> $source
   *   Settings that replace the defaults of the HTTP source.
   * @param array<string, mixed>|null $authentication
   *   The authentication plugin and its configuration.
   * @param array<string, mixed>|null $pagination
   *   The pagination plugin and its configuration; none by default.
   * @param string $plugin
   *   The source plugin ID.
   */
  protected function source(array $source = [], ?array $authentication = NULL, ?array $pagination = NULL, string $plugin = 'http'): SourceInterface {
    $definition = ImportDefinition::create([
      'id' => 'customers',
      'label' => 'Customers',
      'pagination' => $pagination ?? ['plugin' => 'none', 'configuration' => []],
      'source' => [
        'plugin' => $plugin,
        'configuration' => $source + [
          'url' => 'https://site-a.test/graphql/catalog',
          'method' => 'POST',
          'headers' => [],
          'query' => [],
          'body' => '{"query": "{ customers { items { id } } }"}',
          'items_path' => 'data.customers.items',
          'timeout' => 10,
          'format' => 'auto',
          'csv_delimiter' => ',',
        ],
      ],
      'source_key' => $this->sourceKey,
      'authentication' => $authentication ?? [
        'plugin' => 'api_key_header',
        'configuration' => ['header' => 'api-key', 'env_var' => self::ENV_VAR],
      ],
      'target' => [
        'plugin' => 'entity',
        'configuration' => ['entity_type' => 'node', 'bundle' => 'account', 'owner' => 0],
      ],
    ]);
    return $this->container->get('import_engine.source_factory')->create($definition);
  }

  /**
   * Returns the recorded response of site A as a response object.
   */
  protected function fixtureResponse(): Response {
    return new Response(200, ['Content-Type' => 'application/json'], (string) file_get_contents(__DIR__ . '/../../fixtures/site_a_customers.json'));
  }

  /**
   * Returns a JSON response.
   *
   * @param array<mixed> $data
   *   The data to encode.
   * @param int $status
   *   The HTTP status code.
   */
  protected function json(array $data, int $status = 200): Response {
    return new Response($status, ['Content-Type' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR));
  }

  /**
   * Returns the query parameters of a request the source sent.
   *
   * @return array<int|string, mixed>
   *   The parsed query string.
   */
  protected function queryOf(int $index): array {
    parse_str($this->request($index)->getUri()->getQuery(), $query);
    return $query;
  }

  /**
   * Reads pages until the source says there are no more, like an extraction.
   *
   * @return list<array<string, mixed>>
   *   All items of all pages.
   */
  protected function walk(SourceInterface $source, int $maxPages = 50): array {
    $items = [];
    $cursor = NULL;
    for ($page = 0; $page < $maxPages; $page++) {
      $result = $source->fetchPage($cursor);
      $items = array_merge($items, $result->items);
      $cursor = $result->nextCursor;
      if ($cursor === NULL) {
        return $items;
      }
    }
    $this->fail('The source did not stop after ' . $maxPages . ' pages.');
  }

}
