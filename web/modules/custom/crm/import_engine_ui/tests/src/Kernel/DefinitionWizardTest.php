<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine_ui\Kernel;

use Drupal\import_engine\Run\RunStatus;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\import_engine\Entity\ImportDefinition;
use Drupal\import_engine\Run\Trigger;
use Drupal\import_engine_ui\Controller\DefinitionController;
use Drupal\import_engine_ui\Form\DefinitionDeleteForm;
use Drupal\import_engine_ui\Form\DefinitionWizardForm;
use Drupal\Tests\import_engine\Kernel\NodeTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the wizard that creates and edits import definitions.
 */
#[Group('import_engine_ui')]
#[RunTestsInSeparateProcesses]
class DefinitionWizardTest extends NodeTestBase {

  use UserCreationTrait;

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
    $this->container->get('router.builder')->rebuild();
    $this->setUpCurrentUser(['name' => 'alice'], ['administer import definitions', 'administer import runs']);
  }

  /**
   * Submits one step, continuing from the state of the previous one.
   *
   * @param \Drupal\Core\Form\FormStateInterface|null $previous
   *   The state after the previous step; none for the first step.
   * @param array<string, mixed> $values
   *   The values of the step.
   * @param string $button
   *   The name of the button: previous, next, save or a row button.
   * @param array<int, mixed> $args
   *   The arguments of the form: the definition to edit, if any.
   */
  protected function press(?FormStateInterface $previous, array $values, string $button, array $args = []): FormStateInterface {
    $labels = [
      'previous' => 'Previous',
      'next' => 'Next',
      'save' => 'Save',
      'add_mapping_row' => 'Add a field',
      'add_reporter' => 'Add a report',
    ];
    $state = new FormState();
    if ($previous !== NULL) {
      $state->setStorage($previous->getStorage());
    }
    $state->addBuildInfo('args', $args);
    $state->setValues($values + [$button => $labels[$button]]);
    $builder = $this->container->get('form_builder');
    $builder->submitForm(DefinitionWizardForm::class, $state);
    return $state;
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
   * The values of step 1.
   *
   * @return array<string, mixed>
   *   The values.
   */
  protected function step1(): array {
    return [
      'label' => 'Accounts',
      'id' => 'accounts',
      'description' => 'Customers from site A.',
      'source' => [
        'plugin' => 'http',
        'settings' => [
          'url' => 'https://site-a.test/api/customers',
          'method' => 'GET',
          'headers' => 'Accept: application/json',
          'query' => 'status=1',
          'body' => '',
          'items_path' => 'data',
          'format' => 'json',
          'csv_delimiter' => ',',
          'timeout' => '20',
        ],
      ],
    ];
  }

  /**
   * The values of step 2.
   *
   * @return array<string, mixed>
   *   The values.
   */
  protected function step2(): array {
    return [
      'pagination' => [
        'plugin' => 'offset_limit',
        'settings' => [
          'target' => 'query',
          'offset_param' => 'offset',
          'limit_param' => 'limit',
          'page_size' => '25',
          'total_path' => 'meta.total',
          // An unchecked box sends nothing.
          'stop_on_short_page' => NULL,
        ],
      ],
      'authentication' => [
        'plugin' => 'api_key_header',
        'settings' => ['header' => 'api-key', 'env_var' => 'SITE_A_API_KEY'],
      ],
    ];
  }

  /**
   * The values of step 3.
   *
   * @return array<string, mixed>
   *   The values.
   */
  protected function step3(): array {
    return [
      'source_key' => "customer_code\n",
      'target' => ['plugin' => 'entity', 'settings' => ['content' => 'node:account', 'owner' => 'importer (1)']],
    ];
  }

  /**
   * The values of step 4: two fields.
   *
   * @return array<string, mixed>
   *   The values.
   */
  protected function step4(): array {
    return [
      'rows' => [
        0 => [
          'target_field' => 'title',
          'mapper' => [
            'plugin' => 'string',
            'sources' => ['value' => 'name'],
            'settings' => ['trim' => 1, 'empty_as_null' => 1],
          ],
        ],
        1 => [
          'target_field' => 'field_code',
          'mapper' => [
            'plugin' => 'string',
            'sources' => ['value' => 'customer_code'],
            'settings' => ['trim' => 1, 'empty_as_null' => NULL],
          ],
        ],
      ],
    ];
  }

  /**
   * The values of step 5.
   *
   * @return array<string, mixed>
   *   The values.
   */
  protected function step5(): array {
    return [
      'status' => 1,
      'delete_policy' => 'unpublish',
      'delete_threshold_percent' => '30',
      'pool' => 'default',
      'resilience' => [
        'max_attempts' => '4',
        'backoff' => 'linear',
        'retry_delay' => '30',
        'dlq_enabled' => 1,
        'max_repeated_pages' => '3',
      ],
      'breaker' => ['enabled' => 1, 'threshold' => '4', 'cooldown' => '90'],
      'reporters' => [0 => ['plugin' => 'log', 'settings' => ['only_on_problems' => 1]]],
    ];
  }

  /**
   * Walks through steps 1 to 4 and returns the state at step 5.
   */
  protected function toLastStep(): FormStateInterface {
    $state = $this->press(NULL, $this->step1(), 'next');
    $state = $this->press($state, $this->step2(), 'next');
    $state = $this->press($state, $this->step3(), 'next');
    $state = $this->press($state, [], 'add_mapping_row');
    $state = $this->press($state, [], 'add_mapping_row');
    $state = $this->press($state, $this->step4(), 'next');
    $this->assertSame(5, $state->get('step'));
    return $state;
  }

  /**
   * A definition made in five steps is saved as it was filled in.
   */
  public function testCreateDefinition(): void {
    $state = $this->toLastStep();
    // Nothing is saved before the last step.
    $before = ImportDefinition::load('accounts');
    $this->assertNull($before);

    // Reporters start empty: one is added, then the step is saved.
    $state = $this->press($state, [], 'add_reporter');
    $this->press($state, $this->step5(), 'save');

    $this->assertSame(['status' => ['The import Accounts is saved.']], $this->messages());
    $definition = ImportDefinition::load('accounts');
    $this->assertNotNull($definition);
    $this->assertSame('Customers from site A.', $definition->getDescription());
    $this->assertSame(
      [
        'plugin' => 'http',
        'configuration' => [
          'url' => 'https://site-a.test/api/customers',
          'method' => 'GET',
          'headers' => ['Accept' => 'application/json'],
          'query' => ['status' => '1'],
          'body' => '',
          'items_path' => 'data',
          'timeout' => 20,
          'format' => 'json',
          'csv_delimiter' => ',',
        ],
      ],
      $definition->getSource(),
    );
    $this->assertSame('offset_limit', $definition->getPagination()['plugin']);
    $this->assertSame(25, $definition->getPagination()['configuration']['page_size']);
    $this->assertSame([
      'plugin' => 'api_key_header',
      'configuration' => ['header' => 'api-key', 'env_var' => 'SITE_A_API_KEY'],
    ], $definition->getAuthentication());
    $this->assertSame(['customer_code'], $definition->getSourceKey());
    $this->assertSame([
      'plugin' => 'entity',
      'configuration' => ['entity_type' => 'node', 'bundle' => 'account', 'owner' => 1],
    ], $definition->getTarget());
    $mapping = $definition->getMapping();
    $this->assertCount(2, $mapping);
    $this->assertSame('title', $mapping[0]['target_field']);
    $this->assertSame(['value' => 'name'], $mapping[0]['mapper']['sources']);
    $this->assertSame(['trim' => TRUE, 'empty_as_null' => FALSE], $mapping[1]['mapper']['settings']);
    $this->assertSame(30, $definition->getDeleteThresholdPercent());
    $this->assertSame(4, $definition->getMaxAttempts());
    $this->assertSame(['enabled' => TRUE, 'threshold' => 4, 'cooldown' => 90], $definition->getBreaker());
    $this->assertSame([['plugin' => 'log', 'configuration' => ['only_on_problems' => TRUE]]], $definition->getReporters());
    $this->assertCount(0, $definition->getTypedData()->validate());
  }

  /**
   * What was filled in on a step is still there after going back and forth.
   */
  public function testStepsKeepTheirValues(): void {
    $state = $this->press(NULL, $this->step1(), 'next');
    $state = $this->press($state, $this->step2(), 'previous');
    $this->assertSame(1, $state->get('step'));

    $stored = $state->get('definition');
    $this->assertSame('Accounts', $stored['label']);
    $this->assertSame('offset_limit', $stored['pagination']['plugin']);
    $this->assertSame(25, $stored['pagination']['configuration']['page_size']);
    $this->assertSame(['Accept' => 'application/json'], $stored['source']['configuration']['headers']);
  }

  /**
   * A step with a mistake is not left, and says what is wrong.
   */
  public function testMistakesKeepYouOnTheStep(): void {
    $values = $this->step1();
    $values['source']['settings']['headers'] = 'nonsense';
    $state = $this->press(NULL, $values, 'next');

    $this->assertSame(['source][settings][headers'], array_keys($state->getErrors()));
    $this->assertSame(1, $state->get('step'));
  }

  /**
   * The key needs one to five paths.
   */
  public function testKeyNeedsPaths(): void {
    $state = $this->press(NULL, $this->step1(), 'next');
    $state = $this->press($state, $this->step2(), 'next');

    $none = $this->press($state, ['source_key' => '  ', 'target' => $this->step3()['target']], 'next');
    $this->assertArrayHasKey('source_key', $none->getErrors());

    $many = $this->press($state, ['source_key' => "a\nb\nc\nd\ne\nf", 'target' => $this->step3()['target']], 'next');
    $this->assertArrayHasKey('source_key', $many->getErrors());
  }

  /**
   * A field can be filled by one row only, and every row needs a field.
   */
  public function testMappingRowsAreChecked(): void {
    $state = $this->press(NULL, $this->step1(), 'next');
    $state = $this->press($state, $this->step2(), 'next');
    $state = $this->press($state, $this->step3(), 'next');
    $state = $this->press($state, [], 'add_mapping_row');
    $state = $this->press($state, [], 'add_mapping_row');

    $rows = $this->step4()['rows'];
    $rows[1]['target_field'] = 'title';
    $twice = $this->press($state, ['rows' => $rows], 'next');
    $this->assertSame(['rows][1][target_field'], array_keys($twice->getErrors()));

    $rows[1]['target_field'] = '';
    $empty = $this->press($state, ['rows' => $rows], 'next');
    $this->assertSame(['rows][1][target_field'], array_keys($empty->getErrors()));
  }

  /**
   * Rows can be added and removed without losing the others.
   */
  public function testAddAndRemoveRows(): void {
    $state = $this->press(NULL, $this->step1(), 'next');
    $state = $this->press($state, $this->step2(), 'next');
    $state = $this->press($state, $this->step3(), 'next');
    $state = $this->press($state, [], 'add_mapping_row');
    $state = $this->press($state, [], 'add_mapping_row');
    $this->assertSame([0, 1], $state->get('mapping_row_ids'));

    $remove = new FormState();
    $remove->setStorage($state->getStorage());
    $remove->setValues(['remove_mapping_row_0' => 'Remove this field']);
    $this->container->get('form_builder')->submitForm(DefinitionWizardForm::class, $remove);

    $this->assertSame([1], $remove->get('mapping_row_ids'));
    $again = $this->press($remove, [], 'add_mapping_row');
    $this->assertSame([1, 2], $again->get('mapping_row_ids'), 'A new row never takes the ID of a removed one.');
  }

  /**
   * A mistake only the schema sees is shown, and its step opens.
   */
  public function testSchemaProblemsAreShownAtTheirStep(): void {
    $state = $this->toLastStep();
    $state = $this->press($state, [], 'previous');
    $rows = $this->step4()['rows'];
    $rows[0]['mapper']['sources']['value'] = 'name..bad';
    $state = $this->press($state, ['rows' => $rows], 'save');

    $this->assertNull(ImportDefinition::load('accounts'));
    $this->assertSame(4, $state->get('step'));
    $errors = $this->messages()['error'];
    $this->assertStringContainsString('Mapping: mapping.0.mapper.sources.value', $errors[0]);
  }

  /**
   * An existing import is changed, and its ID stays.
   */
  public function testEditDefinition(): void {
    $this->toLastStep();
    $state = $this->toLastStep();
    $this->press($this->press($state, [], 'add_reporter'), $this->step5(), 'save');
    $this->messages();
    $definition = ImportDefinition::load('accounts');
    $this->assertNotNull($definition);

    $values = $this->step1();
    $values['label'] = 'Renamed accounts';
    $values['id'] = 'ignored';
    $state = $this->press(NULL, $values, 'save', [$definition]);

    $this->assertSame(['status' => ['The import Renamed accounts is saved.']], $this->messages());
    $this->assertNull(ImportDefinition::load('ignored'));
    $loaded = ImportDefinition::load('accounts');
    $this->assertSame('Renamed accounts', $loaded?->label());
    // What was not touched is as it was.
    $this->assertSame(['customer_code'], $loaded->getSourceKey());
    $this->assertCount(2, $loaded->getMapping());
    $this->assertSame(1, $state->get('step'));
  }

  /**
   * Choosing another plugin replaces the settings; the callback finds the part.
   */
  public function testAjaxReturnsTheSectionOfTheChangedElement(): void {
    $form_state = new FormState();
    $form = $this->container->get('form_builder')->buildForm(DefinitionWizardForm::class, $form_state);
    $wizard = $this->container->get('class_resolver')->getInstanceFromDefinition(DefinitionWizardForm::class);
    $form_state->setTriggeringElement(['#array_parents' => ['source', 'plugin']]);

    $part = $wizard->ajaxElement($form, $form_state);

    $this->assertArrayHasKey('settings', $part);
    $this->assertArrayHasKey('url', $part['settings']);
    $this->assertSame('http', $form['source']['plugin']['#default_value']);
  }

  /**
   * The list shows the imports, and a viewer without the right cannot see it.
   */
  public function testList(): void {
    $this->toLastStep();
    $controller = $this->container->get('class_resolver')->getInstanceFromDefinition(DefinitionController::class);
    $empty = $controller->list();
    $this->assertSame([], $empty['table']['#rows']);

    $state = $this->toLastStep();
    $this->press($this->press($state, [], 'add_reporter'), $this->step5(), 'save');
    $rows = $controller->list()['table']['#rows'];
    $this->assertCount(1, $rows);
    $row = $rows[0];
    $this->assertSame(['Accounts', 'accounts', 'http', 'entity: node/account'], array_slice($row, 0, 4));

    $manager = $this->container->get('access_manager');
    $this->assertTrue($manager->checkNamedRoute('import_engine_ui.definitions', [], $this->createUser(['administer import definitions'])));
    $this->assertFalse($manager->checkNamedRoute('import_engine_ui.definitions', [], $this->createUser(['view import runs'])));
    $this->assertFalse($manager->checkNamedRoute('import_engine_ui.definition_add', [], $this->createUser(['administer import runs'])));
  }

  /**
   * An import is deleted after confirmation, but not while a run is going.
   */
  public function testDelete(): void {
    $this->definition($this->accounts())->save();
    $definition = ImportDefinition::load('customers');
    $this->assertNotNull($definition);
    $run = $this->container->get('import_engine.run_starter')->start($definition, Trigger::Drush);

    $state = new FormState();
    $state->addBuildInfo('args', [$definition]);
    $this->container->get('form_builder')->submitForm(DefinitionDeleteForm::class, $state);
    $this->assertStringContainsString('is not over', $this->messages()['error'][0]);
    $this->assertNotNull(ImportDefinition::load('customers'));

    $run->setSummary('stop')->transitionTo(RunStatus::Cancelled)->save();
    $state = new FormState();
    $state->addBuildInfo('args', [$definition]);
    $this->container->get('form_builder')->submitForm(DefinitionDeleteForm::class, $state);
    $this->assertSame(['status' => ['The import Customers is deleted.']], $this->messages());
    $this->assertNull(ImportDefinition::load('customers'));
  }

  /**
   * Every step can be rendered to HTML, with the fields a person expects.
   */
  public function testEveryStepRenders(): void {
    $expected = [
      1 => ['Step 1 of 5: Source', 'Name', 'URL', 'Headers'],
      2 => ['Step 2 of 5: Paging and authentication', 'Paging', 'Authentication', 'Type'],
      3 => ['Step 3 of 5: Key and target', 'Key of an item', 'Content type'],
      4 => ['Step 4 of 5: Mapping', 'Field 1', 'Fill the field', 'Add a field'],
      5 => ['Step 5 of 5: Behaviour', 'Circuit breaker', 'Attempts per item', 'Add a report'],
    ];
    $state = NULL;
    $values = [1 => $this->step1(), 2 => $this->step2(), 3 => $this->step3(), 4 => $this->step4()];
    for ($step = 1; $step <= 5; $step++) {
      if ($step === 4) {
        $state = $this->press($state, [], 'add_mapping_row');
      }
      $render_state = new FormState();
      $render_state->setStorage($state?->getStorage() ?? []);
      $form = $this->container->get('form_builder')->buildForm(DefinitionWizardForm::class, $render_state);
      $html = (string) $this->container->get('renderer')->renderRoot($form);

      foreach ($expected[$step] as $text) {
        $this->assertStringContainsString($text, $html, 'Step ' . $step);
      }
      if ($step < 5) {
        $state = $this->press($state, $values[$step], 'next');
      }
    }
  }

  /**
   * Editing shows the saved mapping, with its mapper and the paths filled in.
   */
  public function testEditRendersTheSavedMapping(): void {
    $state = $this->toLastStep();
    $this->press($this->press($state, [], 'add_reporter'), $this->step5(), 'save');
    $definition = ImportDefinition::load('accounts');
    $this->assertNotNull($definition);

    $state = $this->press(NULL, $this->step1(), 'next', [$definition]);
    $state = $this->press($state, $this->step2(), 'next', [$definition]);
    $state = $this->press($state, $this->step3(), 'next', [$definition]);
    $this->assertSame(4, $state->get('step'));
    $render_state = new FormState();
    $render_state->setStorage($state->getStorage());
    $render_state->addBuildInfo('args', [$definition]);
    $form = $this->container->get('form_builder')->buildForm(DefinitionWizardForm::class, $render_state);
    $html = (string) $this->container->get('renderer')->renderRoot($form);

    $this->assertStringContainsString('Field 1', $html);
    $this->assertStringContainsString('Field 2', $html);
    $this->assertStringContainsString('Source: value', $html);
    $this->assertStringContainsString('value="customer_code"', $html);
    $this->assertSame('title', $form['rows'][0]['target_field']['#default_value']);
    $this->assertSame('string', $form['rows'][1]['mapper']['plugin']['#default_value']);
  }

}
