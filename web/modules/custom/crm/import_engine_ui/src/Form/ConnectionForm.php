<?php

declare(strict_types=1);

namespace Drupal\import_engine_ui\Form;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Config\Entity\ConfigEntityStorageInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\import_engine\Connection\ConnectionResolver;
use Drupal\import_engine\ImportConnectionInterface;
use Drupal\import_engine\Storage\ImportConnectionStorage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Adds and changes a connection.
 *
 * The source section shows only the settings that belong to a connection
 * (which the source plugin says); what the import asks for is set in the
 * import. The settings forms are those of the plugins, as in the wizard.
 */
final class ConnectionForm extends FormBase {

  use AutowireTrait;

  /**
   * The connection that is changed; none when one is added.
   */
  protected ?ImportConnectionInterface $connection = NULL;

  /**
   * Constructs the form.
   */
  public function __construct(
    #[Autowire(service: 'entity_type.manager')]
    protected EntityTypeManagerInterface $entityTypes,
    #[Autowire(service: 'plugin.manager.import_engine_source')]
    protected DefaultPluginManager $sources,
    #[Autowire(service: 'plugin.manager.import_engine_authentication')]
    protected DefaultPluginManager $authentications,
    #[Autowire(service: 'import_engine.connection_resolver')]
    protected ConnectionResolver $resolver,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'import_engine_ui_connection';
  }

  /**
   * {@inheritdoc}
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\import_engine\ImportConnectionInterface|null $import_connection
   *   The connection to change, from the route; none to add one.
   *
   * @return array<string, mixed>
   *   The form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?ImportConnectionInterface $import_connection = NULL): array {
    $this->connection = $import_connection;
    $form['#tree'] = TRUE;
    $form['#attributes']['class'][] = 'import-engine-form';
    $form['#attached']['library'][] = 'import_engine_ui/wizard';
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#default_value' => $import_connection?->label(),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];
    $form['id'] = [
      '#type' => 'machine_name',
      '#title' => $this->t('ID'),
      '#default_value' => $import_connection?->id(),
      '#machine_name' => ['exists' => [$this, 'exists'], 'source' => ['label']],
      '#disabled' => $import_connection !== NULL,
      '#maxlength' => 64,
    ];
    $form['description'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Description'),
      '#default_value' => $import_connection?->getDescription(),
      '#description' => $this->t('Where this connection goes, for the person who chooses it in an import.'),
    ];

    $used = $this->usedBy();
    if ($used !== []) {
      $form['used'] = [
        '#markup' => '<p>' . $this->t('Used by the imports @imports. A change here applies to all of them.', ['@imports' => implode(', ', $used)]) . '</p>',
        '#weight' => -10,
      ];
    }

    $source = $import_connection?->getSource() ?? ['plugin' => '', 'configuration' => []];
    $form['source'] = $this->section(
      ['source'],
      $this->sourceOptions(),
      $source,
      $this->sources,
      $form,
      $form_state,
      $this->t('Source'),
      TRUE,
    );
    $authentication = $import_connection?->getAuthentication() ?? ['plugin' => 'none', 'configuration' => []];
    $form['authentication'] = $this->section(
      ['authentication'],
      $this->pluginOptions($this->authentications),
      $authentication,
      $this->authentications,
      $form,
      $form_state,
      $this->t('Authentication'),
      FALSE,
    );

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * Tells whether a connection with this ID exists.
   */
  public function exists(string $id): bool {
    return $this->entityTypes->getStorage('import_connection')->load($id) !== NULL;
  }

  /**
   * Builds a section for a plugin and its settings.
   *
   * @param list<string> $parents
   *   Where the section is in the form.
   * @param array<string, string> $options
   *   The plugins to choose from, by label.
   * @param array{plugin: string, configuration: array<string, mixed>} $current
   *   The saved plugin and configuration.
   * @param \Drupal\Core\Plugin\DefaultPluginManager $manager
   *   The manager of the plugins.
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param mixed $title
   *   The title.
   * @param bool $connection_only
   *   Show only the settings that belong to a connection.
   *
   * @return array<string, mixed>
   *   The section.
   */
  private function section(array $parents, array $options, array $current, DefaultPluginManager $manager, array &$form, FormStateInterface $form_state, mixed $title, bool $connection_only): array {
    $wrapper = 'connection-' . implode('-', $parents);
    $chosen = [...$parents, 'plugin'];
    $selected = (string) ($form_state->getValue($chosen)
      ?? NestedArray::getValue($form_state->getUserInput(), $chosen)
      ?? $current['plugin']);
    if (!isset($options[$selected])) {
      $selected = (string) array_key_first($options);
    }
    $section = [
      '#type' => 'fieldset',
      '#title' => $title,
      '#prefix' => '<div id="' . $wrapper . '">',
      '#suffix' => '</div>',
      'plugin' => [
        '#type' => 'select',
        '#title' => $this->t('Type'),
        '#options' => $options,
        '#default_value' => $selected,
        '#ajax' => ['callback' => '::ajaxSection', 'wrapper' => $wrapper],
      ],
    ];
    if ($selected === '') {
      return $section;
    }

    $saved = $current['plugin'] === $selected;
    $plugin = $manager->createInstance($selected, $saved ? $current['configuration'] : []);
    $section['settings'] = ['#parents' => [...$parents, 'settings'], '#array_parents' => [...$parents, 'settings']];
    if ($plugin instanceof PluginFormInterface) {
      $subform_state = SubformState::createForSubform($section['settings'], $form, $form_state);
      $section['settings'] = $plugin->buildConfigurationForm($section['settings'], $subform_state);
    }
    if ($connection_only) {
      $keys = $this->resolver->connectionKeys($selected);
      foreach (array_keys($section['settings']) as $key) {
        if (!str_starts_with((string) $key, '#') && !in_array($key, $keys, TRUE)) {
          unset($section['settings'][$key]);
        }
      }
    }
    return $section;
  }

  /**
   * Returns the source plugins that can be used for a connection.
   *
   * @return array<string, string>
   *   The labels by plugin ID.
   */
  private function sourceOptions(): array {
    $options = [];
    foreach ($this->sources->getDefinitions() as $id => $definition) {
      if (!empty($definition['connection_keys'])) {
        $options[(string) $id] = (string) $definition['label'];
      }
    }
    asort($options);
    return $options;
  }

  /**
   * Returns the plugins of a manager.
   *
   * @return array<string, string>
   *   The labels by plugin ID.
   */
  private function pluginOptions(DefaultPluginManager $manager): array {
    $options = [];
    foreach ($manager->getDefinitions() as $id => $definition) {
      $options[(string) $id] = (string) ($definition['label'] ?? $id);
    }
    asort($options);
    return $options;
  }

  /**
   * Returns the imports that use the connection.
   *
   * @return list<string>
   *   Their IDs.
   */
  private function usedBy(): array {
    $storage = $this->entityTypes->getStorage('import_connection');
    if ($this->connection === NULL || !$storage instanceof ImportConnectionStorage) {
      return [];
    }
    return $storage->usedBy((string) $this->connection->id());
  }

  /**
   * Returns the part of the form an AJAX call rebuilds.
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<string, mixed>
   *   The section of the element that was changed.
   */
  public function ajaxSection(array &$form, FormStateInterface $form_state): array {
    $trigger = $form_state->getTriggeringElement();
    $part = NestedArray::getValue($form, array_slice((array) ($trigger['#array_parents'] ?? []), 0, -1));
    return is_array($part) ? $part : [];
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
    $managers = ['source' => $this->sources, 'authentication' => $this->authentications];
    foreach ($managers as $name => $manager) {
      $id = (string) $form_state->getValue([$name, 'plugin']);
      if ($id === '' || !isset($form[$name]['settings'])) {
        continue;
      }
      $plugin = $manager->createInstance($id, []);
      if ($plugin instanceof PluginFormInterface) {
        $settings = $form[$name]['settings'];
        $plugin->validateConfigurationForm($settings, SubformState::createForSubform($settings, $form, $form_state));
      }
    }
    if ($form_state->getErrors() !== []) {
      return;
    }

    $values = $this->values($form, $form_state);
    $used = $this->usedBy();
    if ($this->connection !== NULL && $used !== [] && $this->connection->getSource()['plugin'] !== $values['source']['plugin']) {
      $form_state->setErrorByName('source][plugin', $this->t('The imports @imports read from this connection, so it cannot become another kind of source.', ['@imports' => implode(', ', $used)]));
      return;
    }

    // The same rules as for saved configuration.
    $entity = $this->entityTypes->getStorage('import_connection')->create($values);
    foreach ($entity->getTypedData()->validate() as $violation) {
      $path = explode('.', $violation->getPropertyPath());
      $name = implode('][', $path);
      $form_state->setErrorByName(isset($form_state->getValues()[$path[0]]) ? $name : 'label', (string) $violation->getMessage());
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
    $values = $this->values($form, $form_state);
    $storage = $this->entityTypes->getStorage('import_connection');
    if (!$storage instanceof ConfigEntityStorageInterface) {
      return;
    }
    $before = $this->connection?->getSource()['configuration']['url'] ?? NULL;
    $used = $this->usedBy();
    if ($this->connection === NULL) {
      $storage->create($values)->save();
    }
    else {
      foreach (['label', 'description', 'source', 'authentication'] as $key) {
        $this->connection->set($key, $values[$key]);
      }
      $this->connection->save();
    }
    $this->messenger()->addStatus($this->t('The connection @label is saved.', ['@label' => $values['label']]));
    if ($used !== [] && $before !== NULL && $before !== ($values['source']['configuration']['url'] ?? NULL)) {
      $this->messenger()->addWarning($this->t('The address changed. If the new address has other data, run @imports with "Read every page again", or the items read before are taken for unchanged.', ['@imports' => implode(', ', $used)]));
    }
    $form_state->setRedirect('import_engine_ui.connections');
  }

  /**
   * Turns the submitted values into the values of a connection.
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<string, mixed>
   *   The values of the connection.
   */
  private function values(array $form, FormStateInterface $form_state): array {
    $source = $this->collect('source', $this->sources, $form, $form_state);
    $source['configuration'] = array_intersect_key($source['configuration'], array_flip($this->resolver->connectionKeys($source['plugin'])));
    return [
      'id' => $this->connection?->id() ?? (string) $form_state->getValue('id'),
      'label' => trim((string) $form_state->getValue('label')),
      'description' => trim((string) $form_state->getValue('description')),
      'source' => $source,
      'authentication' => $this->collect('authentication', $this->authentications, $form, $form_state),
    ];
  }

  /**
   * Reads a plugin and its configuration from the submitted values.
   *
   * @param string $name
   *   The section.
   * @param \Drupal\Core\Plugin\DefaultPluginManager $manager
   *   The manager of the plugins.
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array{plugin: string, configuration: array<string, mixed>}
   *   The plugin and its configuration.
   */
  private function collect(string $name, DefaultPluginManager $manager, array $form, FormStateInterface $form_state): array {
    $id = (string) $form_state->getValue([$name, 'plugin']);
    $plugin = $id === '' ? NULL : $manager->createInstance($id, []);
    if (!$plugin instanceof PluginFormInterface || !$plugin instanceof ConfigurableInterface) {
      return ['plugin' => $id, 'configuration' => []];
    }
    $settings = $form[$name]['settings'] ?? [];
    $values = (array) $form_state->getValue([$name, 'settings'], []);
    $plugin->submitConfigurationForm($settings, (new FormState())->setValues($values));
    return ['plugin' => $id, 'configuration' => (array) $plugin->getConfiguration()];
  }

}
