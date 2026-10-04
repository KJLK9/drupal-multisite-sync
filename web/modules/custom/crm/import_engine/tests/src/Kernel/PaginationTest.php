<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Kernel;

use Drupal\import_engine\Source\Severity;
use Drupal\import_engine\Source\SourceException;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the pagination plugins through the HTTP source.
 */
#[Group('import_engine')]
#[RunTestsInSeparateProcesses]
class PaginationTest extends HttpSourceTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    putenv(self::ENV_VAR . '=' . self::KEY);
  }

  /**
   * Returns the settings of a plain REST source returning rows at "rows".
   *
   * @return array<string, mixed>
   *   Settings to pass to source().
   */
  protected function rest(): array {
    return ['method' => 'GET', 'body' => '', 'items_path' => 'rows'];
  }

  /**
   * Returns rows with consecutive ids.
   *
   * @return list<array{id: int}>
   *   The rows.
   */
  protected function rows(int $from, int $to): array {
    return array_map(static fn (int $id): array => ['id' => $id], range($from, $to));
  }

  /**
   * Builds the offset and limit plugin with the given settings.
   *
   * @param array<string, mixed> $settings
   *   Settings that replace the defaults.
   *
   * @return array{plugin: string, configuration: array<string, mixed>}
   *   The pagination of a definition.
   */
  protected function offsetLimit(array $settings = []): array {
    return [
      'plugin' => 'offset_limit',
      'configuration' => $settings + [
        'target' => 'query',
        'offset_param' => 'offset',
        'limit_param' => 'limit',
        'page_size' => 2,
        'total_path' => '',
        'stop_on_short_page' => FALSE,
      ],
    ];
  }

  /**
   * Walks every page by offset until the total is reached.
   */
  public function testOffsetLimitStopsAtTheTotal(): void {
    $this->mockResponses([
      $this->json(['rows' => $this->rows(1, 2), 'meta' => ['total' => 5]]),
      $this->json(['rows' => $this->rows(3, 4), 'meta' => ['total' => 5]]),
      $this->json(['rows' => $this->rows(5, 5), 'meta' => ['total' => 5]]),
    ]);

    $items = $this->walk($this->source($this->rest(), NULL, $this->offsetLimit(['total_path' => 'meta.total'])));

    $this->assertSame(range(1, 5), array_column($items, 'id'));
    $this->assertCount(3, $this->history);
    $this->assertSame(['offset' => '0', 'limit' => '2'], $this->queryOf(0));
    $this->assertSame(['offset' => '2', 'limit' => '2'], $this->queryOf(1));
    $this->assertSame(['offset' => '4', 'limit' => '2'], $this->queryOf(2));
  }

  /**
   * The total is reported on the page.
   */
  public function testTotalIsReported(): void {
    $this->mockResponses([$this->json(['rows' => $this->rows(1, 2), 'meta' => ['total' => 5]])]);

    $page = $this->source($this->rest(), NULL, $this->offsetLimit(['total_path' => 'meta.total']))->fetchPage();

    $this->assertSame(5, $page->total);
    $this->assertSame('2', $page->nextCursor);
  }

  /**
   * Without a total the source reads on until it gets an empty page.
   */
  public function testOffsetLimitStopsOnAnEmptyPage(): void {
    $this->mockResponses([
      $this->json(['rows' => $this->rows(1, 2)]),
      $this->json(['rows' => $this->rows(3, 3)]),
      $this->json(['rows' => []]),
    ]);

    $items = $this->walk($this->source($this->rest(), NULL, $this->offsetLimit()));

    $this->assertSame([1, 2, 3], array_column($items, 'id'));
    // The short second page is not taken for the last one.
    $this->assertCount(3, $this->history);
  }

  /**
   * A server that caps the page size must not end the import early.
   */
  public function testCappedPageSizeDoesNotStopEarly(): void {
    // 200 items per page are asked for, the server gives 2.
    $pagination = $this->offsetLimit(['page_size' => 200, 'total_path' => 'meta.total']);
    $this->mockResponses([
      $this->json(['rows' => $this->rows(1, 2), 'meta' => ['total' => 5]]),
      $this->json(['rows' => $this->rows(3, 4), 'meta' => ['total' => 5]]),
      $this->json(['rows' => $this->rows(5, 5), 'meta' => ['total' => 5]]),
    ]);

    $items = $this->walk($this->source($this->rest(), NULL, $pagination));

    $this->assertSame(range(1, 5), array_column($items, 'id'));
    // The next offset follows what came back, not what was asked.
    $this->assertSame('2', $this->queryOf(1)['offset']);
  }

  /**
   * A short page can be taken as the last one, when asked for.
   */
  public function testStopOnShortPage(): void {
    $this->mockResponses([
      $this->json(['rows' => $this->rows(1, 2)]),
      $this->json(['rows' => $this->rows(3, 3)]),
    ]);

    $items = $this->walk($this->source($this->rest(), NULL, $this->offsetLimit(['stop_on_short_page' => TRUE])));

    $this->assertSame([1, 2, 3], array_column($items, 'id'));
    $this->assertCount(2, $this->history);
  }

  /**
   * With the body as target, the values become variables in the JSON body.
   */
  public function testOffsetLimitInTheBody(): void {
    $pagination = $this->offsetLimit([
      'target' => 'body',
      'offset_param' => 'variables.offset',
      'limit_param' => 'variables.limit',
    ]);
    $this->mockResponses([
      $this->json(['rows' => $this->rows(1, 2)]),
      $this->json(['rows' => []]),
    ]);

    $this->walk($this->source(['method' => 'POST', 'body' => '{"query": "{ x }", "variables": {"keep": "me"}}'] + $this->rest(), NULL, $pagination));

    $first = json_decode((string) $this->request(0)->getBody(), TRUE);
    $second = json_decode((string) $this->request(1)->getBody(), TRUE);
    // The order of keys in a request does not matter.
    $this->assertEquals(['query' => '{ x }', 'variables' => ['keep' => 'me', 'offset' => 0, 'limit' => 2]], $first);
    $this->assertSame(2, $second['variables']['offset']);
    // Nothing was put in the query string.
    $this->assertSame('', $this->request(0)->getUri()->getQuery());
  }

  /**
   * A cursor that is not a number is refused.
   */
  public function testInvalidOffsetCursor(): void {
    $this->mockResponses([]);

    $this->expectException(SourceException::class);
    $this->source($this->rest(), NULL, $this->offsetLimit())->fetchPage('abc');
  }

  /**
   * Page numbers start where configured and stop at the last page.
   */
  public function testPageNumbers(): void {
    $pagination = [
      'plugin' => 'page',
      'configuration' => [
        'target' => 'query',
        'page_param' => 'page',
        'size_param' => 'per_page',
        'page_size' => 2,
        'first_page' => 1,
        'total_path' => '',
        'last_page_path' => 'meta.last_page',
      ],
    ];
    $this->mockResponses([
      $this->json(['rows' => $this->rows(1, 2), 'meta' => ['last_page' => 2]]),
      $this->json(['rows' => $this->rows(3, 3), 'meta' => ['last_page' => 2]]),
    ]);

    $items = $this->walk($this->source($this->rest(), NULL, $pagination));

    $this->assertSame([1, 2, 3], array_column($items, 'id'));
    $this->assertEquals(['per_page' => '2', 'page' => '1'], $this->queryOf(0));
    $this->assertSame('2', $this->queryOf(1)['page']);
    $this->assertCount(2, $this->history);
  }

  /**
   * Pages can start at 0, the size is optional, and an empty page ends it.
   */
  public function testPageNumbersFromZeroUntilAnEmptyPage(): void {
    $pagination = [
      'plugin' => 'page',
      'configuration' => [
        'target' => 'query',
        'page_param' => 'page[number]',
        'size_param' => '',
        'page_size' => 50,
        'first_page' => 0,
        'total_path' => '',
        'last_page_path' => '',
      ],
    ];
    $this->mockResponses([
      $this->json(['rows' => $this->rows(1, 2)]),
      $this->json(['rows' => $this->rows(3, 3)]),
      $this->json(['rows' => []]),
    ]);

    $items = $this->walk($this->source($this->rest(), NULL, $pagination));

    $this->assertSame([1, 2, 3], array_column($items, 'id'));
    $this->assertSame(['page' => ['number' => '0']], $this->queryOf(0));
    $this->assertSame(['page' => ['number' => '2']], $this->queryOf(2));
  }

  /**
   * Returns the settings of the next URL plugin.
   *
   * @return array{plugin: string, configuration: array<string, mixed>}
   *   The pagination of a definition.
   */
  protected function nextUrl(): array {
    return ['plugin' => 'next_url', 'configuration' => ['next_path' => 'links.next', 'total_path' => '']];
  }

  /**
   * Relative and absolute links on the same host are followed.
   */
  public function testNextUrlIsFollowed(): void {
    $this->mockResponses([
      $this->json(['rows' => $this->rows(1, 2), 'links' => ['next' => '/api/customers?page=2&sort=id']]),
      $this->json(['rows' => $this->rows(3, 4), 'links' => ['next' => 'https://site-a.test/api/customers?page=3']]),
      $this->json(['rows' => $this->rows(5, 5), 'links' => ['next' => NULL]]),
    ]);

    $items = $this->walk($this->source(['url' => 'https://site-a.test/api/customers', 'query' => ['status' => 'active']] + $this->rest(), NULL, $this->nextUrl()));

    $this->assertSame(range(1, 5), array_column($items, 'id'));
    $this->assertSame(['status' => 'active'], $this->queryOf(0));
    // The link holds its own query: the configured one is not added to it.
    $this->assertSame(['page' => '2', 'sort' => 'id'], $this->queryOf(1));
    $this->assertSame(['page' => '3'], $this->queryOf(2));
    // The credentials go along with every page.
    $this->assertSame(self::KEY, $this->request(2)->getHeaderLine('api-key'));
  }

  /**
   * A link to another host is not followed, so the key is not sent there.
   */
  public function testNextUrlToAnotherHostIsRefused(): void {
    $this->mockResponses([
      $this->json(['rows' => $this->rows(1, 2), 'links' => ['next' => 'https://evil.test/steal']]),
    ]);
    $source = $this->source(['url' => 'https://site-a.test/api/customers'] + $this->rest(), NULL, $this->nextUrl());
    $page = $source->fetchPage();

    try {
      $source->fetchPage($page->nextCursor);
      $this->fail('Expected a SourceException.');
    }
    catch (SourceException $exception) {
      $this->assertFalse($exception->retryable);
      $this->assertStringContainsString('another host', $exception->getMessage());
    }
    // Nothing was sent to the other host.
    $this->assertCount(1, $this->history);
  }

  /**
   * Another scheme or port counts as another host.
   */
  public function testNextUrlWithAnotherSchemeOrPortIsRefused(): void {
    foreach (['http://site-a.test/api/customers?page=2', 'https://site-a.test:8443/api/customers?page=2'] as $link) {
      $this->mockResponses([]);
      $source = $this->source(['url' => 'https://site-a.test/api/customers'] + $this->rest(), NULL, $this->nextUrl());
      try {
        $source->fetchPage($link);
        $this->fail('Expected a SourceException for ' . $link);
      }
      catch (SourceException $exception) {
        $this->assertStringContainsString('another host', $exception->getMessage());
      }
    }
  }

  /**
   * A link that points at the page just read is a loop.
   */
  public function testNextUrlThatDoesNotAdvanceIsRefused(): void {
    $this->mockResponses([
      $this->json(['rows' => $this->rows(1, 2), 'links' => ['next' => '/api/customers?page=2']]),
      $this->json(['rows' => $this->rows(3, 4), 'links' => ['next' => '/api/customers?page=2']]),
    ]);
    $source = $this->source(['url' => 'https://site-a.test/api/customers'] + $this->rest(), NULL, $this->nextUrl());
    $first = $source->fetchPage();

    $this->expectException(SourceException::class);
    $this->expectExceptionMessage('the page that was just read');
    $source->fetchPage($first->nextCursor);
  }

  /**
   * A link that is not text is refused; an empty one ends the pages.
   */
  public function testNextUrlMustBeText(): void {
    $this->mockResponses([
      $this->json(['rows' => $this->rows(1, 1), 'links' => ['next' => '']]),
      $this->json(['rows' => $this->rows(1, 1), 'links' => ['next' => ['href' => '/x']]]),
    ]);

    $this->assertNull($this->source($this->rest(), NULL, $this->nextUrl())->fetchPage()->nextCursor);

    $this->expectException(SourceException::class);
    $this->expectExceptionMessage('not text');
    $this->source($this->rest(), NULL, $this->nextUrl())->fetchPage();
  }

  /**
   * Messages never show the query of a link, which may hold tokens.
   */
  public function testMessagesLeaveOutTheLinkQuery(): void {
    $this->mockResponses([
      $this->json(['rows' => $this->rows(1, 2), 'links' => ['next' => '/api/customers?page=2&token=hush']]),
      new Response(503, [], 'busy'),
    ]);
    $source = $this->source(['url' => 'https://site-a.test/api/customers'] + $this->rest(), NULL, $this->nextUrl());
    $first = $source->fetchPage();

    try {
      $source->fetchPage($first->nextCursor);
      $this->fail('Expected a SourceException.');
    }
    catch (SourceException $exception) {
      $this->assertTrue($exception->retryable);
      $this->assertStringContainsString('https://site-a.test/api/customers', $exception->getMessage());
      $this->assertStringNotContainsString('hush', $exception->getMessage());
    }
  }

  /**
   * A check reads several pages and reports that the paging works.
   */
  public function testCheckReadsSeveralPages(): void {
    $this->mockResponses([
      $this->json(['rows' => $this->rows(1, 2), 'meta' => ['total' => 6]]),
      $this->json(['rows' => $this->rows(3, 4), 'meta' => ['total' => 6]]),
      $this->json(['rows' => $this->rows(5, 6), 'meta' => ['total' => 6]]),
    ]);

    $check = $this->source($this->rest(), NULL, $this->offsetLimit(['total_path' => 'meta.total']))->check();

    $this->assertTrue($check->isOk());
    $this->assertSame(
      [
        'Connected: the first page holds 2 items.',
        'The source reports 6 items in total.',
        'Read 3 pages with 6 items and no page repeated.',
      ],
      $check->getMessagesBySeverity(Severity::Info),
    );
    $this->assertCount(3, $this->history);
  }

  /**
   * A check finds a server that ignores the paging parameters.
   */
  public function testCheckFindsRepeatedPage(): void {
    $same = ['rows' => $this->rows(1, 2)];
    $this->mockResponses([$this->json($same), $this->json($same)]);

    $check = $this->source($this->rest(), NULL, $this->offsetLimit())->check();

    $this->assertFalse($check->isOk());
    $this->assertStringContainsString('Page 2 holds exactly the same data as page 1', $check->getMessagesBySeverity(Severity::Error)[0]);
    $this->assertStringContainsString('names of the paging parameters', $check->getMessagesBySeverity(Severity::Error)[0]);
  }

  /**
   * A cycle between two pages is found too, not only the same page twice.
   */
  public function testCheckFindsCycle(): void {
    $a = ['rows' => $this->rows(1, 2)];
    $b = ['rows' => $this->rows(3, 4)];
    $this->mockResponses([$this->json($a), $this->json($b), $this->json($a)]);

    $check = $this->source($this->rest(), NULL, $this->offsetLimit())->check();

    $this->assertStringContainsString('Page 3 holds exactly the same data as page 1', $check->getMessagesBySeverity(Severity::Error)[0]);
  }

  /**
   * Pages that overlap a little are a warning, not an error.
   */
  public function testCheckWarnsAboutOverlap(): void {
    $this->mockResponses([
      $this->json(['rows' => $this->rows(1, 3)]),
      $this->json(['rows' => $this->rows(3, 5)]),
      $this->json(['rows' => []]),
    ]);

    $check = $this->source($this->rest(), NULL, $this->offsetLimit(['page_size' => 3]))->check();

    $this->assertTrue($check->isOk());
    $this->assertStringContainsString('1 items appeared on more than one page', $check->getMessagesBySeverity(Severity::Warning)[0]);
  }

  /**
   * A failure on a later page says which page it was.
   */
  public function testCheckNamesTheFailingPage(): void {
    $this->mockResponses([
      $this->json(['rows' => $this->rows(1, 2)]),
      new Response(500, [], 'boom'),
    ]);

    $check = $this->source($this->rest(), NULL, $this->offsetLimit())->check();

    $this->assertFalse($check->isOk());
    $this->assertStringStartsWith('Page 2: Temporary problem, try again:', $check->getMessagesBySeverity(Severity::Error)[0]);
  }

}
