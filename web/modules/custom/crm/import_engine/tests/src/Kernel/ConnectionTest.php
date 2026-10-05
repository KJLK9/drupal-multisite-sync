<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Kernel;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\import_engine\Connection\ConnectionException;
use Drupal\import_engine\Entity\ImportConnection;
use Drupal\import_engine\Entity\ImportDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests connections: what imports share, and how an import uses one.
 */
#[Group('import_engine')]
#[RunTestsInSeparateProcesses]
class ConnectionTest extends HttpSourceTestBase {

  /**
   * Builds a connection to a GraphQL endpoint, not saved.
   *
   * @param array<string, mixed> $values
   *   Values that replace the defaults.
   */
  protected function connection(array $values = []): ImportConnection {
    return ImportConnection::create($values + [
      'id' => 'site_a',
      'label' => 'Site A',
      'description' => 'The catalog.',
      'source' => [
        'plugin' => 'graphql',
        'configuration' => [
          'url' => 'http://site-a.test/graphql',
          'headers' => ['X-Team' => 'b'],
          'timeout' => 20,
        ],
      ],
      'authentication' => static::key('api-key', self::ENV_VAR),
    ]);
  }

  /**
   * Returns the authentication of an API key in a header.
   *
   * @return array{plugin: string, configuration: array<string, string>}
   *   The authentication.
   */
  protected static function key(string $header, string $env_var): array {
    return [
      'plugin' => 'api_key_header',
      'configuration' => ['header' => $header, 'env_var' => $env_var],
    ];
  }

  /**
   * Builds an import that uses the connection, not saved.
   *
   * @param array<string, mixed> $values
   *   Values that replace the defaults.
   */
  protected function import(array $values = []): ImportDefinition {
    return ImportDefinition::create($values + [
      'id' => 'accounts',
      'label' => 'Accounts',
      'connection' => 'site_a',
      'source' => [
        'plugin' => 'graphql',
        'configuration' => [
          'query' => '{ customers { items { id } } }',
          'variables' => '',
          'items_path' => 'data.customers.items',
        ],
      ],
      'authentication' => ['plugin' => 'none', 'configuration' => []],
      'pagination' => ['plugin' => 'none', 'configuration' => []],
      'source_key' => ['id'],
      'target' => [
        'plugin' => 'entity',
        'configuration' => [
          'entity_type' => 'node',
          'bundle' => 'account',
          'owner' => 0,
        ],
      ],
    ]);
  }

  /**
   * Returns what is wrong with an entity: the message by property path.
   *
   * @return array<string, string>
   *   The messages.
   */
  protected function problems(ImportDefinition|ImportConnection $entity): array {
    $problems = [];
    foreach ($entity->getTypedData()->validate() as $violation) {
      $problems[$violation->getPropertyPath()] = (string) $violation->getMessage();
    }
    return $problems;
  }

  /**
   * A connection is valid, and keeps only what belongs to a connection.
   */
  public function testConnectionIsValid(): void {
    $this->assertSame([], $this->problems($this->connection()));

    $connection = $this->connection();
    $connection->save();
    $loaded = ImportConnection::load('site_a');
    $this->assertSame('graphql', $loaded?->getSource()['plugin']);
    $this->assertSame('The catalog.', $loaded->getDescription());
    $this->assertSame('api_key_header', $loaded->getAuthentication()['plugin']);
  }

  /**
   * What cannot be in a connection is refused.
   */
  public function testConnectionSchemaRefuses(): void {
    $source = ['plugin' => 'graphql', 'configuration' => ['url' => 'http://x.test/g', 'headers' => [], 'timeout' => 20]];

    $query = $source;
    $query['configuration']['query'] = '{ x }';
    $this->assertArrayHasKey('source.configuration.query', $this->problems($this->connection(['source' => $query])), 'A query belongs to an import.');

    $bad_url = $source;
    $bad_url['configuration']['url'] = 'ftp://x.test';
    $this->assertArrayHasKey('source.configuration.url', $this->problems($this->connection(['source' => $bad_url])));

    $pigeon = ['plugin' => 'carrier_pigeon', 'configuration' => []];
    $this->assertArrayHasKey('source.plugin', $this->problems($this->connection(['source' => $pigeon])));
    $nope = ['plugin' => 'nope', 'configuration' => []];
    $this->assertArrayHasKey('authentication.plugin', $this->problems($this->connection(['authentication' => $nope])));

    $slow = $source;
    $slow['configuration']['timeout'] = 0;
    $this->assertArrayHasKey('source.configuration.timeout', $this->problems($this->connection(['source' => $slow])));
  }

  /**
   * An import that uses a connection does not need the settings it has.
   */
  public function testImportWithConnectionIsValid(): void {
    $this->connection()->save();

    $this->assertSame([], $this->problems($this->import()));
  }

  /**
   * An import that does not fit its connection says how.
   *
   * @param array<string, mixed> $changes
   *   Values that replace those of the import.
   * @param string $path
   *   The property path of the problem.
   */
  #[DataProvider('misfits')]
  public function testImportThatDoesNotFit(array $changes, string $path): void {
    $this->connection()->save();

    $problems = $this->problems($this->import($changes));

    $this->assertArrayHasKey($path, $problems, implode(', ', array_keys($problems)));
  }

  /**
   * Imports that do not fit the connection.
   *
   * @return array<string, array{array<string, mixed>, string}>
   *   The changes and the property path of the problem.
   */
  public static function misfits(): array {
    $source = static fn (array $config): array => [
      'plugin' => 'graphql',
      'configuration' => $config + ['query' => '{ x }', 'variables' => '', 'items_path' => 'data'],
    ];
    $http = ['plugin' => 'http', 'configuration' => ['url' => 'http://x.test/a']];
    return [
      'a connection that does not exist' => [['connection' => 'nope'], 'connection'],
      'the URL again' => [['source' => $source(['url' => 'http://other.test/g'])], 'source.configuration.url'],
      'the headers again' => [['source' => $source(['headers' => []])], 'source.configuration.headers'],
      'the timeout again' => [['source' => $source(['timeout' => 5])], 'source.configuration.timeout'],
      'authentication of its own' => [['authentication' => static::key('k', 'K')], 'authentication.plugin'],
      'another kind of source' => [['source' => $http], 'connection'],
      'no query' => [['source' => $source(['query' => ''])], 'source.configuration.query'],
    ];
  }

  /**
   * An import without a connection has all the settings its source needs.
   */
  public function testImportWithoutConnectionNeedsItsOwnSettings(): void {
    $own = ['query' => '{ x }', 'variables' => '', 'items_path' => 'data', 'headers' => [], 'timeout' => 30];

    $with_url = ['plugin' => 'graphql', 'configuration' => $own + ['url' => 'http://x.test/g']];
    $this->assertSame([], $this->problems($this->import(['connection' => NULL, 'source' => $with_url])));
    $no_url = ['plugin' => 'graphql', 'configuration' => $own];
    $without_url = $this->problems($this->import(['connection' => NULL, 'source' => $no_url]));
    $this->assertArrayHasKey('source.configuration.url', $without_url);
    $this->assertStringContainsString('or use a connection', $without_url['source.configuration.url']);
  }

  /**
   * The source of an import is made of the import and the connection.
   */
  public function testResolverMergesImportAndConnection(): void {
    $this->connection()->save();
    $import = $this->import();

    $resolved = $this->container->get('import_engine.connection_resolver')->resolve($import);

    $this->assertSame('graphql', $resolved['source']['plugin']);
    $this->assertSame([
      'query' => '{ customers { items { id } } }',
      'variables' => '',
      'items_path' => 'data.customers.items',
      'url' => 'http://site-a.test/graphql',
      'headers' => ['X-Team' => 'b'],
      'timeout' => 20,
    ], $resolved['source']['configuration']);
    $this->assertSame('api_key_header', $resolved['authentication']['plugin']);
    $this->assertSame('site_a', $this->container->get('import_engine.connection_resolver')->connectionOf($import)?->id());
  }

  /**
   * Without a connection the import is what it says it is.
   */
  public function testResolverLeavesAnImportWithoutConnectionAlone(): void {
    $http = ['plugin' => 'http', 'configuration' => ['url' => 'http://x.test/a']];
    $import = $this->import(['connection' => NULL, 'source' => $http]);

    $resolved = $this->container->get('import_engine.connection_resolver')->resolve($import);

    $this->assertSame($import->getSource(), $resolved['source']);
    $this->assertSame($import->getAuthentication(), $resolved['authentication']);
    $this->assertNull($this->container->get('import_engine.connection_resolver')->connectionOf($import));
  }

  /**
   * A connection that is gone, or for another source, is a clear error.
   */
  public function testResolverExplainsWhatIsWrong(): void {
    $resolver = $this->container->get('import_engine.connection_resolver');
    try {
      $resolver->resolve($this->import(['connection' => 'ghost']));
      $this->fail('Expected a ConnectionException.');
    }
    catch (ConnectionException $exception) {
      $this->assertSame('The connection "ghost" of the import "accounts" no longer exists.', $exception->getMessage());
    }

    $this->connection()->save();
    try {
      $http = ['plugin' => 'http', 'configuration' => ['url' => 'http://x.test/a']];
      $resolver->resolve($this->import(['source' => $http]));
      $this->fail('Expected a ConnectionException.');
    }
    catch (ConnectionException $exception) {
      $this->assertStringContainsString('is for a "graphql" source, not for "http"', $exception->getMessage());
    }
  }

  /**
   * The source reads from the connection, with its key and headers.
   */
  public function testSourceReadsThroughTheConnection(): void {
    putenv(self::ENV_VAR . '=' . self::KEY);
    $this->connection()->save();
    $this->mockResponses([$this->json(['data' => ['customers' => ['items' => [['id' => '1'], ['id' => '2']]]]])]);

    $source = $this->container->get('import_engine.source_factory')->create($this->import());
    $page = $source->fetchPage();

    $this->assertCount(2, $page->items);
    $this->assertSame('site-a.test', $source->getEndpoint());
    $request = $this->request(0);
    $this->assertSame('http://site-a.test/graphql', (string) $request->getUri());
    $this->assertSame(self::KEY, $request->getHeaderLine('api-key'));
    $this->assertSame('b', $request->getHeaderLine('X-Team'));
    $this->assertStringContainsString('customers', (string) $request->getBody());
    $this->assertSame(20, $this->history[0]['options']['timeout']);
  }

  /**
   * Changing the connection changes every import that uses it.
   */
  public function testChangingTheConnectionChangesTheImports(): void {
    $connection = $this->connection();
    $connection->save();
    $factory = $this->container->get('import_engine.source_factory');
    $first = $this->import();
    $second = $this->import(['id' => 'items']);
    $this->assertSame('site-a.test', $factory->create($first)->getEndpoint());

    $source = $connection->getSource();
    $source['configuration']['url'] = 'http://site-b.test/graphql';
    $connection->set('source', $source)->save();

    $this->assertSame('site-b.test', $factory->create($first)->getEndpoint());
    $this->assertSame('site-b.test', $factory->create($second)->getEndpoint());
  }

  /**
   * An import depends on its connection, for the order of a config import.
   */
  public function testImportDependsOnItsConnection(): void {
    $this->connection()->save();
    $import = $this->import();
    $import->save();

    $this->assertContains('import_engine.import_connection.site_a', $import->getDependencies()['config'] ?? []);
  }

  /**
   * A connection that imports use cannot be deleted; the message says which.
   */
  public function testConnectionInUseCannotBeDeleted(): void {
    $connection = $this->connection();
    $connection->save();
    $this->import()->save();
    $this->import(['id' => 'items'])->save();

    try {
      $connection->delete();
      $this->fail('Expected an EntityStorageException.');
    }
    catch (EntityStorageException $exception) {
      $this->assertSame('The connection "site_a" cannot be deleted: it is used by the imports accounts, items.', $exception->getMessage());
    }
    $this->assertNotNull(ImportConnection::load('site_a'));

    // Once the imports have gone, or no longer use it, it can.
    ImportDefinition::load('accounts')?->delete();
    $items = ImportDefinition::load('items');
    $items?->set('connection', NULL)->set('source', [
      'plugin' => 'graphql',
      'configuration' => [
        'url' => 'http://x.test/g',
        'query' => '{ x }',
        'variables' => '',
        'items_path' => 'data',
        'headers' => [],
        'timeout' => 30,
      ],
    ])->save();
    $connection->delete();
    $this->assertNull(ImportConnection::load('site_a'));
  }

  /**
   * A connection can be deleted when nothing uses it.
   */
  public function testUnusedConnectionCanBeDeleted(): void {
    $connection = $this->connection();
    $connection->save();

    $connection->delete();

    $this->assertNull(ImportConnection::load('site_a'));
  }

}
