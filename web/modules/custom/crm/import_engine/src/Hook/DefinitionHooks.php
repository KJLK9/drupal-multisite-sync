<?php

declare(strict_types=1);

namespace Drupal\import_engine\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\import_engine\Storage\DefinitionCleaner;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Hook implementations about import definitions.
 */
final class DefinitionHooks {

  /**
   * Constructs the hooks.
   */
  public function __construct(
    #[Autowire(service: 'import_engine.definition_cleaner')]
    private readonly DefinitionCleaner $cleaner,
  ) {
  }

  /**
   * Implements hook_ENTITY_TYPE_delete() for import definitions.
   *
   * What the engine kept for the import goes with it, whether it was deleted
   * in the interface, with drush or by a configuration import.
   */
  #[Hook('import_definition_delete')]
  public function definitionDeleted(EntityInterface $definition): void {
    $this->cleaner->forget((string) $definition->id());
  }

}
