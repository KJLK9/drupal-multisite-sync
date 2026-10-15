<?php

declare(strict_types=1);

namespace Drupal\Tests\site_b_catalog\Kernel;

use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that the entities of the catalog can be found in the administration.
 */
#[Group('site_b_catalog')]
#[RunTestsInSeparateProcesses]
class NavigationTest extends CatalogTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * The catalog is an item of its own, with the three lists under it.
   */
  public function testCatalogIsTopLevelWithTheThreeLists(): void {
    $manager = $this->container->get('plugin.manager.menu.link');
    $overview = $manager->loadLinksByRoute('site_b_catalog.overview');
    $this->assertNotFalse(reset($overview));
    $this->assertSame('system.admin', reset($overview)->getParent());

    foreach (['entity.account.collection', 'entity.item.collection', 'entity.agreement.collection'] as $route) {
      $links = $manager->loadLinksByRoute($route);
      $link = reset($links);
      $this->assertNotFalse($link, $route);
      $this->assertSame('site_b_catalog.overview', $link->getParent(), $route);
    }
  }

  /**
   * The overview and the lists show the same tabs, with their own active.
   */
  public function testTabsConnectTheLists(): void {
    $this->setCurrentUser($this->createUser(['administer account', 'administer item', 'administer agreement']));
    $tasks = $this->container->get('plugin.manager.menu.local_task');

    $pages = [
      'site_b_catalog.overview' => 'Overview',
      'entity.account.collection' => 'Accounts',
      'entity.item.collection' => 'Items',
      'entity.agreement.collection' => 'Agreements',
    ];
    foreach ($pages as $route => $active) {
      $data = $tasks->getLocalTasks($route, 0);
      $titles = [];
      $current = [];
      foreach ($data['tabs'] as $tab) {
        $titles[] = (string) $tab['#link']['title'];
        if (!empty($tab['#active'])) {
          $current[] = (string) $tab['#link']['title'];
        }
      }
      $this->assertSame(['Overview', 'Accounts', 'Items', 'Agreements'], $titles, $route);
      $this->assertSame([$active], $current, $route);
    }
  }

  /**
   * Whoever administers one of the types may open the overview; others may not.
   */
  public function testOverviewAccess(): void {
    $manager = $this->container->get('access_manager');

    $this->assertTrue($manager->checkNamedRoute('site_b_catalog.overview', [], $this->createUser(['administer item'])));
    $this->assertTrue($manager->checkNamedRoute('site_b_catalog.overview', [], $this->createUser(['administer account'])));
    // To see one account is not to list them: the lists are administration.
    $this->assertFalse($manager->checkNamedRoute('site_b_catalog.overview', [], $this->createUser(['view account'])));
    $this->assertFalse($manager->checkNamedRoute('site_b_catalog.overview', [], $this->createUser([])));
  }

}
