<?php

declare(strict_types=1);

namespace Drupal\import_engine\Mapper;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\import_engine\Attribute\ImportMapper;

/**
 * Manages the mapper plugins of the import engine.
 */
final class MapperPluginManager extends DefaultPluginManager {

  /**
   * Constructs the plugin manager.
   *
   * @param \Traversable<string, string> $namespaces
   *   The namespaces to look for plugins in.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   The cache backend.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/ImportEngine/Mapper', $namespaces, $module_handler, MapperInterface::class, ImportMapper::class);
    $this->alterInfo('import_engine_mapper_info');
    $this->setCacheBackend($cache_backend, 'import_engine_mapper_plugins');
  }

  /**
   * Returns the IDs of the mappers that fit a field type.
   *
   * With one result a form can choose the mapper without asking; with several
   * it offers the choice.
   *
   * @return list<string>
   *   The plugin IDs, in the order of the plugin definitions.
   */
  public function idsForFieldType(string $fieldType): array {
    $ids = [];
    foreach ($this->getDefinitions() as $id => $definition) {
      if (in_array($fieldType, $definition['field_types'] ?? [], TRUE)) {
        $ids[] = (string) $id;
      }
    }
    return $ids;
  }

}
