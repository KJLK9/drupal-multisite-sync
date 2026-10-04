<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Kernel;

use Drupal\import_engine\Entity\ImportDefinition;
use Drupal\import_engine\Source\Severity;
use Drupal\import_engine\Source\SourceException;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the GraphQL source, including paging through variables in the body.
 */
#[Group('import_engine')]
#[RunTestsInSeparateProcesses]
class GraphqlSourceTest extends HttpSourceTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    putenv(self::ENV_VAR . '=' . self::KEY);
  }

  /**
   * Returns the settings of a GraphQL source for site A's customers.
   *
   * @param array<string, mixed> $settings
   *   Settings that replace the defaults.
   *
   * @return array<string, mixed>
   *   Settings to pass to source().
   */
  protected function graphql(array $settings = []): array {
    return $settings + [
      'url' => 'https://site-a.test/graphql/catalog',
      'query' => 'query($limit: Int!, $offset: Int!) { customers(limit: $limit, offset: $offset) { totalCount items { id label } } }',
      'variables' => '{"filter": "all"}',
      'headers' => [],
      'items_path' => 'data.customers.items',
      'id_path' => 'id',
      'timeout' => 10,
    ];
  }

  /**
   * Builds the offset and limit plugin that sets GraphQL variables.
   *
   * @return array{plugin: string, configuration: array<string, mixed>}
   *   The pagination of a definition.
   */
  protected function variablePaging(): array {
    return [
      'plugin' => 'offset_limit',
      'configuration' => [
        'target' => 'body',
        'offset_param' => 'variables.offset',
        'limit_param' => 'variables.limit',
        'page_size' => 2,
        'total_path' => 'data.customers.totalCount',
        'stop_on_short_page' => FALSE,
      ],
    ];
  }

  /**
   * Builds a GraphQL response of customers.
   *
   * @return \GuzzleHttp\Psr7\Response
   *   The response.
   */
  protected function customers(int $from, int $to, int $total): Response {
    return $this->json([
      'data' => [
        'customers' => [
          'totalCount' => $total,
          'items' => array_map(static fn (int $id): array => ['id' => (string) $id, 'label' => "Customer $id"], range($from, $to)),
        ],
      ],
    ]);
  }

  /**
   * The query and variables go in a POST body with authentication.
   */
  public function testRequestIsGraphqlPost(): void {
    $this->mockResponses([$this->customers(1, 2, 2)]);

    $page = $this->source($this->graphql(), NULL, NULL, 'graphql')->fetchPage();

    $this->assertCount(2, $page->items);
    $request = $this->request(0);
    $this->assertSame('POST', $request->getMethod());
    $this->assertSame(self::KEY, $request->getHeaderLine('api-key'));
    $this->assertSame('application/json', $request->getHeaderLine('Accept'));
    $body = json_decode((string) $request->getBody(), TRUE);
    $this->assertStringContainsString('customers(limit: $limit', $body['query']);
    $this->assertSame(['filter' => 'all'], $body['variables']);
  }

  /**
   * All pages are read by setting the variables, using the fixture of site A.
   */
  public function testPagesThroughVariables(): void {
    $this->mockResponses([
      $this->customers(1, 2, 5),
      $this->customers(3, 4, 5),
      $this->customers(5, 5, 5),
    ]);

    $items = $this->walk($this->source($this->graphql(), NULL, $this->variablePaging(), 'graphql'));

    $this->assertSame(['1', '2', '3', '4', '5'], array_column($items, 'id'));
    $second = json_decode((string) $this->request(1)->getBody(), TRUE);
    // The paging values are variables; the others stay.
    $this->assertSame(['filter' => 'all', 'limit' => 2, 'offset' => 2], $second['variables']);
    $this->assertSame('', $this->request(1)->getUri()->getQuery());
  }

  /**
   * The recorded response of site A is read through its paths.
   */
  public function testReadsTheRecordedResponseFromSiteA(): void {
    $this->mockResponses([$this->fixtureResponse()]);

    $page = $this->source($this->graphql(), NULL, $this->variablePaging(), 'graphql')->fetchPage();

    $this->assertSame(30, $page->total);
    $this->assertSame('CUST-0001', $page->items[0]['customerNumber']);
    $this->assertSame('3', $page->nextCursor);
  }

  /**
   * An answer of 200 with GraphQL errors is a failure.
   */
  public function testGraphqlErrorsAreFailures(): void {
    $long = str_repeat('x', 500);
    $this->mockResponses([
      $this->json(['errors' => [['message' => 'Cannot query field "nope" on type "Customer".']], 'data' => NULL]),
      $this->json(['errors' => [['message' => $long]]]),
    ]);

    try {
      $this->source($this->graphql(), NULL, NULL, 'graphql')->fetchPage();
      $this->fail('Expected a SourceException.');
    }
    catch (SourceException $exception) {
      $this->assertFalse($exception->retryable);
      $this->assertStringContainsString('GraphQL error: Cannot query field "nope"', $exception->getMessage());
    }

    try {
      $this->source($this->graphql(), NULL, NULL, 'graphql')->fetchPage();
      $this->fail('Expected a SourceException.');
    }
    catch (SourceException $exception) {
      // A very long server message is cut.
      $this->assertLessThan(400, strlen($exception->getMessage()));
    }
  }

  /**
   * HTTP failures are judged like those of the HTTP source.
   */
  public function testHttpFailuresAreJudgedAsForHttp(): void {
    $this->mockResponses([new Response(401, [], 'no'), new Response(502, [], 'bad')]);

    foreach ([FALSE, TRUE] as $retryable) {
      try {
        $this->source($this->graphql(), NULL, NULL, 'graphql')->fetchPage();
        $this->fail('Expected a SourceException.');
      }
      catch (SourceException $exception) {
        $this->assertSame($retryable, $exception->retryable);
      }
    }
  }

  /**
   * Variables that are not a JSON object are refused.
   */
  public function testInvalidVariables(): void {
    $this->mockResponses([]);

    $this->expectException(SourceException::class);
    $this->expectExceptionMessage('variables of the source are not valid JSON');
    $this->source($this->graphql(['variables' => '{broken']), NULL, NULL, 'graphql')->fetchPage();
  }

  /**
   * A check of a GraphQL source pages like any other.
   */
  public function testCheck(): void {
    $this->mockResponses([$this->customers(1, 2, 9), $this->customers(3, 4, 9), $this->customers(5, 6, 9)]);

    $check = $this->source($this->graphql(), NULL, $this->variablePaging(), 'graphql')->check();
    $this->assertTrue($check->isOk());
    $this->assertContains('The source reports 9 items in total.', $check->getMessagesBySeverity(Severity::Info));

    // The server ignores the variables: the same page again.
    $this->history = [];
    $this->mockResponses([$this->customers(1, 2, 9), $this->customers(1, 2, 9)]);
    $ignored = $this->source($this->graphql(), NULL, $this->variablePaging(), 'graphql')->check();
    $this->assertStringContainsString('exactly the same data', $ignored->getMessagesBySeverity(Severity::Error)[0]);
  }

  /**
   * The schema accepts a GraphQL definition and rejects an empty query.
   */
  public function testSchemaValidation(): void {
    $values = [
      'id' => 'customers',
      'label' => 'Customers',
      'source' => ['plugin' => 'graphql', 'configuration' => $this->graphql()],
      'pagination' => $this->variablePaging(),
      'authentication' => [
        'plugin' => 'api_key_header',
        'configuration' => ['header' => 'api-key', 'env_var' => 'SITE_A_API_KEY'],
      ],
      'target' => ['entity_type' => 'node', 'bundle' => 'account'],
    ];
    $this->assertCount(0, ImportDefinition::create($values)->getTypedData()->validate());

    $values['source']['configuration']['query'] = '';
    $values['source']['configuration']['variables'] = '[1, 2]';
    $paths = [];
    foreach (ImportDefinition::create($values)->getTypedData()->validate() as $violation) {
      $paths[] = $violation->getPropertyPath();
    }
    $this->assertContains('source.configuration.query', $paths);
    $this->assertContains('source.configuration.variables', $paths);
  }

}
