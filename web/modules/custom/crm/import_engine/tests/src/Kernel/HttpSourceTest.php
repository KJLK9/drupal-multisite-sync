<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Kernel;

use Drupal\import_engine\Source\Severity;
use Drupal\import_engine\Source\SourceException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the HTTP source and its authentication with a mocked HTTP client.
 */
#[Group('import_engine')]
#[RunTestsInSeparateProcesses]
class HttpSourceTest extends HttpSourceTestBase {

  /**
   * The plugins are discovered.
   */
  public function testPluginsAreDiscovered(): void {
    $sources = array_keys($this->container->get('plugin.manager.import_engine_source')->getDefinitions());
    $authentication = array_keys($this->container->get('plugin.manager.import_engine_authentication')->getDefinitions());

    $pagination = array_keys($this->container->get('plugin.manager.import_engine_pagination')->getDefinitions());

    $this->assertEqualsCanonicalizing(['http', 'graphql'], $sources);
    $this->assertEqualsCanonicalizing(['none', 'api_key_header'], $authentication);
    $this->assertEqualsCanonicalizing(['none', 'offset_limit', 'page', 'next_url'], $pagination);
  }

  /**
   * A page of site A is fetched with the request the definition describes.
   */
  public function testFetchPageFromSiteA(): void {
    putenv(self::ENV_VAR . '=' . self::KEY);
    $this->mockResponses([$this->fixtureResponse()]);

    $page = $this->source()->fetchPage();

    $this->assertCount(3, $page->items);
    $this->assertSame('Customer 1', $page->items[0]['label']);
    $this->assertSame('CUST-0001', $page->items[0]['customerNumber']);
    // No paging yet: everything is one page.
    $this->assertNull($page->nextCursor);

    $this->assertCount(1, $this->history);
    $request = $this->request(0);
    $this->assertSame('POST', $request->getMethod());
    $this->assertSame('https://site-a.test/graphql/catalog', (string) $request->getUri());
    $this->assertSame(self::KEY, $request->getHeaderLine('api-key'));
    $this->assertSame('application/json', $request->getHeaderLine('Accept'));
    $this->assertSame(['query' => '{ customers { items { id } } }'], json_decode((string) $request->getBody(), TRUE));
    $this->assertSame(10, $this->history[0]['options']['timeout']);
  }

  /**
   * A check reports success with sample items.
   */
  public function testCheckSucceeds(): void {
    putenv(self::ENV_VAR . '=' . self::KEY);
    $this->mockResponses([$this->fixtureResponse()]);

    $check = $this->source()->check();

    $this->assertTrue($check->isOk());
    $this->assertCount(3, $check->sampleItems);
    $this->assertSame(['Connected: the first page holds 3 items.'], $check->getMessagesBySeverity(Severity::Info));
  }

  /**
   * Responses that are worth retrying are transient, others permanent.
   */
  #[DataProvider('statusProvider')]
  public function testHttpStatusDecidesRetryability(int $status, bool $retryable): void {
    putenv(self::ENV_VAR . '=' . self::KEY);
    $this->mockResponses([new Response($status, [], 'denied')]);

    try {
      $this->source()->fetchPage();
      $this->fail('Expected a SourceException.');
    }
    catch (SourceException $exception) {
      $this->assertSame($retryable, $exception->retryable);
      $this->assertStringContainsString('HTTP ' . $status, $exception->getMessage());
      $this->assertStringNotContainsString(self::KEY, $exception->getMessage());
    }
  }

  /**
   * Data provider.
   *
   * @return array<string, array{int, bool}>
   *   The status code and whether retrying can help.
   */
  public static function statusProvider(): array {
    return [
      'unauthorized' => [401, FALSE],
      'forbidden' => [403, FALSE],
      'not found' => [404, FALSE],
      'request timeout' => [408, TRUE],
      'too many requests' => [429, TRUE],
      'server error' => [500, TRUE],
      'bad gateway' => [502, TRUE],
      'unavailable' => [503, TRUE],
    ];
  }

  /**
   * A failed connection or timeout is transient, and a check says so.
   */
  public function testConnectionFailureIsTransient(): void {
    putenv(self::ENV_VAR . '=' . self::KEY);
    $failure = new ConnectException('cURL error 28: timed out for https://site-a.test/?api-key=' . self::KEY, new Request('POST', 'https://site-a.test'));
    $this->mockResponses([$failure, $failure]);

    try {
      $this->source()->fetchPage();
      $this->fail('Expected a SourceException.');
    }
    catch (SourceException $exception) {
      $this->assertTrue($exception->retryable);
      // The low level message can echo the request; it is not passed on.
      $this->assertStringNotContainsString(self::KEY, $exception->getMessage());
    }

    $check = $this->source()->check();
    $this->assertFalse($check->isOk());
    $this->assertStringStartsWith('Temporary problem, try again:', $check->getMessagesBySeverity(Severity::Error)[0]);
  }

  /**
   * A response that is not what was configured is a permanent failure.
   */
  public function testMalformedResponsesArePermanent(): void {
    putenv(self::ENV_VAR . '=' . self::KEY);
    $json = ['Content-Type' => 'application/json'];
    $this->mockResponses([
      new Response(200, $json, '{"data": '),
      new Response(200, $json, '{"data": {"customers": "none"}}'),
      new Response(200, $json, '{"data": {}}'),
    ]);

    foreach (['not valid JSON', 'no list of items', 'no list of items'] as $expected) {
      try {
        $this->source()->fetchPage();
        $this->fail('Expected a SourceException.');
      }
      catch (SourceException $exception) {
        $this->assertFalse($exception->retryable);
        $this->assertStringContainsString($expected, $exception->getMessage());
      }
    }
  }

  /**
   * A missing secret is reported by name, without a value.
   */
  public function testMissingSecret(): void {
    $this->mockResponses([$this->fixtureResponse()]);

    try {
      $this->source()->fetchPage();
      $this->fail('Expected a SourceException.');
    }
    catch (SourceException $exception) {
      $this->assertFalse($exception->retryable);
      $this->assertStringContainsString(self::ENV_VAR, $exception->getMessage());
    }
    // Nothing was sent.
    $this->assertSame([], $this->history);
  }

  /**
   * Without authentication no credentials are sent.
   */
  public function testNoAuthentication(): void {
    $this->mockResponses([$this->fixtureResponse()]);

    $this->source([], ['plugin' => 'none', 'configuration' => []])->fetchPage();

    $this->assertFalse($this->request(0)->hasHeader('api-key'));
  }

  /**
   * A check finds items without an id and ids that repeat.
   */
  public function testCheckValidatesIds(): void {
    putenv(self::ENV_VAR . '=' . self::KEY);
    $json = ['Content-Type' => 'application/json'];
    $this->mockResponses([
      new Response(200, $json, '{"data": {"customers": {"items": [{"id": "1"}, {"name": "no id"}, {"id": ""}]}}}'),
      new Response(200, $json, '{"data": {"customers": {"items": [{"id": "1"}, {"id": "1"}]}}}'),
      new Response(200, $json, '{"data": {"customers": {"items": []}}}'),
    ]);

    $missing = $this->source()->check();
    $this->assertFalse($missing->isOk());
    $this->assertSame(
      ['Item 2: no value at "id".', 'Item 3: the value at "id" is empty.'],
      $missing->getMessagesBySeverity(Severity::Error),
    );

    $duplicate = $this->source()->check();
    $this->assertFalse($duplicate->isOk());
    $this->assertStringContainsString('not unique', $duplicate->getMessagesBySeverity(Severity::Error)[0]);

    $empty = $this->source()->check();
    $this->assertTrue($empty->isOk());
    $this->assertCount(1, $empty->getMessagesBySeverity(Severity::Warning));
  }

  /**
   * XML and CSV responses are read like JSON.
   */
  public function testXmlAndCsvSources(): void {
    putenv(self::ENV_VAR . '=' . self::KEY);
    $this->mockResponses([
      new Response(200, ['Content-Type' => 'application/xml'], '<customers><customer><id>1</id><label>ACME</label></customer><customer><id>2</id><label>Globex</label></customer></customers>'),
      new Response(200, ['Content-Type' => 'application/xml'], '<customers><customer><id>7</id><label>Only</label></customer></customers>'),
      new Response(200, ['Content-Type' => 'text/csv'], "id,label\n1,ACME\n2,Globex\n"),
    ]);

    $xml = $this->source(['method' => 'GET', 'body' => '', 'items_path' => 'customers.customer'])->fetchPage();
    $this->assertSame([['id' => '1', 'label' => 'ACME'], ['id' => '2', 'label' => 'Globex']], $xml->items);

    // A single element is still a list of one item.
    $single = $this->source(['method' => 'GET', 'body' => '', 'items_path' => 'customers.customer'])->fetchPage();
    $this->assertSame([['id' => '7', 'label' => 'Only']], $single->items);

    $csv = $this->source(['method' => 'GET', 'body' => '', 'items_path' => ''])->fetchPage();
    $this->assertSame([['id' => '1', 'label' => 'ACME'], ['id' => '2', 'label' => 'Globex']], $csv->items);
    // With format "auto" the source still asks for JSON first.
    $this->assertSame('application/json', $this->request(0)->getHeaderLine('Accept'));
  }

  /**
   * Query parameters and custom headers are sent; the Accept header can differ.
   */
  public function testQueryAndHeadersAreSent(): void {
    putenv(self::ENV_VAR . '=' . self::KEY);
    $this->mockResponses([new Response(200, ['Content-Type' => 'application/json'], '{"rows": [{"id": 1}]}')]);

    $this->source([
      'method' => 'GET',
      'body' => '',
      'query' => ['limit' => '50', 'status' => 'active'],
      'headers' => ['Accept' => 'application/vnd.api+json', 'X-Trace' => 'abc'],
      'items_path' => 'rows',
    ])->fetchPage();

    $request = $this->request(0);
    $this->assertSame('limit=50&status=active', $request->getUri()->getQuery());
    $this->assertSame('application/vnd.api+json', $request->getHeaderLine('Accept'));
    $this->assertSame('abc', $request->getHeaderLine('X-Trace'));
  }

  /**
   * Without paging there is no second page to ask for.
   */
  public function testNoSecondPageWithoutPaging(): void {
    putenv(self::ENV_VAR . '=' . self::KEY);
    $this->mockResponses([]);

    $this->expectException(SourceException::class);
    $this->source()->fetchPage('100');
  }

  /**
   * A key made of several values is unique as a whole, not per value.
   */
  public function testCheckWithCompositeKey(): void {
    putenv(self::ENV_VAR . '=' . self::KEY);
    $json = ['Content-Type' => 'application/json'];
    $this->sourceKey = ['code', 'site'];
    $this->mockResponses([
      new Response(200, $json, '{"data": {"customers": {"items": [{"code": "1", "site": "a"}, {"code": "1", "site": "b"}, {"code": 2, "site": "a"}]}}}'),
      new Response(200, $json, '{"data": {"customers": {"items": [{"code": "1", "site": "a"}, {"code": "1", "site": "a"}]}}}'),
      new Response(200, $json, '{"data": {"customers": {"items": [{"code": "1"}]}}}'),
    ]);

    $this->assertTrue($this->source()->check()->isOk());

    $duplicate = $this->source()->check();
    $this->assertStringContainsString('not unique', $duplicate->getMessagesBySeverity(Severity::Error)[0]);

    $missing = $this->source()->check();
    $this->assertSame(['Item 1: no value at "site".'], $missing->getMessagesBySeverity(Severity::Error));
  }

}
