<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine_ui\Kernel;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\import_engine_ui\Hook\NavigationHooks;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that every page of the engine can be left for another section.
 */
#[Group('import_engine_ui')]
#[RunTestsInSeparateProcesses]
class NavigationTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'import_engine', 'import_engine_ui'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('router.builder')->rebuild();
    $this->setUpCurrentUser(['name' => 'alice'], [
      'administer import definitions',
      'administer import runs',
      'view import runs',
    ]);
  }

  /**
   * Returns the tabs of a page: the titles, and which one is active.
   *
   * @return array{titles: list<string>, active: list<string>}
   *   The titles of the tabs, in order, and those that are marked active.
   */
  protected function tabs(string $route): array {
    $data = $this->container->get('plugin.manager.menu.local_task')->getLocalTasks($route, 0);
    $titles = [];
    $active = [];
    foreach ($data['tabs'] ?? [] as $tab) {
      $access = $tab['#access'] ?? NULL;
      if ($access instanceof AccessResultInterface && !$access->isAllowed()) {
        continue;
      }
      $title = (string) $tab['#link']['title'];
      $titles[] = $title;
      if (!empty($tab['#active'])) {
        $active[] = $title;
      }
    }
    return ['titles' => $titles, 'active' => $active];
  }

  /**
   * The sections are tabs on one overview page.
   */
  public function testSectionsAreTabs(): void {
    $expected = ['Overview', 'Imports', 'Connections', 'Run sets', 'Runs', 'Dead letter queue', 'Circuit breakers'];

    $tabs = $this->tabs('import_engine_ui.run_sets');

    $this->assertSame($expected, $tabs['titles']);
    $this->assertSame(['Run sets'], $tabs['active']);
    $this->assertSame(['Overview'], $this->tabs('import_engine_ui.overview')['active']);
  }

  /**
   * A page below a section shows the tabs, with its section marked.
   */
  public function testPagesBelowSectionShowTabs(): void {
    foreach (NavigationHooks::SECTIONS as $route => $section) {
      $tabs = $this->tabs($route);

      $this->assertCount(7, $tabs['titles'], $route);
      $this->assertCount(1, $tabs['active'], $route);
      $expected = $this->tabs($section)['active'];
      $this->assertSame($expected, $tabs['active'], $route);
    }
  }

  /**
   * A person who may not administer sees only the tabs they may use.
   */
  public function testTabsFollowAccess(): void {
    $this->setUpCurrentUser(['name' => 'bob'], ['view import runs']);

    $titles = $this->tabs('import_engine_ui.run')['titles'];

    $this->assertSame(['Overview', 'Runs', 'Dead letter queue', 'Circuit breakers'], $titles);
  }

  /**
   * The overview lists the sections, and the sections are below it.
   */
  public function testOverviewIsTheParentOfTheSections(): void {
    $links = $this->container->get('plugin.manager.menu.link')->loadLinksByRoute('import_engine_ui.runs');
    $link = reset($links);
    $this->assertNotFalse($link);

    $this->assertSame('import_engine_ui.overview', $link->getParent());
    $overview = $this->container->get('plugin.manager.menu.link')->loadLinksByRoute('import_engine_ui.overview');
    $parent = reset($overview);
    $this->assertNotFalse($parent);
    $this->assertSame('system.admin_config_system', $parent->getParent());
  }

  /**
   * Every route below a section exists, and so does its section.
   */
  public function testSectionsPointAtRoutes(): void {
    $provider = $this->container->get('router.route_provider');
    foreach (NavigationHooks::SECTIONS as $route => $section) {
      $this->assertNotNull($provider->getRouteByName($route));
      $this->assertNotNull($provider->getRouteByName($section));
    }
  }

}
