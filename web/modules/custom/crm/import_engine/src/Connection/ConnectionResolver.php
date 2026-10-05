<?php

declare(strict_types=1);

namespace Drupal\import_engine\Connection;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\import_engine\ImportConnectionInterface;
use Drupal\import_engine\ImportDefinitionInterface;
use Drupal\import_engine\Source\SourcePluginManager;

/**
 * Works out the source and authentication an import really uses.
 *
 * An import with a connection has the settings of its own (what to ask for)
 * and the connection has the rest (where, and how to log in). Together they
 * are the source of the import. No setting is in both: the connection owns the
 * settings its source plugin names as those of a connection, and the import
 * owns the others, which the definition checks.
 */
final class ConnectionResolver {

  /**
   * Constructs the resolver.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly SourcePluginManager $sources,
  ) {
  }

  /**
   * Returns the connection of an import, if it has one.
   *
   * @throws \Drupal\import_engine\Connection\ConnectionException
   *   When the import names a connection that does not exist.
   */
  public function connectionOf(ImportDefinitionInterface $definition): ?ImportConnectionInterface {
    $id = $definition->getConnection();
    if ($id === NULL || $id === '') {
      return NULL;
    }
    $connection = $this->entityTypeManager->getStorage('import_connection')->load($id);
    if (!$connection instanceof ImportConnectionInterface) {
      throw new ConnectionException(sprintf('The connection "%s" of the import "%s" no longer exists.', $id, $definition->id()));
    }
    return $connection;
  }

  /**
   * Returns the source and the authentication the import uses.
   *
   * @return array{source: array{plugin: string, configuration: array<string, mixed>}, authentication: array{plugin: string, configuration: array<string, mixed>}}
   *   The source plugin with its complete configuration, and the
   *   authentication.
   *
   * @throws \Drupal\import_engine\Connection\ConnectionException
   *   When the import names a connection that does not exist, or one for
   *   another kind of source.
   */
  public function resolve(ImportDefinitionInterface $definition): array {
    $source = $definition->getSource();
    $connection = $this->connectionOf($definition);
    if ($connection === NULL) {
      return ['source' => $source, 'authentication' => $definition->getAuthentication()];
    }

    $own = $connection->getSource();
    if ($own['plugin'] !== $source['plugin']) {
      throw new ConnectionException(sprintf('The connection "%s" is for a "%s" source, not for "%s".', $connection->id(), $own['plugin'], $source['plugin']));
    }
    $keys = $this->connectionKeys($source['plugin']);
    $configuration = $source['configuration'] + array_intersect_key($own['configuration'], array_flip($keys));
    return [
      'source' => ['plugin' => $source['plugin'], 'configuration' => $configuration],
      'authentication' => $connection->getAuthentication(),
    ];
  }

  /**
   * Returns the settings of a source plugin that belong to a connection.
   *
   * @return list<string>
   *   The keys.
   */
  public function connectionKeys(string $plugin): array {
    $definition = $this->sources->getDefinition($plugin, FALSE);
    return $definition['connection_keys'] ?? [];
  }

  /**
   * Returns the settings of a source plugin that an import must have.
   *
   * @return list<string>
   *   The keys.
   */
  public function requiredKeys(string $plugin): array {
    $definition = $this->sources->getDefinition($plugin, FALSE);
    return $definition['required_keys'] ?? [];
  }

}
