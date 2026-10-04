<?php

declare(strict_types=1);

namespace Drupal\import_engine\Pagination;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\import_engine\Attribute\ImportPagination;

/**
 * Manages the pagination plugins of the import engine.
 */
final class PaginationPluginManager extends DefaultPluginManager {

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
    parent::__construct('Plugin/ImportEngine/Pagination', $namespaces, $module_handler, PaginationInterface::class, ImportPagination::class);
    $this->alterInfo('import_engine_pagination_info');
    $this->setCacheBackend($cache_backend, 'import_engine_pagination_plugins');
  }

}
