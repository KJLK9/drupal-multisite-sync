<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine_ui\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\import_engine\Breaker\BreakerState;
use Drupal\import_engine\Breaker\BreakerStore;
use Drupal\import_engine_ui\Controller\BreakerController;
use Drupal\import_engine_ui\Form\BreakerActionForm;
use Drupal\Tests\import_engine\Kernel\StorageTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tests the page of circuit breakers, and opening and closing them.
 */
#[Group('import_engine_ui')]
#[RunTestsInSeparateProcesses]
class BreakerPagesTest extends StorageTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'import_engine', 'import_engine_ui'];

  /**
   * The store of breakers.
   */
  protected BreakerStore $store;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
    $this->container->get('router.builder')->rebuild();
    $this->store = $this->container->get('import_engine.breaker_store');
    $this->setUpCurrentUser(['name' => 'alice'], ['view import runs', 'administer import runs']);
  }

  /**
   * Builds the page.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  protected function page(): array {
    return $this->container->get('class_resolver')->getInstanceFromDefinition(BreakerController::class)->list();
  }

  /**
   * With no problems the page says so.
   */
  public function testEmpty(): void {
    $this->assertSame('No server has had a problem yet.', (string) $this->page()['table']['#empty']);
  }

  /**
   * The page shows the state of each breaker and what can be done with it.
   */
  public function testShowsBreakers(): void {
    $now = time();
    $this->store->recordFailure('a.test', 1, 60, $now);
    $this->store->recordFailure('b.test', 5, 60, $now);
    $this->store->trip('c.test', $now);

    $page = $this->page();
    $rows = $page['table']['#rows'];
    $state = static fn (array $row): string => strip_tags((string) $row[1]['data']['#markup']);

    $this->assertSame(['a.test', 'b.test', 'c.test'], array_column($rows, 0));
    $this->assertSame('open', $state($rows[0]));
    $this->assertSame(1, $rows[0][2]);
    $this->assertSame('closed', $state($rows[1]));
    $this->assertSame('open (by hand)', $state($rows[2]));
    $this->assertStringContainsString('import-pill--failed', (string) $rows[0][1]['data']['#markup']);
    $this->assertStringContainsString('import-pill--on', (string) $rows[1][1]['data']['#markup']);

    // The line above the page counts them, and the panel says where they stand.
    $header = (string) $page['header']['#markup'];
    $this->assertStringContainsString('2 open', $header);
    $this->assertStringContainsString('1 closed', $header);
    $this->assertStringContainsString('3 servers watched', $header);
    $sentences = implode(' ', array_column($page['summary_plain']['box']['list']['#items'], '#markup'));
    $this->assertStringContainsString('a.test is taken to be down', $sentences);
    $this->assertStringContainsString('c.test was opened by hand', $sentences);
    $this->assertStringNotContainsString('b.test', $sentences, 'A closed breaker has nothing to say.');
    $this->assertSame(['reset'], array_keys($rows[0][6]['data']['#links']));
    $this->assertSame(['trip'], array_keys($rows[1][6]['data']['#links']));
  }

  /**
   * A viewer sees the breakers but gets no operations.
   */
  public function testViewerGetsNoOperations(): void {
    $this->store->trip('c.test', time());
    $this->setUpCurrentUser(['name' => 'bob'], ['view import runs']);

    $this->assertSame([], $this->page()['table']['#rows'][0][6]['data']['#links']);
  }

  /**
   * Confirming opens or closes the breaker.
   */
  public function testActions(): void {
    $this->submit('trip', 'a.test');
    $this->assertSame(BreakerState::Open, $this->store->get('a.test')->state);
    $this->assertTrue($this->store->get('a.test')->manual);

    $this->submit('reset', 'a.test');
    $this->assertSame(BreakerState::Closed, $this->store->get('a.test')->state);
    $this->assertFalse($this->store->get('a.test')->manual);
  }

  /**
   * An action that does not exist is not found.
   */
  public function testUnknownActionIsNotFound(): void {
    $this->expectException(NotFoundHttpException::class);

    $form_state = new FormState();
    $form_state->addBuildInfo('args', ['delete', 'a.test']);
    $this->container->get('form_builder')->buildForm(BreakerActionForm::class, $form_state);
  }

  /**
   * Only people who may administer runs can open or close a breaker.
   */
  public function testAccess(): void {
    $manager = $this->container->get('access_manager');
    $viewer = $this->createUser(['view import runs']);
    $admin = $this->createUser(['administer import runs']);
    $parameters = ['action' => 'reset', 'endpoint' => 'a.test:8443'];

    $this->assertTrue($manager->checkNamedRoute('import_engine_ui.breakers', [], $viewer));
    $this->assertFalse($manager->checkNamedRoute('import_engine_ui.breaker_action', $parameters, $viewer));
    $this->assertTrue($manager->checkNamedRoute('import_engine_ui.breaker_action', $parameters, $admin));
  }

  /**
   * Submits the confirmation form of an action.
   */
  protected function submit(string $action, string $endpoint): void {
    $form_state = new FormState();
    $form_state->addBuildInfo('args', [$action, $endpoint]);
    $this->container->get('form_builder')->submitForm(BreakerActionForm::class, $form_state);
  }

}
