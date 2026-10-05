<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine_ui\Kernel;

use Drupal\import_engine\Run\RunStatus;
use Drupal\Core\Form\FormState;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
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
      'test_source' => 'Try the source',
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
   * A mistake only the schema sees is listed, in words, and its step opens.
   */
  public function testSchemaProblemsAreShownAtTheirStep(): void {
    $state = $this->toLastStep();
    $state = $this->press($state, [], 'previous');
    $rows = $this->step4()['rows'];
    $rows[0]['mapper']['sources']['value'] = 'name..bad';
    $state = $this->press($state, ['rows' => $rows], 'save');

    $this->assertNull(ImportDefinition::load('accounts'));
    $this->assertSame(4, $state->get('step'));
    $this->assertSame([], $this->messages(), 'The problems are in the form, not in messages.');
    $form = $this->buildAt($state);
    $this->assertArrayHasKey('summary', $form);
    $texts = array_column($form['summary']['step_4']['#items'], '#plain_text');
    $this->assertCount(1, $texts);
    $this->assertStringStartsWith('Mapping, row 1 (title): ', $texts[0]);
    $this->assertStringContainsString('Dotted path in the source item', $texts[0]);
    $this->assertSame('Step 4: Mapping', (string) $form['summary']['step_4']['#title']);
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
    $this->assertSame(['edit', 'run', 'runs', 'delete'], array_keys($row[5]['data']['#links']));

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

  /**
   * Lets the source answer with the given items, for the next try.
   *
   * @param list<array<string, mixed>> $items
   *   The items the source returns.
   */
  protected function sourceReturns(array $items): void {
    $response = new Response(200, ['Content-Type' => 'application/json'], json_encode(['data' => $items], JSON_THROW_ON_ERROR));
    $this->container->set('http_client', new Client(['handler' => HandlerStack::create(new MockHandler([$response]))]));
  }

  /**
   * The values of step 2 without paging and without authentication.
   *
   * @return array<string, mixed>
   *   The values.
   */
  protected function plainStep2(): array {
    return [
      'pagination' => ['plugin' => 'none', 'settings' => []],
      'authentication' => ['plugin' => 'none', 'settings' => []],
    ];
  }

  /**
   * Trying the source shows what came back and the paths of its items.
   */
  public function testTryTheSource(): void {
    $this->sourceReturns([
      ['code' => 'C-1', 'name' => 'Acme', 'extra' => ['title' => 'T']],
      ['code' => 'C-2', 'name' => 'Globex'],
    ]);
    $state = $this->press(NULL, $this->step1(), 'next');

    $state = $this->press($state, $this->plainStep2(), 'test_source');

    $this->assertSame(2, $state->get('step'), 'Trying the source does not leave the step.');
    $sample = $state->get('source_sample');
    $this->assertSame(['code', 'name', 'extra.title'], array_keys($sample['paths']));
    $this->assertSame(2, $sample['items']);
    $this->assertSame('none', $state->get('definition')['pagination']['plugin'], 'What was filled in is kept.');
    $render_state = new FormState();
    $render_state->setStorage($state->getStorage());
    $form = $this->container->get('form_builder')->buildForm(DefinitionWizardForm::class, $render_state);
    $html = (string) $this->container->get('renderer')->renderRoot($form);
    $this->assertStringContainsString('Paths in 2 sample items', $html);
    $this->assertStringContainsString('Connected: the first page holds 2 items.', $html);
    $this->assertStringContainsString('extra.title', $html);
  }

  /**
   * A source that does not answer is shown as a message on the step.
   */
  public function testTryTheSourceThatFails(): void {
    $this->container->set('http_client', new Client(['handler' => HandlerStack::create(new MockHandler([new Response(403)]))]));
    $state = $this->press(NULL, $this->step1(), 'next');

    $state = $this->press($state, $this->plainStep2(), 'test_source');

    $sample = $state->get('source_sample');
    $this->assertSame([], $sample['paths']);
    $this->assertSame('error', $sample['messages'][0]['severity']);
    $this->assertStringContainsString('HTTP 403', $sample['messages'][0]['message']);
  }

  /**
   * The key can be tried too, before the step is left.
   */
  public function testTryTheKey(): void {
    $this->sourceReturns([['code' => 'C-1'], ['code' => 'C-2']]);
    $state = $this->press(NULL, $this->step1(), 'next');
    $state = $this->press($state, $this->plainStep2(), 'next');

    $state = $this->press($state, ['source_key' => 'missing'], 'test_source');

    $this->assertSame(3, $state->get('step'));
    $severities = array_column($state->get('source_sample')['messages'], 'severity');
    $this->assertContains('error', $severities, 'The items have no such key.');
    $this->assertSame(['missing'], $state->get('definition')['source_key']);
    // The paths found are named on the step, to choose from.
    $render_state = new FormState();
    $render_state->setStorage($state->getStorage());
    $form = $this->container->get('form_builder')->buildForm(DefinitionWizardForm::class, $render_state);
    $this->assertStringContainsString('Found in the sample: code.', (string) $form['source_key']['#description']);
  }

  /**
   * The mapping offers the paths found, and fills in the one that fits.
   */
  public function testMappingSuggestsPaths(): void {
    $this->sourceReturns([['code' => 'C-1', 'name' => 'Acme', 'extra' => ['title' => 'T']]]);
    $state = $this->press(NULL, $this->step1(), 'next');
    $state = $this->press($state, $this->plainStep2(), 'test_source');
    $state = $this->press($state, $this->plainStep2(), 'next');
    $state = $this->press($state, ['source_key' => 'code', 'target' => $this->step3()['target']], 'next');
    $state = $this->press($state, [], 'add_mapping_row');
    $state = $this->press($state, [], 'add_mapping_row');

    $render_state = new FormState();
    $render_state->setStorage($state->getStorage());
    $render_state->setUserInput(['rows' => [0 => ['target_field' => 'field_code'], 1 => ['target_field' => 'title']]]);
    $form = $this->container->get('form_builder')->buildForm(DefinitionWizardForm::class, $render_state);
    $html = (string) $this->container->get('renderer')->renderRoot($form);

    // A path that is the field, and one whose last part is the field.
    $this->assertSame('code', $form['rows'][0]['mapper']['sources']['value']['#default_value']);
    $this->assertSame('extra.title', $form['rows'][1]['mapper']['sources']['value']['#default_value']);
    $this->assertStringContainsString('<datalist id="import-wizard-paths">', $html);
    $this->assertStringContainsString('<option value="extra.title">', $html);
    $this->assertSame('import-wizard-paths', $form['rows'][0]['mapper']['sources']['value']['#attributes']['list']);
  }

  /**
   * A row whose field was chosen keeps its mapper when another row is added.
   */
  public function testRowKeepsItsMapperWhileRowsChange(): void {
    $state = $this->press(NULL, $this->step1(), 'next');
    $state = $this->press($state, $this->plainStep2(), 'next');
    $state = $this->press($state, $this->step3(), 'next');
    $state = $this->press($state, [], 'add_mapping_row');

    // The button drops the values; the input of the browser is still there.
    $render_state = new FormState();
    $render_state->setStorage($state->getStorage());
    $render_state->setUserInput(['rows' => [0 => ['target_field' => 'field_code']]]);
    $form = $this->container->get('form_builder')->buildForm(DefinitionWizardForm::class, $render_state);

    $this->assertArrayHasKey('mapper', $form['rows'][0]);
    $this->assertSame('field_code', $form['rows'][0]['target_field']['#default_value']);
  }

  /**
   * A row whose source path is missing is an error, not a dropped row.
   */
  public function testRowWithoutSourceIsAnError(): void {
    $state = $this->press(NULL, $this->step1(), 'next');
    $state = $this->press($state, $this->plainStep2(), 'next');
    $state = $this->press($state, $this->step3(), 'next');
    $state = $this->press($state, [], 'add_mapping_row');

    $rows = [
      0 => [
        'target_field' => 'title',
        'mapper' => ['plugin' => 'string', 'sources' => ['value' => ''], 'settings' => []],
      ],
    ];
    $next = $this->press($state, ['rows' => $rows], 'next');

    $this->assertSame(['rows][0][mapper][sources][value'], array_keys($next->getErrors()));
    $this->assertSame(4, $next->get('step'));
  }

  /**
   * Builds the form as the person sees it after a step.
   *
   * @param \Drupal\Core\Form\FormStateInterface $state
   *   The state after the step.
   * @param array<int, mixed> $args
   *   The arguments of the form: the definition to edit, if any.
   *
   * @return array<string, mixed>
   *   The form.
   */
  protected function buildAt(FormStateInterface $state, array $args = []): array {
    $render_state = new FormState();
    $render_state->setStorage($state->getStorage());
    $render_state->addBuildInfo('args', $args);
    return $this->container->get('form_builder')->buildForm(DefinitionWizardForm::class, $render_state);
  }

  /**
   * Presses a button of the menu of steps.
   *
   * The text of the button holds a mark that depends on the state, so it is
   * read from the form as it stands.
   *
   * @param \Drupal\Core\Form\FormStateInterface $previous
   *   The state before.
   * @param array<string, mixed> $values
   *   The values of the step that is left.
   * @param int $to
   *   The step to go to.
   */
  protected function jump(FormStateInterface $previous, array $values, int $to): FormStateInterface {
    $form = $this->buildAt($previous);
    $state = new FormState();
    $state->setStorage($previous->getStorage());
    $state->addBuildInfo('args', []);
    $state->setValues($values + ['goto_' . $to => (string) $form['steps']['goto_' . $to]['#value']]);
    $this->container->get('form_builder')->submitForm(DefinitionWizardForm::class, $state);
    return $state;
  }

  /**
   * The menu of steps goes to any step, and keeps what was typed.
   */
  public function testMenuGoesToAnyStep(): void {
    $state = $this->press(NULL, $this->step1(), 'next');
    $this->assertSame(2, $state->get('step'));

    $state = $this->jump($state, $this->plainStep2(), 5);

    $this->assertSame(5, $state->get('step'));
    $this->assertSame('none', $state->get('definition')['pagination']['plugin'], 'What was on the step that was left is kept.');
    $this->assertSame([1, 2, 5], $state->get('visited'));
    $back = $this->jump($state, $this->step5(), 1);
    $this->assertSame(1, $back->get('step'));
    $this->assertSame(30, $back->get('definition')['delete_threshold_percent']);
  }

  /**
   * Leaving a step that is not in order is possible, and not a mistake.
   */
  public function testLeavingAnUnfinishedStepIsAllowed(): void {
    $state = $this->press(NULL, $this->step1(), 'next');
    $state = $this->press($state, $this->step2(), 'next');
    // The key is still empty, which Next would refuse.
    $refused = $this->press($state, ['source_key' => '', 'target' => $this->step3()['target']], 'next');
    $this->assertArrayHasKey('source_key', $refused->getErrors());

    $left = $this->jump($state, ['source_key' => '', 'target' => $this->step3()['target']], 1);

    $this->assertSame([], $left->getErrors());
    $this->assertSame(1, $left->get('step'));
    $this->assertSame('node', $left->get('definition')['target']['configuration']['entity_type'] ?? NULL, 'The part that was fine is kept.');
  }

  /**
   * Going back never asks for the step to be finished.
   */
  public function testPreviousNeverBlocks(): void {
    $state = $this->press(NULL, $this->step1(), 'next');
    $values = $this->step2();
    $values['pagination']['plugin'] = 'offset_limit';
    $values['pagination']['settings'] = [
      'target' => 'query',
      'offset_param' => '',
      'limit_param' => '',
      'page_size' => '0',
    ];

    $back = $this->press($state, $values, 'previous');

    $this->assertSame([], $back->getErrors());
    $this->assertSame(1, $back->get('step'));
    $this->assertSame(0, $back->get('definition')['pagination']['configuration']['page_size'], 'It is kept as it was typed, and the schema says so on Save.');
  }

  /**
   * What cannot be read is not lost with the rest of the step.
   */
  public function testUnreadablePartKeepsItsValueAndTheRestIsKept(): void {
    $state = $this->press(NULL, $this->step1(), 'next');
    $state = $this->press($state, $this->step2(), 'previous');
    $values = $this->step1();
    $values['label'] = 'Changed name';
    $values['source']['settings']['headers'] = 'this is not a header';

    $left = $this->jump($state, $values, 3);

    $this->assertSame('Changed name', $left->get('definition')['label']);
    $this->assertSame(['Accept' => 'application/json'], $left->get('definition')['source']['configuration']['headers'], 'The source keeps what it had.');
    $this->assertStringContainsString('Not everything on step 1 could be kept: source', $this->messages()['warning'][0]);
  }

  /**
   * A box that is not ticked sends nothing; leaving a step counts it as off.
   */
  public function testUntickedBoxesAreOffWhenLeavingStep(): void {
    $state = $this->toLastStep();
    $values = $this->step5();
    unset($values['status'], $values['resilience']['dlq_enabled'], $values['breaker']['enabled']);

    $left = $this->jump($state, $values, 4);

    $stored = $left->get('definition');
    $this->assertFalse($stored['status']);
    $this->assertFalse($stored['resilience']['dlq_enabled']);
    $this->assertFalse($stored['breaker']['enabled']);
  }

  /**
   * The menu shows which steps are in order and which have a problem.
   */
  public function testMenuMarksStepsThatAreInOrderOrNot(): void {
    $state = $this->press(NULL, $this->step1(), 'next');
    $form = $this->buildAt($state);
    $this->assertSame('1. Source ✓', (string) $form['steps']['goto_1']['#value']);
    $this->assertContains('is-current', $form['steps']['goto_2']['#attributes']['class']);
    $this->assertSame('3. Key and target', (string) $form['steps']['goto_3']['#value'], 'A step that was not visited has no mark.');

    // Step 3 is left without a key: it has problems from then on.
    $state = $this->press($state, $this->step2(), 'next');
    $state = $this->jump($state, ['source_key' => '', 'target' => $this->step3()['target']], 4);
    $form = $this->buildAt($state);

    $this->assertStringStartsWith('3. Key and target ⚠ ', (string) $form['steps']['goto_3']['#value']);
    $this->assertContains('has-problems', $form['steps']['goto_3']['#attributes']['class']);
    $this->assertContains('is-ok', $form['steps']['goto_1']['#attributes']['class']);
    $this->assertArrayNotHasKey('summary', $form, 'The list of problems is for after a try to save.');
  }

  /**
   * An import that is new can be shown before anything is filled in.
   */
  public function testBlankImportCanBeShown(): void {
    $form = $this->container->get('form_builder')->getForm(DefinitionWizardForm::class);

    $this->assertSame(['goto_1', 'goto_2', 'goto_3', 'goto_4', 'goto_5'], array_keys(array_filter($form['steps'], static fn (mixed $key): bool => is_array($key) && isset($key['#goto']))));
    $this->assertSame('1. Source', (string) $form['steps']['goto_1']['#value'], 'The current step has no mark.');
  }

  /**
   * Adding and removing rows is done by AJAX, so the page stays where it is.
   */
  public function testRowButtonsAreAjax(): void {
    $state = $this->press(NULL, $this->step1(), 'next');
    $state = $this->press($state, $this->plainStep2(), 'next');
    $state = $this->press($state, $this->step3(), 'next');
    $state = $this->press($state, [], 'add_mapping_row');
    $form = $this->buildAt($state);

    $this->assertSame('import-wizard-rows', $form['add_row']['#ajax']['wrapper']);
    $this->assertSame('import-wizard-rows', $form['rows'][0]['remove']['#ajax']['wrapper']);
    $this->assertStringContainsString('id="import-wizard-rows"', $form['rows']['#prefix']);
    $wizard = $this->container->get('class_resolver')->getInstanceFromDefinition(DefinitionWizardForm::class);
    $trigger = new FormState();
    $trigger->setTriggeringElement(['#rows_key' => 'rows']);
    $this->assertSame($form['rows'], $wizard->ajaxRows($form, $trigger), 'The callback gives back the rows.');

    // The reports are on the last step.
    $last = $this->toLastStep();
    $form = $this->buildAt($last);
    $this->assertSame('import-wizard-reporters', $form['reporters']['add']['#ajax']['wrapper']);
    $trigger->setTriggeringElement(['#rows_key' => 'reporters']);
    $this->assertSame($form['reporters'], $wizard->ajaxRows($form, $trigger));
  }

  /**
   * Trying the source is AJAX too, and its panel is what gets replaced.
   */
  public function testTryTheSourceIsAjax(): void {
    $state = $this->press(NULL, $this->step1(), 'next');
    $form = $this->buildAt($state);

    $this->assertSame('import-wizard-source-test', $form['source_test']['test']['#ajax']['wrapper']);
    $this->assertStringContainsString('id="import-wizard-source-test"', $form['source_test']['#prefix']);
  }

}
