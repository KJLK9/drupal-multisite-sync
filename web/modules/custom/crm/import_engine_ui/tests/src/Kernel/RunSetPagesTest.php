<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine_ui\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\import_engine\Entity\ImportDefinition;
use Drupal\import_engine\Entity\ImportRunSet;
use Drupal\import_engine\RunSet\SetProgress;
use Drupal\import_engine\RunSet\SetState;
use Drupal\import_engine_ui\Controller\RunSetController;
use Drupal\import_engine_ui\Form\DefinitionDeleteForm;
use Drupal\import_engine_ui\Form\RunSetDeleteForm;
use Drupal\import_engine_ui\Form\RunSetForm;
use Drupal\import_engine_ui\Form\RunSetRunForm;
use Drupal\import_engine_ui\Run\RunSetBatch;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the screens of run sets.
 */
#[Group('import_engine_ui')]
#[RunTestsInSeparateProcesses]
class RunSetPagesTest extends KernelTestBase {

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
    $this->installConfig(['system']);
    $this->container->get('router.builder')->rebuild();
    $this->setUpCurrentUser(['name' => 'alice'], ['administer import definitions', 'administer import runs']);
    foreach (['accounts', 'items', 'agreements'] as $id) {
      ImportDefinition::create([
        'id' => $id,
        'label' => ucfirst($id),
        'source' => ['plugin' => 'http', 'configuration' => ['url' => 'http://x.test/' . $id]],
        'source_key' => ['id'],
        'target' => [
          'plugin' => 'entity',
          'configuration' => [
            'entity_type' => 'node',
            'bundle' => 'account',
            'owner' => 0,
          ],
        ],
      ])->save();
    }
  }

  /**
   * Submits the form of a run set.
   *
   * @param array<string, mixed> $values
   *   The values.
   * @param string|null $id
   *   The set that is changed; none to add one.
   */
  protected function submit(array $values, ?string $id = NULL): FormStateInterface {
    $state = new FormState();
    $state->addBuildInfo('args', $id === NULL ? [] : [ImportRunSet::load($id)]);
    $state->set('rows', 4);
    $state->setValues($values + ['op' => 'Save']);
    $this->container->get('form_builder')->submitForm(RunSetForm::class, $state);
    return $state;
  }

  /**
   * The values of the form for rows of imports.
   *
   * @param array<int, array{0: string, 1: int}> $rows
   *   Per row the import and its weight.
   * @param array<string, mixed> $changes
   *   Values that replace the defaults.
   *
   * @return array<string, mixed>
   *   The values.
   */
  protected function values(array $rows, array $changes = []): array {
    $table = [];
    foreach ($rows as $delta => [$import, $weight]) {
      $table[$delta] = ['import' => $import, 'weight' => $weight];
    }
    return $changes + [
      'label' => 'Catalog',
      'id' => 'catalog',
      'description' => 'All of the catalog.',
      'imports' => $table,
      'stop_on_errors' => NULL,
    ];
  }

  /**
   * Returns the messages shown, by type, and forgets them.
   *
   * @return array<string, list<string>>
   *   The messages.
   */
  protected function messages(): array {
    $messenger = $this->container->get('messenger');
    $messages = [];
    foreach ($messenger->all() as $type => $list) {
      $messages[$type] = array_values(array_map('strval', $list));
    }
    $messenger->deleteAll();
    return $messages;
  }

  /**
   * A set keeps its imports in the order of their weights.
   */
  public function testAddSetInTheOrderOfTheWeights(): void {
    $state = $this->submit($this->values([['agreements', 5], ['', 0], ['accounts', -5], ['items', 0]]));

    $this->assertSame([], $state->getErrors());
    $set = ImportRunSet::load('catalog');
    $this->assertSame(['accounts', 'items', 'agreements'], $set?->getImports());
    $this->assertSame('All of the catalog.', $set->getDescription());
    $this->assertFalse($set->stopsOnErrors());
  }

  /**
   * Rows with the same weight stay in the order they are in.
   */
  public function testEqualWeightsKeepTheirOrder(): void {
    $this->submit($this->values([['items', 0], ['accounts', 0], ['agreements', 0]]));

    $this->assertSame(['items', 'accounts', 'agreements'], ImportRunSet::load('catalog')?->getImports());
  }

  /**
   * A set that does not hold together is not saved, and says why.
   */
  public function testInvalidSetIsRefused(): void {
    $state = $this->submit($this->values([['', 0], ['', 1]]));
    $this->assertNotSame([], $state->getErrors());
    $this->assertNull(ImportRunSet::load('catalog'));

    $state = $this->submit($this->values([['items', 0], ['items', 1]]));
    $this->assertNotSame([], $state->getErrors());
    $this->assertNull(ImportRunSet::load('catalog'));
  }

  /**
   * A set is changed; its ID stays.
   */
  public function testEditSet(): void {
    $this->submit($this->values([['accounts', 0], ['items', 1]]));

    $state = $this->submit($this->values([['items', 0], ['accounts', 1]], ['id' => 'other', 'stop_on_errors' => 1]), 'catalog');

    $this->assertSame([], $state->getErrors());
    $this->assertNull(ImportRunSet::load('other'));
    $set = ImportRunSet::load('catalog');
    $this->assertSame(['items', 'accounts'], $set?->getImports());
    $this->assertTrue($set->stopsOnErrors());
  }

  /**
   * The form shows the saved order, and a row to add to.
   */
  public function testFormShowsSavedImportsAndOneMoreRow(): void {
    $this->submit($this->values([['accounts', 0], ['items', 1]]));
    $state = new FormState();
    $state->addBuildInfo('args', [ImportRunSet::load('catalog')]);

    $form = $this->container->get('form_builder')->buildForm(RunSetForm::class, $state);

    $this->assertSame('accounts', $form['imports'][0]['import']['#default_value']);
    $this->assertSame('items', $form['imports'][1]['import']['#default_value']);
    $this->assertSame('', $form['imports'][2]['import']['#default_value']);
    $this->assertArrayNotHasKey(3, $form['imports']);
    $this->assertSame(['accounts', 'agreements', 'items'], array_keys(array_diff_key($form['imports'][0]['import']['#options'], ['' => 1])));
  }

  /**
   * A row is added by a button, which rebuilds the table.
   */
  public function testAnotherRowIsAdded(): void {
    $state = new FormState();
    $state->setValues(['op' => 'Add another import']);
    $state->setTriggeringElement(['#name' => 'add_import']);
    $state->set('rows', 2);
    $form = [];

    $this->container->get('class_resolver')->getInstanceFromDefinition(RunSetForm::class)->addRow($form, $state);

    $this->assertSame(3, $state->get('rows'));
    $this->assertTrue($state->isRebuilding());
  }

  /**
   * The list shows the imports of a set in order.
   */
  public function testList(): void {
    $this->submit($this->values([['accounts', 0], ['items', 1], ['agreements', 2]]));

    $page = $this->container->get('class_resolver')->getInstanceFromDefinition(RunSetController::class)->list();

    $this->assertSame('accounts → items → agreements', $page['table']['#rows'][0][2]);
    $this->assertSame(['run', 'edit', 'delete'], array_keys($page['table']['#rows'][0][4]['data']['#links']));
  }

  /**
   * A set is deleted after confirmation, and its imports stay.
   */
  public function testDeleteSet(): void {
    $this->submit($this->values([['accounts', 0]]));
    $state = new FormState();
    $state->addBuildInfo('args', [ImportRunSet::load('catalog')]);
    $state->setValues(['op' => 'Confirm']);

    $this->container->get('form_builder')->submitForm(RunSetDeleteForm::class, $state);

    $this->assertNull(ImportRunSet::load('catalog'));
    $this->assertNotNull(ImportDefinition::load('accounts'));
  }

  /**
   * An import in a set cannot be deleted from its form; the set is named.
   */
  public function testImportInSetCannotBeDeleted(): void {
    $this->submit($this->values([['accounts', 0]]));
    $state = new FormState();
    $state->addBuildInfo('args', [ImportDefinition::load('accounts')]);

    $form = $this->container->get('form_builder')->buildForm(DefinitionDeleteForm::class, $state);

    $this->assertStringContainsString('catalog', (string) $form['blocked']['#markup']);
    $this->assertArrayNotHasKey('submit', $form['actions']);

    $state = new FormState();
    $state->addBuildInfo('args', [ImportDefinition::load('accounts')]);
    $state->setValues(['op' => 'Confirm']);
    $this->container->get('form_builder')->submitForm(DefinitionDeleteForm::class, $state);
    $this->assertNotNull(ImportDefinition::load('accounts'));
  }

  /**
   * The run form says which imports run, in which order, and offers a full run.
   *
   * Submitting it sets a batch, which the Batch API runs at once for a form
   * that is submitted by a test; the batch itself is tested on its own.
   */
  public function testRunFormNamesTheImports(): void {
    $this->submit($this->values([['items', 0], ['accounts', 1]]));
    $state = new FormState();
    $state->addBuildInfo('args', [ImportRunSet::load('catalog')]);

    $form = $this->container->get('form_builder')->buildForm(RunSetRunForm::class, $state);

    $this->assertStringContainsString('items, accounts', (string) $form['description']['#markup']);
    $this->assertSame('checkbox', $form['full']['#type']);
  }

  /**
   * The batch works on the set and reports how it went.
   */
  public function testBatchStopsWhenTheSetIsGone(): void {
    $batch = $this->container->get('import_engine_ui.run_set_batch');
    $context = ['results' => []];

    $batch->drive('ghost', FALSE, $context);
    $this->assertSame(1, $context['finished']);
    $batch->finished(TRUE, $context['results'], []);

    $this->assertSame(['error' => ['The run set no longer exists.']], $this->messages());
  }

  /**
   * The batch hands the runner's progress on and ends when the set stops.
   */
  public function testBatchEndsWhenTheSetStops(): void {
    $this->submit($this->values([['accounts', 0]]));
    ImportDefinition::load('accounts')?->setStatus(FALSE)->save();
    $batch = $this->container->get('import_engine_ui.run_set_batch');
    $context = ['results' => []];

    $batch->drive('catalog', FALSE, $context);

    $this->assertSame(1, $context['finished']);
    $progress = $context['results']['progress'];
    $this->assertSame(SetState::Stopped, $progress->state);
    $this->assertSame('The import "accounts" is disabled.', $progress->message);
  }

  /**
   * The finished callback tells what every import did.
   */
  public function testBatchReportsTheOutcomes(): void {
    $progress = new SetProgress(1, NULL, [
      ['import' => 'accounts', 'run' => 1, 'status' => 'completed', 'summary' => ''],
      ['import' => 'items', 'run' => 2, 'status' => 'failed', 'summary' => ''],
    ], SetState::Stopped, 'The import "items" ended as failed, so the imports after it did not run.');

    $this->container->get('import_engine_ui.run_set_batch')->finished(TRUE, ['progress' => $progress], []);

    $messages = $this->messages();
    $this->assertSame(['accounts: completed.'], $messages['status']);
    $this->assertSame(['items: failed.'], $messages['warning']);
    $this->assertStringContainsString('did not run', $messages['error'][0]);
  }

  /**
   * The batch class is a service the Batch API can call.
   */
  public function testBatchServiceExists(): void {
    $this->assertInstanceOf(RunSetBatch::class, $this->container->get('import_engine_ui.run_set_batch'));
  }

}
