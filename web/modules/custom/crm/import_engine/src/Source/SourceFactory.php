<?php

declare(strict_types=1);

namespace Drupal\import_engine\Source;

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
  ) {
  }

  /**
   * Creates the source of a definition.
   */
  public function create(ImportDefinitionInterface $definition): SourceInterface {
    $source = $definition->getSource();
    $configuration = $source['configuration'] + [
      'authentication' => $definition->getAuthentication(),
      'pagination' => $definition->getPagination(),
      'source_key' => $definition->getSourceKey(),
    ];
    $instance = $this->sources->createInstance($source['plugin'], $configuration);
    assert($instance instanceof SourceInterface);
    return $instance;
  }

}
