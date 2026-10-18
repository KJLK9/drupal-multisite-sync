<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Guarantee;

use Drupal\import_engine\Entity\ImportDefinition;
use Drupal\import_engine\Drive\RunBudget;
use Drupal\import_engine\Run\RunStatus;
use Drupal\import_engine\Run\Trigger;
use Drupal\node\Entity\Node;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Every way of paging reads every item once, at every size of the data.
 *
 * Through the real HTTP source and the real paging plugins, against a server
 * that pages the way each plugin expects.
 */
#[Group('guarantee')]
#[RunTestsInSeparateProcesses]
class PaginationGuaranteeTest extends GuaranteeTestBase {

  /**
   * The ways of paging with the sizes that have always gone wrong.
   *
   * @return array<string, array{string, int}>
   *   The style and the number of items.
   */
  public static function cases(): array {
    $cases = [];
    foreach (['none', 'offset', 'offset without a total', 'page', 'page without a last page', 'next_url'] as $style) {
      foreach ([0, 1, 9, 10, 11, 20, 95] as $count) {
        $cases["$style, $count items"] = [$style, $count];
      }
    }
    return $cases;
  }

  /**
   * Saves an import that reads the API in the way of the style.
   */
  protected function apiImport(string $style): void {
    $pagination = match ($style) {
      'none' => ['plugin' => 'none', 'configuration' => []],
      'offset' => [
        'plugin' => 'offset_limit',
        'configuration' => [
          'target' => 'query',
          'offset_param' => 'offset',
          'limit_param' => 'limit',
          'page_size' => 10,
          'total_path' => 'meta.total',
          'stop_on_short_page' => FALSE,
        ],
      ],
      'offset without a total' => [
        'plugin' => 'offset_limit',
        'configuration' => [
          'target' => 'query',
          'offset_param' => 'offset',
          'limit_param' => 'limit',
          'page_size' => 10,
          'total_path' => '',
          'stop_on_short_page' => TRUE,
        ],
      ],
      'page' => [
        'plugin' => 'page',
        'configuration' => [
          'target' => 'query',
          'page_param' => 'page',
          'size_param' => 'per_page',
          'page_size' => 10,
          'first_page' => 1,
          'total_path' => '',
          'last_page_path' => 'meta.pages',
        ],
      ],
      'page without a last page' => [
        'plugin' => 'page',
        'configuration' => [
          'target' => 'query',
          'page_param' => 'page',
          'size_param' => 'per_page',
          'page_size' => 10,
          'first_page' => 1,
          'total_path' => '',
          'last_page_path' => '',
        ],
      ],
      'next_url' => ['plugin' => 'next_url', 'configuration' => ['next_path' => 'links.next', 'total_path' => '']],
      default => throw new \InvalidArgumentException($style),
    };
    $this->saveImport([
      'source_key' => ['id'],
      'pagination' => $pagination,
      'authentication' => ['plugin' => 'none', 'configuration' => []],
      'source' => [
        'plugin' => 'http',
        'configuration' => [
          'url' => FakeApi::URL,
          'method' => 'GET',
          'headers' => [],
          'query' => [],
          'body' => '',
          'items_path' => 'data',
          'timeout' => 10,
          'format' => 'json',
          'csv_delimiter' => ',',
        ],
      ],
    ]);
  }

  /**
   * The ways of paging, in the shape the API takes.
   */
  protected function styleOf(string $style): string {
    return match ($style) {
      'offset without a total' => 'offset',
      'page without a last page' => 'page',
      default => $style,
    };
  }

  /**
   * Reads everything, once, and asks the server no more often than it must.
   */
  #[DataProvider('cases')]
  public function testEveryItemIsReadOnce(string $style, int $count): void {
    $this->apiImport($style);
    $api = new FakeApi($this->accountItems(1, $count), $this->styleOf($style), 10, !str_contains($style, 'without'));
    $this->container->set('http_client', $api->client());
    $definition = ImportDefinition::load('customers');
    $this->assertNotNull($definition);

    $run = $this->starter->start($definition, Trigger::Drush);
    $this->driver->drive($run, $definition, new RunBudget(), 'w', 50);

    $run = $this->reload($run);
    $this->assertSame(RunStatus::Completed, $run->getStatus(), $style);
    $this->assertCount($count, Node::loadMultiple(), 'Every item is on the site.');
    $sent = $api->sentIds();
    $this->assertSame($count === 0 ? [] : range(1, $count), $sent, 'Every item was sent once, in order.');
    $full = (int) ceil($count / 10);
    $expected = match ($style) {
      'none' => 1,
      'offset', 'page', 'next_url' => max(1, $full),
      // These stop when a page comes back short or empty: a data set that is
      // an exact multiple of the page costs one more, empty, request.
      'offset without a total' => $count % 10 === 0 && $count > 0 ? $full + 1 : max(1, $full),
      // A page style without a last page goes on until a page is empty.
      'page without a last page' => $count === 0 ? 1 : $full + 1,
      default => throw new \InvalidArgumentException($style),
    };
    $this->assertCount($expected, $api->requests, 'The number of requests.');
  }

  /**
   * A server that ignores the paging does not keep the engine reading for ever.
   */
  public function testServerThatIgnoresPagingIsStopped(): void {
    $this->apiImport('offset without a total');
    $api = new FakeApi($this->accountItems(1, 50), 'stuck', 10, FALSE);
    $this->container->set('http_client', $api->client());
    $definition = ImportDefinition::load('customers');
    $this->assertNotNull($definition);

    $run = $this->starter->start($definition, Trigger::Drush);
    $this->driver->drive($run, $definition, new RunBudget(), 'w', 50);

    $run = $this->reload($run);
    $this->assertLessThanOrEqual(8, count($api->requests), 'It gave up after a few identical pages.');
    $this->assertContains($run->getStatus(), [RunStatus::Failed, RunStatus::CompletedWithErrors]);
    $this->assertCount(10, Node::loadMultiple(), 'The page it did get is not written ten times.');
  }

  /**
   * A server that claims more than it has is read to its real end.
   */
  public function testWrongTotalIsNotBelieved(): void {
    $this->apiImport('offset');
    $api = new FakeApi($this->accountItems(1, 25), 'offset', 10, TRUE, 1000);
    $this->container->set('http_client', $api->client());
    $definition = ImportDefinition::load('customers');
    $this->assertNotNull($definition);

    $run = $this->starter->start($definition, Trigger::Drush);
    $this->driver->drive($run, $definition, new RunBudget(), 'w', 50);

    $this->assertCount(25, Node::loadMultiple());
    $this->assertCount(4, $api->requests, 'Three pages and the empty one that shows it is over.');
  }

}
