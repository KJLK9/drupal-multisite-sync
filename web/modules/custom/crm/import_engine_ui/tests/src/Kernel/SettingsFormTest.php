<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine_ui\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\import_engine_ui\Form\SettingsForm;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the settings of the engine: retention and cron.
 */
#[Group('import_engine_ui')]
#[RunTestsInSeparateProcesses]
class SettingsFormTest extends KernelTestBase {

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
    $this->installConfig(['import_engine']);
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Submits the form.
   *
   * @param array<string, mixed> $values
   *   The values that replace the saved ones.
   */
  protected function submit(array $values): FormStateInterface {
    $state = new FormState();
    $state->setValues($values + [
      'retention_items_days' => 7,
      'retention_dead_days' => 90,
      'retention_events_days' => 365,
      'retention_runs_days' => 90,
      'cron_resume_seconds' => '0',
      'op' => 'Save configuration',
    ]);
    $this->container->get('form_builder')->submitForm(SettingsForm::class, $state);
    return $state;
  }

  /**
   * The form starts from what is saved.
   */
  public function testFormShowsTheSavedSettings(): void {
    $this->config('import_engine.settings')->set('retention_events_days', 30)->save();

    $form = $this->container->get('form_builder')->getForm(SettingsForm::class);

    $this->assertSame(7, $form['retention']['retention_items_days']['#default_value']);
    $this->assertSame(30, $form['retention']['retention_events_days']['#default_value']);
    $this->assertSame(0, $form['cron']['cron_resume_seconds']['#default_value']);
  }

  /**
   * Saving changes the settings the purge and cron read.
   */
  public function testSaving(): void {
    $state = $this->submit([
      // As a browser sends them: text.
      'retention_items_days' => '3',
      'retention_dead_days' => '0',
      'retention_events_days' => '730',
      'retention_runs_days' => '30',
      'cron_resume_seconds' => '120',
    ]);

    $this->assertSame([], $state->getErrors());
    $config = $this->config('import_engine.settings');
    $this->assertSame(3, $config->get('retention_items_days'));
    $this->assertSame(0, $config->get('retention_dead_days'));
    $this->assertSame(730, $config->get('retention_events_days'));
    $this->assertSame(30, $config->get('retention_runs_days'));
    $this->assertSame(120, $config->get('cron_resume_seconds'));
  }

  /**
   * A value outside what the schema allows is refused, and nothing is saved.
   */
  public function testOutOfRangeIsRefused(): void {
    $state = $this->submit(['retention_items_days' => '-1', 'cron_resume_seconds' => '5000']);

    $this->assertArrayHasKey('retention_items_days', $state->getErrors());
    $this->assertArrayHasKey('cron_resume_seconds', $state->getErrors());
    $this->assertSame(7, $this->config('import_engine.settings')->get('retention_items_days'));
  }

  /**
   * The settings are a tab of the engine, for those who may administer.
   */
  public function testSettingsAreTab(): void {
    $this->setUpCurrentUser(['name' => 'alice'], ['administer import definitions', 'view import runs']);

    $data = $this->container->get('plugin.manager.menu.local_task')->getLocalTasks('import_engine_ui.settings', 0);
    $titles = array_map(static fn (array $tab): string => (string) $tab['#link']['title'], $data['tabs']);

    $this->assertContains('Settings', $titles);
    $manager = $this->container->get('access_manager');
    $this->assertTrue($manager->checkNamedRoute('import_engine_ui.settings', [], $this->createUser(['administer import definitions'])));
    $this->assertFalse($manager->checkNamedRoute('import_engine_ui.settings', [], $this->createUser(['view import runs'])));
  }

}
