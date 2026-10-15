<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Kernel;

use Drupal\import_engine\Entity\ImportDefinition;
use Drupal\import_engine\Entity\ImportRunSet;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

/**
 * Base class for tests of imports that read from a mocked HTTP source.
 */
abstract class HttpImportTestBase extends NodeTestBase {

  /**
   * Saves an import that reads from the mocked source.
   */
  protected function httpImport(string $id): void {
    ImportDefinition::create($this->accounts([
      'id' => $id,
      'label' => ucfirst($id),
      'source_key' => ['id'],
      'authentication' => ['plugin' => 'none', 'configuration' => []],
      'pagination' => ['plugin' => 'none', 'configuration' => []],
      'source' => [
        'plugin' => 'http',
        'configuration' => [
          'url' => 'https://site-a.test/' . $id,
          'method' => 'GET',
          'headers' => [],
          'query' => [],
          'body' => '',
          'items_path' => 'data',
          'timeout' => 10,
          'format' => 'auto',
          'csv_delimiter' => ',',
        ],
      ],
    ]))->save();
  }

  /**
   * Saves a run set.
   *
   * @param list<string> $imports
   *   The imports, in order.
   * @param array<string, mixed> $values
   *   Values that replace the defaults.
   */
  protected function runSet(array $imports, array $values = []): ImportRunSet {
    $set = ImportRunSet::create($values + [
      'id' => 'catalog',
      'label' => 'Catalog',
      'imports' => $imports,
    ]);
    $set->save();
    return $set;
  }

  /**
   * Makes the source answer with these responses, in order.
   *
   * @param list<\Psr\Http\Message\ResponseInterface> $responses
   *   The responses.
   */
  protected function answers(array $responses): void {
    $this->container->set('http_client', new Client(['handler' => HandlerStack::create(new MockHandler($responses))]));
  }

  /**
   * Builds a response with accounts.
   *
   * @param list<int> $ids
   *   The IDs of the accounts.
   * @param string $name
   *   Their name; empty makes the items invalid.
   */
  protected function accountsResponse(array $ids, string $name = 'Account'): Response {
    $rows = array_map(static fn (int $id): array => [
      'id' => $id,
      'name' => $name === '' ? '' : "$name $id",
      'code' => "C$id",
    ], $ids);
    return new Response(200, ['Content-Type' => 'application/json'], json_encode(['data' => $rows], JSON_THROW_ON_ERROR));
  }

}
