<?php

declare(strict_types=1);

namespace Drupal\import_engine\Source;

use Drupal\import_engine\Connection\ConnectionResolver;
use Drupal\import_engine\ImportDefinitionInterface;

/**
 * Creates the source plugin of an import definition.
 *
 * The factory hands the definition's authentication, pagination and key paths
 * to the source, so a source that talks HTTP can sign its requests and step
 * through its pages.
 */
final class SourceFactory {

  /**
   * Constructs the factory.
   */
  public function __construct(
    private readonly SourcePluginManager $sources,
    private readonly ConnectionResolver $connections,
  ) {
  }

  /**
   * Creates the source of a definition.
   *
   * When the import uses a connection, the source is made of the settings of
   * the import and those of the connection, which also gives the
   * authentication.
   *
   * @throws \Drupal\import_engine\Connection\ConnectionException
   *   When the connection of the import is gone or does not fit.
   */
  public function create(ImportDefinitionInterface $definition): SourceInterface {
    $resolved = $this->connections->resolve($definition);
    $configuration = $resolved['source']['configuration'] + [
      'authentication' => $resolved['authentication'],
      'pagination' => $definition->getPagination(),
      'source_key' => $definition->getSourceKey(),
    ];
    $instance = $this->sources->createInstance($resolved['source']['plugin'], $configuration);
    assert($instance instanceof SourceInterface);
    return $instance;
  }

}
