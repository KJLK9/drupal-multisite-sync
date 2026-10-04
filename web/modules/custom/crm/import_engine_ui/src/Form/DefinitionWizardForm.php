<?php

declare(strict_types=1);

namespace Drupal\import_engine_ui\Form;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\Url;
use Drupal\import_engine\BackoffStrategy;
use Drupal\import_engine\DeletePolicy;
use Drupal\import_engine\Entity\ImportDefinition;
use Drupal\import_engine\Form\TextLists;
use Drupal\import_engine\ImportDefinitionInterface;
use Drupal\import_engine\Mapper\MapperPluginManager;
use Drupal\import_engine\Source\SourceSampler;
use Drupal\import_engine\Target\TargetField;
use Drupal\import_engine\Target\TargetInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Creates and edits an import definition in five steps.
 *
 * 1. Source: name and where the items come from.
 * 2. Paging and authentication.
 * 3. The key of an item, and where the items are written.
 * 4. The mapping: which source value fills which field, and how.
 * 5. Behaviour: what happens to what disappears, retries, the circuit breaker
 *    and who is told how a run went.
 *
 * Nothing is saved before the last step: the definition lives in the form
 * state, each step is checked when it is left, and the whole definition is
 * checked against its config schema before it is saved, so a half made
 * definition never reaches the configuration. The settings of every plugin are
 * the form that plugin describes itself (ADR 0014).
 */
final class DefinitionWizardForm extends FormBase {

  use AutowireTrait;

  /**
   * The steps, by number.
   */
  private const STEPS = [
    1 => 'Source',
    2 => 'Paging and authentication',
    3 => 'Key and target',
    4 => 'Mapping',
    5 => 'Behaviour',
  ];

  /**
   * The step that holds each part of the definition, for error messages.
   */
  private const STEP_OF = [
    'id' => 1,
    'label' => 1,
    'description' => 1,
    'source' => 1,
    'pagination' => 2,
    'authentication' => 2,
    'source_key' => 3,
    'target' => 3,
    'mapping' => 4,
  ];

  /**
   * Constructs the form.
   */
  public function __construct(
    #[Autowire(service: 'entity_type.manager')]
    protected EntityTypeManagerInterface $entityTypes,
    #[Autowire(service: 'plugin.manager.import_engine_source')]
    protected DefaultPluginManager $sources,
    #[Autowire(service: 'plugin.manager.import_engine_pagination')]
    protected DefaultPluginManager $paginations,
    #[Autowire(service: 'plugin.manager.import_engine_authentication')]
    protected DefaultPluginManager $authentications,
    #[Autowire(service: 'plugin.manager.import_engine_target')]
    protected DefaultPluginManager $targets,
    #[Autowire(service: 'plugin.manager.import_engine_mapper')]
    protected MapperPluginManager $mappers,
    #[Autowire(service: 'plugin.manager.import_engine_reporter')]
    protected DefaultPluginManager $reporters,
    #[Autowire(service: 'import_engine.source_sampler')]
    protected SourceSampler $sampler,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'import_engine_ui_definition_wizard';
  }

  /**
   * {@inheritdoc}
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\import_engine\ImportDefinitionInterface|null $import_definition
   *   The definition to edit, from the route; none to create one.
   *
   * @return array<string, mixed>
   *   The form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?ImportDefinitionInterface $import_definition = NULL): array {
    if ($form_state->get('definition') === NULL) {
      $form_state->set('definition', $this->initialValues($import_definition));
      $form_state->set('is_new', $import_definition === NULL);
      $form_state->set('step', 1);
    }
    $step = (int) $form_state->get('step');

    $form['#tree'] = TRUE;
    $form['#attributes']['class'][] = 'import-definition-wizard';
    $form['progress'] = [
      '#markup' => '<p class="import-definition-wizard__progress"><strong>' . $this->t('Step @step of @total: @title', [
        '@step' => (string) $step,
        '@total' => (string) count(self::STEPS),
        '@title' => self::STEPS[$step],
      ]) . '</strong></p>',
    ];

    match ($step) {
      1 => $this->stepSource($form, $form_state),
      2 => $this->stepPagingAndAuthentication($form, $form_state),
      3 => $this->stepKeyAndTarget($form, $form_state),
      4 => $this->stepMapping($form, $form_state),
      default => $this->stepBehaviour($form, $form_state),
    };
    if ($step >= 2 && $step <= 4) {
      $this->sourceTestPanel($form, $form_state, $step);
    }

    $form['actions'] = ['#type' => 'actions', '#weight' => 100];
    if ($step > 1) {
      $form['actions']['previous'] = ['#type' => 'submit', '#value' => $this->t('Previous'), '#name' => 'previous'];
    }
    if ($step < count(self::STEPS)) {
      $form['actions']['next'] = ['#type' => 'submit', '#value' => $this->t('Next'), '#name' => 'next'];
    }
    $form['actions']['save'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
      '#name' => 'save',
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * Step 1: name and source.
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  private function stepSource(array &$form, FormStateInterface $form_state): void {
    $values = $this->values($form_state);
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#default_value' => $values['label'],
      '#required' => TRUE,
    ];
    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $values['id'],
      '#maxlength' => 64,
      '#disabled' => !$form_state->get('is_new'),
      '#machine_name' => ['exists' => [$this, 'exists'], 'source' => ['label']],
    ];
    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => $values['description'],
      '#rows' => 2,
    ];
    $form['source'] = $this->pluginSection(['source'], $this->sources, $values['source'], $form, $form_state, $this->t('Source'));
  }

  /**
   * Step 2: paging and authentication.
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  private function stepPagingAndAuthentication(array &$form, FormStateInterface $form_state): void {
    $values = $this->values($form_state);
    $form['pagination'] = $this->pluginSection(['pagination'], $this->paginations, $values['pagination'], $form, $form_state, $this->t('Paging'));
    $form['authentication'] = $this->pluginSection(['authentication'], $this->authentications, $values['authentication'], $form, $form_state, $this->t('Authentication'));
  }

  /**
   * Step 3: the key of an item and the target.
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  private function stepKeyAndTarget(array &$form, FormStateInterface $form_state): void {
    $values = $this->values($form_state);
    $form['source_key'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Key of an item'),
      '#description' => $this->t('The dotted paths of the values that together identify an item, one per line, at most five. A single path, such as <code>customer_code</code>, is the usual case.') . $this->pathHint($form_state),
      '#default_value' => TextLists::formatLines($values['source_key']),
      '#rows' => 2,
      '#required' => TRUE,
    ];
    $form['target'] = $this->pluginSection(['target'], $this->targets, $values['target'], $form, $form_state, $this->t('Target'));
  }

  /**
   * Step 4: the mapping.
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  private function stepMapping(array &$form, FormStateInterface $form_state): void {
    $values = $this->values($form_state);
    $fields = $this->targetFields($values['target']);
    if (!is_array($fields)) {
      $form['problem'] = ['#markup' => '<p>' . $fields . '</p>'];
      return;
    }

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Each row fills one field of the target. The mapper is chosen by the type of the field; a source is the dotted path of a value in the source item, for example <code>price.amount</code>.') . '</p>',
    ];
    $paths = $this->samplePaths($form_state);
    if ($paths !== []) {
      // The paths found in the sample, offered while typing a source.
      $form['path_list'] = [
        '#type' => 'inline_template',
        '#template' => '<datalist id="import-wizard-paths">{% for path in paths %}<option value="{{ path }}">{% endfor %}</datalist>',
        '#context' => ['paths' => array_keys($paths)],
      ];
    }
    $form['rows'] = ['#type' => 'container', '#tree' => TRUE];
    foreach ($this->rowIds($form_state, 'mapping_row_ids', count($values['mapping'])) as $id) {
      $form['rows'][$id] = $this->mappingRow($id, $values['mapping'][$id] ?? NULL, $fields, $form, $form_state);
    }
    $form['add_row'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add a field'),
      '#name' => 'add_mapping_row',
      '#limit_validation_errors' => [],
      '#submit' => ['::addMappingRow'],
    ];
  }

  /**
   * Step 5: behaviour.
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  private function stepBehaviour(array &$form, FormStateInterface $form_state): void {
    $values = $this->values($form_state);
    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#default_value' => $values['status'],
    ];
    $form['delete_policy'] = [
      '#type' => 'select',
      '#title' => $this->t('What happens to items that disappear from the source'),
      '#options' => [
        DeletePolicy::Unpublish->value => $this->t('Unpublish them (they come back when the source has them again)'),
        DeletePolicy::Delete->value => $this->t('Delete them'),
        DeletePolicy::Ignore->value => $this->t('Leave them as they are'),
      ],
      '#default_value' => $values['delete_policy'],
    ];
    $form['delete_threshold_percent'] = [
      '#type' => 'number',
      '#title' => $this->t('Most items, in percent, that may disappear in one run'),
      '#description' => $this->t('When more go missing, nothing is changed and the run ends with errors: a source that suddenly returns a short list is more likely broken than right. 0 means no limit.'),
      '#default_value' => $values['delete_threshold_percent'],
      '#min' => 0,
      '#max' => 100,
    ];
    $form['pool'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Worker pool'),
      '#description' => $this->t('Only workers of this pool handle the items, for example to keep a heavy import apart.'),
      '#default_value' => $values['pool'],
      '#required' => TRUE,
    ];

    $resilience = $values['resilience'];
    $form['resilience'] = ['#type' => 'details', '#title' => $this->t('Retries'), '#open' => TRUE];
    $form['resilience']['max_attempts'] = [
      '#type' => 'number',
      '#title' => $this->t('Attempts per item'),
      '#default_value' => $resilience['max_attempts'],
      '#min' => 1,
      '#max' => 20,
    ];
    $form['resilience']['backoff'] = [
      '#type' => 'select',
      '#title' => $this->t('Waiting between attempts'),
      '#options' => [
        BackoffStrategy::Fixed->value => $this->t('The same every time'),
        BackoffStrategy::Linear->value => $this->t('Longer by a step each time'),
        BackoffStrategy::Exponential->value => $this->t('Twice as long each time'),
      ],
      '#default_value' => $resilience['backoff'],
    ];
    $form['resilience']['retry_delay'] = [
      '#type' => 'number',
      '#title' => $this->t('Seconds before the first retry'),
      '#default_value' => $resilience['retry_delay'],
      '#min' => 1,
    ];
    $form['resilience']['dlq_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Keep items that ran out of attempts in the dead letter queue'),
      '#description' => $this->t('With their payload, so they can be fixed and retried. Without it they are only counted as failed.'),
      '#default_value' => $resilience['dlq_enabled'],
    ];
    $form['resilience']['max_repeated_pages'] = [
      '#type' => 'number',
      '#title' => $this->t('Same page this many times in a row means the paging does not work'),
      '#default_value' => $resilience['max_repeated_pages'],
      '#min' => 1,
      '#max' => 10,
    ];

    $breaker = $values['breaker'];
    $form['breaker'] = ['#type' => 'details', '#title' => $this->t('Circuit breaker'), '#open' => TRUE];
    $form['breaker']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Stop calling the source when it is down'),
      '#default_value' => $breaker['enabled'],
    ];
    $form['breaker']['threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Failures in a row that open the breaker'),
      '#default_value' => $breaker['threshold'],
      '#min' => 1,
      '#max' => 100,
    ];
    $form['breaker']['cooldown'] = [
      '#type' => 'number',
      '#title' => $this->t('Seconds before the source is checked again'),
      '#default_value' => $breaker['cooldown'],
      '#min' => 1,
      '#max' => 3600,
    ];

    $form['reporters'] = [
      '#type' => 'details',
      '#title' => $this->t('Reports'),
      '#description' => $this->t('Who is told how a run went.'),
      '#open' => TRUE,
    ];
    foreach ($this->rowIds($form_state, 'reporter_row_ids', count($values['reporters'])) as $id) {
      $form['reporters'][$id] = $this->pluginSection([
        'reporters',
        (string) $id,
      ], $this->reporters, $values['reporters'][$id] ?? ['plugin' => '', 'configuration' => []], $form, $form_state, $this->t('Report'));
      $form['reporters'][$id]['remove'] = [
        '#type' => 'submit',
        '#value' => $this->t('Remove this report'),
        '#name' => 'remove_reporter_' . $id,
        '#limit_validation_errors' => [],
        '#submit' => ['::removeReporter'],
        '#row_id' => $id,
      ];
    }
    $form['reporters']['add'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add a report'),
      '#name' => 'add_reporter',
      '#limit_validation_errors' => [],
      '#submit' => ['::addReporter'],
    ];
  }

  /**
   * Builds one row of the mapping.
   *
   * @param int $id
   *   The ID of the row; it stays the same while rows come and go.
   * @param array<string, mixed>|null $row
   *   The saved row, if there is one.
   * @param array<string, \Drupal\import_engine\Target\TargetField> $fields
   *   The fields of the target.
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<string, mixed>
   *   The row.
   */
  private function mappingRow(int $id, ?array $row, array $fields, array &$form, FormStateInterface $form_state): array {
    $parents = ['rows', (string) $id];
    $options = [];
    foreach ($fields as $name => $field) {
      $options[$name] = $field->label . ($field->required ? ' *' : '') . ' (' . $field->type . ')';
    }
    $field_name = (string) ($this->input($form_state, [...$parents, 'target_field']) ?? $row['target_field'] ?? '');
    $field = $fields[$field_name] ?? NULL;

    $element = [
      '#type' => 'fieldset',
      '#title' => $this->t('Field @n', ['@n' => (string) ($id + 1)]),
      '#prefix' => '<div id="' . $this->wrapperId($parents) . '">',
      '#suffix' => '</div>',
    ];
    $element['target_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Fill the field'),
      '#options' => $options,
      '#empty_option' => $this->t('- Select -'),
      '#default_value' => $field_name,
      '#ajax' => ['callback' => '::ajaxElement', 'wrapper' => $this->wrapperId($parents)],
    ];
    if ($field !== NULL) {
      $mapper_ids = $this->mappers->idsForFieldType($field->type);
      $current = $row['mapper'] ?? ['plugin' => '', 'configuration' => []];
      $current['configuration'] = $current['settings'] ?? [];
      $suggestion = $this->suggestPath($field, $this->samplePaths($form_state));
      $element['mapper'] = $this->pluginSection([...$parents, 'mapper'], $this->mappers, $current, $form, $form_state, $this->t('Mapper'), $mapper_ids, $row['mapper']['sources'] ?? [], $suggestion);
    }
    $element['remove'] = [
      '#type' => 'submit',
      '#value' => $this->t('Remove this field'),
      '#name' => 'remove_mapping_row_' . $id,
      '#limit_validation_errors' => [],
      '#submit' => ['::removeMappingRow'],
      '#row_id' => $id,
    ];
    return $element;
  }

  /**
   * Builds the part of the form where a plugin is chosen and configured.
   *
   * The settings are the form that the plugin describes itself. Choosing
   * another plugin replaces them, without leaving the step.
   *
   * @param list<string> $parents
   *   Where the section sits in the form.
   * @param \Drupal\Core\Plugin\DefaultPluginManager $manager
   *   The plugin manager.
   * @param array<string, mixed> $current
   *   The plugin and configuration the definition has now.
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string $title
   *   The title of the section.
   * @param list<string>|null $only
   *   The plugins to choose from; all by default.
   * @param array<string, string>|null $sources
   *   For a mapper: the saved paths of its sources. NULL for other plugins.
   * @param string|null $suggestion
   *   For a mapper: a path found in the sample that probably is its first
   *   source, used when no path is saved.
   *
   * @return array<string, mixed>
   *   The section.
   */
  private function pluginSection(array $parents, DefaultPluginManager $manager, array $current, array &$form, FormStateInterface $form_state, mixed $title, ?array $only = NULL, ?array $sources = NULL, ?string $suggestion = NULL): array {
    $options = [];
    foreach ($manager->getDefinitions() as $id => $definition) {
      if ($only === NULL || in_array((string) $id, $only, TRUE)) {
        $options[(string) $id] = (string) ($definition['label'] ?? $id);
      }
    }
    $selected = (string) ($this->input($form_state, [...$parents, 'plugin']) ?? $current['plugin'] ?? '');
    if (!isset($options[$selected])) {
      $selected = (string) array_key_first($options);
    }

    $section = [
      '#type' => 'fieldset',
      '#title' => $title,
      '#prefix' => '<div id="' . $this->wrapperId($parents) . '">',
      '#suffix' => '</div>',
    ];
    $section['plugin'] = [
      '#type' => 'select',
      '#title' => $this->t('Type'),
      '#options' => $options,
      '#default_value' => $selected,
      '#ajax' => ['callback' => '::ajaxElement', 'wrapper' => $this->wrapperId($parents)],
    ];
    if ($selected === '') {
      return $section;
    }

    $definition = $manager->getDefinition($selected);
    if (!empty($definition['description'])) {
      $section['plugin']['#description'] = (string) $definition['description'];
    }
    if ($sources !== NULL) {
      $section['sources'] = ['#type' => 'container'];
      $first = TRUE;
      foreach ((array) ($definition['sources'] ?? []) as $name => $required) {
        $section['sources'][$name] = [
          '#type' => 'textfield',
          '#title' => $this->t('Source: @name', ['@name' => (string) $name]),
          '#description' => $this->t('Dotted path in the source item, for example <code>price.amount</code>.') . ($required ? '' : ' ' . $this->t('Optional.')),
          '#default_value' => $sources[$name] ?? ($first && $suggestion !== NULL ? $suggestion : ''),
          '#required' => (bool) $required,
          '#attributes' => ['list' => 'import-wizard-paths'],
        ];
        $first = FALSE;
      }
    }

    // The settings are what the plugin describes; they start from the saved
    // configuration only when the saved plugin is the chosen one.
    $configuration = ($current['plugin'] ?? '') === $selected ? (array) ($current['configuration'] ?? []) : [];
    $plugin = $manager->createInstance($selected, $configuration);
    $section['settings'] = ['#parents' => [...$parents, 'settings'], '#array_parents' => [...$parents, 'settings']];
    if ($plugin instanceof PluginFormInterface) {
      $subform_state = SubformState::createForSubform($section['settings'], $form, $form_state);
      $section['settings'] = $plugin->buildConfigurationForm($section['settings'], $subform_state);
    }
    return $section;
  }

  /**
   * Builds the part of the form where the source is tried.
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param int $step
   *   The current step.
   */
  private function sourceTestPanel(array &$form, FormStateInterface $form_state, int $step): void {
    $form['source_test'] = [
      '#type' => 'details',
      '#title' => $this->t('Try the source'),
      '#description' => $this->t('Reads a few pages with the settings so far, and shows what came back and the paths of the values in its items. Those paths are offered in the next steps.'),
      '#open' => $form_state->get('source_sample') !== NULL,
      '#weight' => 50,
    ];
    $form['source_test']['test'] = [
      '#type' => 'submit',
      '#value' => $this->t('Try the source'),
      '#name' => 'test_source',
      // A person who has not filled in this step yet may still try.
      '#limit_validation_errors' => match ($step) {
        2 => [['pagination'], ['authentication']],
        3 => [['source_key']],
        default => [],
      },
      '#submit' => ['::testSource'],
    ];

    $sample = $form_state->get('source_sample');
    if (!is_array($sample)) {
      return;
    }
    $items = [];
    foreach ($sample['messages'] as $message) {
      $items[] = ['#markup' => '<strong>' . ucfirst((string) $message['severity']) . ':</strong> ' . Html::escape((string) $message['message'])];
    }
    $form['source_test']['messages'] = ['#theme' => 'item_list', '#items' => $items];
    if ($sample['paths'] !== []) {
      $rows = [];
      foreach (array_slice($sample['paths'], 0, 100, TRUE) as $path => $info) {
        $rows[] = [$path, $info['type'], $info['example']];
      }
      $form['source_test']['paths'] = [
        '#type' => 'table',
        '#caption' => $this->t('Paths in @count sample items', ['@count' => (string) $sample['items']]),
        '#header' => [$this->t('Path'), $this->t('Type'), $this->t('Example')],
        '#rows' => $rows,
      ];
    }
  }

  /**
   * Tries the source with what has been filled in so far.
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function testSource(array &$form, FormStateInterface $form_state): void {
    $step = (int) $form_state->get('step');
    if ($step === 2) {
      $this->storeStep(2, $form, $form_state);
    }
    $values = $this->values($form_state);
    if ($step === 3) {
      $values['source_key'] = TextLists::lines((string) $form_state->getValue('source_key'));
      $form_state->set('definition', $values);
    }
    $sample = $this->sampler->sample(ImportDefinition::create($values + ['id' => 'draft', 'label' => 'draft']));
    $form_state->set('source_sample', [
      'messages' => $sample->messages,
      'paths' => $sample->paths,
      'items' => $sample->items,
    ]);
    $form_state->setRebuild();
  }

  /**
   * Returns the paths found in the last try of the source.
   *
   * @return array<string, array{type: string, example: string}>
   *   The paths with their type and an example.
   */
  private function samplePaths(FormStateInterface $form_state): array {
    $sample = $form_state->get('source_sample');
    return is_array($sample) ? $sample['paths'] : [];
  }

  /**
   * Returns a sentence that names some of the paths found in the sample.
   */
  private function pathHint(FormStateInterface $form_state): string {
    $paths = array_keys($this->samplePaths($form_state));
    if ($paths === []) {
      return '';
    }
    $shown = array_slice($paths, 0, 12);
    return ' ' . $this->t('Found in the sample: @paths.', ['@paths' => implode(', ', $shown) . (count($paths) > 12 ? ', …' : '')]);
  }

  /**
   * Finds the path in the sample that most likely holds a field's value.
   *
   * A path matches when its name, or its last part, is the name of the field
   * without the prefix of a field and the characters that differ in style: a
   * field "field_customer_code" and a path "customer.code" or "customerCode".
   *
   * @param \Drupal\import_engine\Target\TargetField $field
   *   The field.
   * @param array<string, array{type: string, example: string}> $paths
   *   The paths of the sample.
   */
  private function suggestPath(TargetField $field, array $paths): ?string {
    $normalize = static fn (string $text): string => strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $text));
    $wanted = $normalize(preg_replace('/^field_/', '', $field->name) ?? $field->name);
    if ($wanted === '') {
      return NULL;
    }
    foreach (array_keys($paths) as $path) {
      if ($normalize((string) $path) === $wanted) {
        return (string) $path;
      }
    }
    foreach (array_keys($paths) as $path) {
      if ($normalize((string) substr((string) strrchr('.' . $path, '.'), 1)) === $wanted) {
        return (string) $path;
      }
    }
    return NULL;
  }

  /**
   * AJAX callback: returns the part of the form around the changed element.
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<string, mixed>
   *   The part of the form to replace.
   */
  public function ajaxElement(array &$form, FormStateInterface $form_state): array {
    $trigger = $form_state->getTriggeringElement();
    $parents = array_slice((array) ($trigger['#array_parents'] ?? []), 0, -1);
    $part = NestedArray::getValue($form, $parents);
    return is_array($part) ? $part : [];
  }

  /**
   * Adds a row to the mapping.
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function addMappingRow(array &$form, FormStateInterface $form_state): void {
    $this->addRow($form_state, 'mapping_row_ids', count($this->values($form_state)['mapping']));
  }

  /**
   * Removes a row from the mapping.
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function removeMappingRow(array &$form, FormStateInterface $form_state): void {
    $this->removeRow($form_state, 'mapping_row_ids', count($this->values($form_state)['mapping']));
  }

  /**
   * Adds a report.
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function addReporter(array &$form, FormStateInterface $form_state): void {
    $this->addRow($form_state, 'reporter_row_ids', count($this->values($form_state)['reporters']));
  }

  /**
   * Removes a report.
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function removeReporter(array &$form, FormStateInterface $form_state): void {
    $this->removeRow($form_state, 'reporter_row_ids', count($this->values($form_state)['reporters']));
  }

  /**
   * Returns what a person chose in an element, also while values are limited.
   *
   * A button that skips validation (adding or removing a row) drops the
   * values of the other elements, but what was typed is still in the input;
   * the sections that depend on a choice must not vanish because of that.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array<int, string> $parents
   *   The parents of the element.
   */
  private function input(FormStateInterface $form_state, array $parents): mixed {
    return $form_state->getValue($parents) ?? NestedArray::getValue($form_state->getUserInput(), $parents);
  }

  /**
   * Returns the IDs of the rows of a list on this step.
   *
   * @return list<int>
   *   The IDs: those of the saved rows to begin with.
   */
  private function rowIds(FormStateInterface $form_state, string $key, int $saved): array {
    $ids = $form_state->get($key);
    if (!is_array($ids)) {
      $ids = $saved > 0 ? range(0, $saved - 1) : [];
      $form_state->set($key, $ids);
      $form_state->set($key . '_next', $saved);
    }
    return array_values(array_map(intval(...), $ids));
  }

  /**
   * Adds the ID of a new row, and rebuilds the form.
   */
  private function addRow(FormStateInterface $form_state, string $key, int $saved): void {
    $ids = $this->rowIds($form_state, $key, $saved);
    $next = (int) $form_state->get($key . '_next');
    $ids[] = $next;
    $form_state->set($key, $ids)->set($key . '_next', $next + 1)->setRebuild();
  }

  /**
   * Takes the ID of the row of the pressed button away, and rebuilds the form.
   */
  private function removeRow(FormStateInterface $form_state, string $key, int $saved): void {
    $remove = (int) ($form_state->getTriggeringElement()['#row_id'] ?? -1);
    $ids = array_values(array_filter($this->rowIds($form_state, $key, $saved), static fn (int $id): bool => $id !== $remove));
    $form_state->set($key, $ids)->setRebuild();
  }

  /**
   * {@inheritdoc}
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $button = (string) ($form_state->getTriggeringElement()['#name'] ?? '');
    $step = (int) $form_state->get('step');
    if (in_array($button, ['add_mapping_row', 'add_reporter'], TRUE) || str_starts_with($button, 'remove_') || ($button === 'test_source' && $step !== 2)) {
      return;
    }
    foreach ($this->sectionsOfStep($form_state) as $path => $manager) {
      $this->collectSection(explode('/', (string) $path), $manager, $form, $form_state, FALSE);
    }
    if ((int) $form_state->get('step') === 3) {
      $paths = TextLists::lines((string) $form_state->getValue('source_key'));
      if ($paths === [] || count($paths) > 5) {
        $form_state->setErrorByName('source_key', $this->t('Give one to five paths.'));
      }
    }
    if ((int) $form_state->get('step') === 4) {
      $this->validateMapping($form_state);
    }
  }

  /**
   * Checks the mapping rows: one row per field, and no source left empty.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  private function validateMapping(FormStateInterface $form_state): void {
    $seen = [];
    foreach ((array) $form_state->getValue('rows') as $id => $row) {
      $field = (string) ($row['target_field'] ?? '');
      if ($field === '') {
        $form_state->setErrorByName('rows][' . $id . '][target_field', $this->t('Choose the field this row fills, or remove the row.'));
        continue;
      }
      if (($row['mapper']['plugin'] ?? '') === '') {
        $form_state->setErrorByName('rows][' . $id . '][target_field', $this->t('Choose a mapper for the field "@field", or remove the row.', ['@field' => $field]));
        continue;
      }
      if (isset($seen[$field])) {
        $form_state->setErrorByName('rows][' . $id . '][target_field', $this->t('The field "@field" is filled by more than one row.', ['@field' => $field]));
      }
      $seen[$field] = TRUE;
    }
  }

  /**
   * {@inheritdoc}
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $step = (int) $form_state->get('step');
    $this->storeStep($step, $form, $form_state);

    $button = (string) ($form_state->getTriggeringElement()['#name'] ?? '');
    if ($button === 'previous' || $button === 'next') {
      $to = $button === 'next' ? $step + 1 : $step - 1;
      // Rows come from the saved definition again on the next step.
      $form_state->set('step', $to)->set('mapping_row_ids', NULL)->set('reporter_row_ids', NULL)->setRebuild();
      $form_state->setUserInput([]);
      return;
    }
    $this->save($form_state);
  }

  /**
   * Puts the values of a step in the definition.
   *
   * @param int $step
   *   The step.
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  private function storeStep(int $step, array $form, FormStateInterface $form_state): void {
    $values = $this->values($form_state);
    foreach ($this->sectionsOfStep($form_state) as $path => $manager) {
      $parents = explode('/', (string) $path);
      $section = $this->collectSection($parents, $manager, $form, $form_state, TRUE);
      if ($section !== NULL && count($parents) === 1) {
        $values[$parents[0]] = $section;
      }
    }

    if ($step === 1) {
      $values['label'] = (string) $form_state->getValue('label');
      if ($form_state->get('is_new')) {
        $values['id'] = (string) $form_state->getValue('id');
      }
      $values['description'] = (string) $form_state->getValue('description');
    }
    elseif ($step === 3) {
      $values['source_key'] = TextLists::lines((string) $form_state->getValue('source_key'));
    }
    elseif ($step === 4) {
      $values['mapping'] = $this->collectMapping($form, $form_state);
    }
    elseif ($step === 5) {
      $values['status'] = (bool) $form_state->getValue('status');
      $values['delete_policy'] = (string) $form_state->getValue('delete_policy');
      $values['delete_threshold_percent'] = (int) $form_state->getValue('delete_threshold_percent');
      $values['pool'] = (string) $form_state->getValue('pool');
      $resilience = (array) $form_state->getValue('resilience');
      $values['resilience'] = [
        'max_attempts' => (int) ($resilience['max_attempts'] ?? 0),
        'backoff' => (string) ($resilience['backoff'] ?? ''),
        'retry_delay' => (int) ($resilience['retry_delay'] ?? 0),
        'dlq_enabled' => (bool) ($resilience['dlq_enabled'] ?? FALSE),
        'max_repeated_pages' => (int) ($resilience['max_repeated_pages'] ?? 0),
      ];
      $breaker = (array) $form_state->getValue('breaker');
      $values['breaker'] = [
        'enabled' => (bool) ($breaker['enabled'] ?? FALSE),
        'threshold' => (int) ($breaker['threshold'] ?? 0),
        'cooldown' => (int) ($breaker['cooldown'] ?? 0),
      ];
      $values['reporters'] = $this->collectReporters($form, $form_state);
    }
    $form_state->set('definition', $values);
  }

  /**
   * Returns the plugin sections on the current step: their path and manager.
   *
   * @return array<string, \Drupal\Core\Plugin\DefaultPluginManager>
   *   The managers, keyed by the path of the section in the form (joined by /).
   */
  private function sectionsOfStep(FormStateInterface $form_state): array {
    return match ((int) $form_state->get('step')) {
      1 => ['source' => $this->sources],
      2 => ['pagination' => $this->paginations, 'authentication' => $this->authentications],
      3 => ['target' => $this->targets],
      default => [],
    };
  }

  /**
   * Validates, or submits, a plugin section.
   *
   * @param list<string> $parents
   *   Where the section is in the form.
   * @param \Drupal\Core\Plugin\DefaultPluginManager $manager
   *   The manager of the plugin.
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param bool $submit
   *   Whether to submit (and return the result) instead of validating.
   *
   * @return array{plugin: string, configuration: array<string, mixed>}|null
   *   The plugin and its configuration, when submitted.
   */
  private function collectSection(array $parents, DefaultPluginManager $manager, array $form, FormStateInterface $form_state, bool $submit): ?array {
    $values = $form_state->getValue($parents);
    $id = is_array($values) ? (string) ($values['plugin'] ?? '') : '';
    $section = NestedArray::getValue($form, $parents);
    if ($id === '' || !is_array($section) || !isset($section['settings'])) {
      return $submit ? ['plugin' => $id, 'configuration' => []] : NULL;
    }
    $plugin = $manager->createInstance($id, []);
    if (!$plugin instanceof PluginFormInterface || !$plugin instanceof ConfigurableInterface) {
      return $submit ? ['plugin' => $id, 'configuration' => []] : NULL;
    }
    $settings = $section['settings'];
    $subform_state = SubformState::createForSubform($settings, $form, $form_state);
    if (!$submit) {
      $plugin->validateConfigurationForm($settings, $subform_state);
      return NULL;
    }
    $plugin->submitConfigurationForm($settings, $subform_state);
    return ['plugin' => $id, 'configuration' => (array) $plugin->getConfiguration()];
  }

  /**
   * Reads the mapping rows of the form.
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return list<array<string, mixed>>
   *   The mapping, as the definition keeps it.
   */
  private function collectMapping(array $form, FormStateInterface $form_state): array {
    $mapping = [];
    foreach (array_keys((array) $form_state->getValue('rows')) as $id) {
      $parents = ['rows', (string) $id, 'mapper'];
      $section = $this->collectSection($parents, $this->mappers, $form, $form_state, TRUE);
      $row = (array) $form_state->getValue(['rows', (string) $id]);
      $sources = array_filter(array_map(static fn (mixed $path): string => trim((string) $path), (array) ($row['mapper']['sources'] ?? [])), static fn (string $path): bool => $path !== '');
      if ($section === NULL || $section['plugin'] === '') {
        continue;
      }
      $mapping[] = [
        'target_field' => (string) ($row['target_field'] ?? ''),
        'mapper' => ['plugin' => $section['plugin'], 'sources' => $sources, 'settings' => $section['configuration']],
      ];
    }
    return $mapping;
  }

  /**
   * Reads the reports of the form.
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return list<array{plugin: string, configuration: array<string, mixed>}>
   *   The reporters.
   */
  private function collectReporters(array $form, FormStateInterface $form_state): array {
    $reporters = [];
    foreach (array_keys((array) $form_state->getValue('reporters')) as $id) {
      if (!is_int($id) && !ctype_digit((string) $id)) {
        continue;
      }
      $section = $this->collectSection(['reporters', (string) $id], $this->reporters, $form, $form_state, TRUE);
      if ($section !== NULL && $section['plugin'] !== '') {
        $reporters[] = $section;
      }
    }
    return $reporters;
  }

  /**
   * Checks the whole definition against its schema, and saves it.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  private function save(FormStateInterface $form_state): void {
    $values = $this->values($form_state);
    $storage = $this->entityTypes->getStorage('import_definition');
    $definition = $form_state->get('is_new') ? ImportDefinition::create($values) : $storage->load($values['id']);
    if (!$definition instanceof ImportDefinition) {
      $this->messenger()->addError($this->t('The import no longer exists.'));
      return;
    }
    if (!$form_state->get('is_new')) {
      foreach ($values as $key => $value) {
        if ($key !== 'id') {
          $definition->set($key, $value);
        }
      }
    }

    $problems = [];
    $first = 5;
    foreach ($definition->getTypedData()->validate() as $violation) {
      $root = explode('.', $violation->getPropertyPath())[0];
      $step = self::STEP_OF[$root] ?? 5;
      $first = min($first, $step);
      $problems[] = $this->t('@step: @path: @message', [
        '@step' => self::STEPS[$step],
        '@path' => $violation->getPropertyPath(),
        '@message' => (string) $violation->getMessage(),
      ]);
    }
    if ($problems !== []) {
      foreach ($problems as $problem) {
        $this->messenger()->addError($problem);
      }
      $form_state->set('step', $first)->set('mapping_row_ids', NULL)->set('reporter_row_ids', NULL)->setRebuild();
      $form_state->setUserInput([]);
      return;
    }

    $definition->save();
    $this->messenger()->addStatus($this->t('The import @label is saved.', ['@label' => (string) $definition->label()]));
    $form_state->setRedirectUrl(Url::fromRoute('import_engine_ui.definitions'));
  }

  /**
   * Returns whether an import with this ID exists; for the machine name field.
   */
  public function exists(string $id): bool {
    return $this->entityTypes->getStorage('import_definition')->load($id) !== NULL;
  }

  /**
   * Returns the fields of the target, or why they are not known.
   *
   * @param array<string, mixed> $target
   *   The target of the definition.
   *
   * @return array<string, \Drupal\import_engine\Target\TargetField>|\Drupal\Core\StringTranslation\TranslatableMarkup
   *   The fields, or a message.
   */
  private function targetFields(array $target): array|\Stringable {
    try {
      $plugin = $this->targets->createInstance((string) ($target['plugin'] ?? ''), (array) ($target['configuration'] ?? []));
      return $plugin instanceof TargetInterface ? $plugin->fields() : $this->t('Choose a target first.');
    }
    catch (\Throwable $exception) {
      return $this->t('The target cannot be used yet: @message', ['@message' => $exception->getMessage()]);
    }
  }

  /**
   * Returns the definition as it is now, completed with defaults.
   *
   * @return array<string, mixed>
   *   The values of the definition.
   */
  private function values(FormStateInterface $form_state): array {
    /** @var array<string, mixed> $values */
    $values = $form_state->get('definition');
    return $values;
  }

  /**
   * Returns the values to start from: those of the import, or the defaults.
   *
   * @return array<string, mixed>
   *   The values.
   */
  private function initialValues(?ImportDefinitionInterface $definition): array {
    if ($definition !== NULL) {
      /** @var array<string, mixed> $values */
      $values = $definition->toArray();
      $values['status'] = $definition->status();
      return $values;
    }
    $blank = ImportDefinition::create(['id' => '', 'label' => '']);
    /** @var array<string, mixed> $values */
    $values = $blank->toArray();
    $values['source'] = ['plugin' => 'http', 'configuration' => []];
    $values['pagination'] = ['plugin' => 'none', 'configuration' => []];
    $values['authentication'] = ['plugin' => 'none', 'configuration' => []];
    $values['target'] = ['plugin' => 'entity', 'configuration' => []];
    $values['source_key'] = [];
    $values['mapping'] = [];
    $values['reporters'] = [];
    $values['status'] = TRUE;
    return $values;
  }

  /**
   * Returns the ID of the element that is replaced by an AJAX call.
   *
   * @param list<string> $parents
   *   Where the element is in the form.
   */
  private function wrapperId(array $parents): string {
    return 'import-wizard-' . implode('-', $parents);
  }

}
