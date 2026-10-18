<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine_ui\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\import_engine\Entity\ImportDefinition;
use Drupal\import_engine\Entity\ImportRunSet;
use Drupal\import_engine_ui\Form\BreakerActionForm;
use Drupal\import_engine_ui\Form\DefinitionDeleteForm;
use Drupal\import_engine_ui\Form\RunSetDeleteForm;
use Drupal\import_engine_ui\Form\RunSetRunForm;
use Drupal\Tests\import_engine\Kernel\NodeTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that the confirmation forms look like the other screens of the engine.
 */
#[Group('import_engine_ui')]
#[RunTestsInSeparateProcesses]
class ConfirmFormsTest extends NodeTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'money_field',
    'import_engine',
    'import_engine_ui',
  ];

  /**
   * Builds a form with arguments.
   *
   * @param class-string $class
   *   The form.
   * @param list<mixed> $args
   *   The arguments of the form.
   *
   * @return array<string, mixed>
   *   The form.
   */
  private function build(string $class, array $args): array {
    $state = new FormState();
    $state->addBuildInfo('args', $args);
    return $this->container->get('form_builder')->buildForm($class, $state);
  }

  /**
   * What cannot be undone is red and says so; the consequences are in a panel.
   */
  public function testDeletesAreMarkedAsFinal(): void {
    $set = ImportRunSet::create(['id' => 'daily', 'label' => 'Daily', 'imports' => []]);
    $set->save();
    $definition = ImportDefinition::create(['id' => 'alone', 'label' => 'Alone']);
    $definition->save();

    foreach ([[RunSetDeleteForm::class, $set], [DefinitionDeleteForm::class, $definition]] as [$class, $entity]) {
      $form = $this->build($class, [$entity]);

      $this->assertStringContainsString('Cannot be undone', (string) $form['confirm_header']['#markup'], $class);
      $this->assertStringContainsString('import-pill--failed', (string) $form['confirm_header']['#markup'], $class);
      $this->assertArrayNotHasKey('description', $form, 'The consequences are in the panel.');
      $this->assertNotSame([], $form['summary_plain']['box']['list']['#items'], $class);
      $this->assertSame('danger', $form['actions']['submit']['#button_type'], $class);
      $this->assertSame(['dialog-cancel'], $form['actions']['cancel']['#attributes']['class'], $class);
    }
  }

  /**
   * Opening a breaker asks for care, and closing it does not.
   */
  public function testBreakerActionsAreDressed(): void {
    $open = $this->build(BreakerActionForm::class, ['trip', 'api.test']);
    $close = $this->build(BreakerActionForm::class, ['reset', 'api.test']);

    $this->assertStringContainsString('Take care', (string) $open['confirm_header']['#markup']);
    $this->assertStringNotContainsString('Take care', (string) $close['confirm_header']['#markup']);
    $this->assertStringContainsString('Open the circuit breaker for api.test?', (string) $open['confirm_header']['#markup']);
    $this->assertSame('primary', $close['actions']['submit']['#button_type']);
  }

  /**
   * Starting a run keeps its own checkbox, and the plain look.
   */
  public function testRunFormKeepsItsOption(): void {
    $definition = ImportDefinition::create(['id' => 'a', 'label' => 'A']);
    $definition->save();
    $set = ImportRunSet::create(['id' => 'two', 'label' => 'Two', 'imports' => ['a']]);
    $set->save();

    $form = $this->build(RunSetRunForm::class, [$set]);

    $this->assertSame('checkbox', $form['full']['#type']);
    $this->assertStringNotContainsString('import-pill', (string) $form['confirm_header']['#markup']);
    $this->assertContains('import-engine-form', $form['#attributes']['class']);
  }

}
