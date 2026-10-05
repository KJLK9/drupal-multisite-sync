<?php

declare(strict_types=1);

namespace Drupal\import_engine_ui\Hook;

use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Menu\LocalTaskManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Keeps the tabs of the import engine on the pages below a section.
 *
 * The pages of the engine are tabs (imports, connections, run sets, runs, ...).
 * A page below a tab (change an import, a run, delete a connection) is not a
 * tab itself, so it would show none, and the way to another section would be
 * the menu. Here such a page shows the tabs of its section, with that section
 * marked, so another section is always one click away.
 */
final class NavigationHooks {

  /**
   * The section of every page that is below one: route name to tab route.
   */
  public const SECTIONS = [
    'import_engine_ui.definition_add' => 'import_engine_ui.definitions',
    'import_engine_ui.definition_edit' => 'import_engine_ui.definitions',
    'import_engine_ui.definition_delete' => 'import_engine_ui.definitions',
    'import_engine_ui.definition_run' => 'import_engine_ui.definitions',
    'import_engine_ui.connection_add' => 'import_engine_ui.connections',
    'import_engine_ui.connection_edit' => 'import_engine_ui.connections',
    'import_engine_ui.connection_delete' => 'import_engine_ui.connections',
    'import_engine_ui.run_set_add' => 'import_engine_ui.run_sets',
    'import_engine_ui.run_set_edit' => 'import_engine_ui.run_sets',
    'import_engine_ui.run_set_delete' => 'import_engine_ui.run_sets',
    'import_engine_ui.run_set_run' => 'import_engine_ui.run_sets',
    'import_engine_ui.run' => 'import_engine_ui.runs',
    'import_engine_ui.run_items' => 'import_engine_ui.runs',
    'import_engine_ui.run_events' => 'import_engine_ui.runs',
    'import_engine_ui.run_cancel' => 'import_engine_ui.runs',
    'import_engine_ui.dead_letter_edit' => 'import_engine_ui.dead_letter',
    'import_engine_ui.breaker_action' => 'import_engine_ui.breakers',
  ];

  /**
   * Constructs the hooks.
   */
  public function __construct(
    #[Autowire(service: 'plugin.manager.menu.local_task')]
    private readonly LocalTaskManagerInterface $tasks,
  ) {
  }

  /**
   * Implements hook_menu_local_tasks_alter().
   *
   * @param array<string, mixed> $data
   *   The tabs, by level.
   * @param string $route_name
   *   The current route.
   * @param \Drupal\Core\Cache\RefinableCacheableDependencyInterface $cacheability
   *   The cacheability of the tabs.
   */
  #[Hook('menu_local_tasks_alter')]
  public function localTasksAlter(array &$data, string $route_name, RefinableCacheableDependencyInterface &$cacheability): void {
    $section = self::SECTIONS[$route_name] ?? NULL;
    if ($section === NULL) {
      return;
    }
    // The tabs of the section, built as for the section's own page, so that
    // the section is the active one.
    $build = $this->tasks->getTasksBuild($section, $cacheability);
    if (isset($build[0])) {
      $data['tabs'][0] = $build[0];
    }
    $cacheability->addCacheContexts(['route', 'user.permissions']);
  }

}
