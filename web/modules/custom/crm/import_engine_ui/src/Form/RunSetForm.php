<?php

declare(strict_types=1);

namespace Drupal\import_engine_ui\Form;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\import_engine\ImportRunSetInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Adds and changes a run set.
 *
 * The imports are rows that can be dragged into the order they run in. A row
 * without an import is left out; another row is added with a button.
 */
final class RunSetForm extends FormBase {

  use AutowireTrait;

  /**
   * The set that is changed; none when one is added.
   */
  protected ?ImportRunSetInterface $set = NULL;

  /**
   * Constructs the form.
   */
  public function __construct(
    #[Autowire(service: 'entity_type.manager')]
    protected EntityTypeManagerInterface $entityTypes,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'import_engine_ui_run_set';
  }

  /**
   * {@inheritdoc}
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\import_engine\ImportRunSetInterface|null $import_run_set
   *   The set to change, from the route; none to add one.
   *
   * @return array<string, mixed>
   *   The form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?ImportRunSetInterface $import_run_set = NULL): array {
    $this->set = $import_run_set;
    $saved = $import_run_set?->getImports() ?? [];
    // Rows: those of the set, and one to add to.
    $rows = (int) ($form_state->get('rows') ?? count($saved) + 1);
    $form_state->set('rows', $rows);

    $form['#tree'] = TRUE;
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#default_value' => $import_run_set?->label(),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];
    $form['id'] = [
      '#type' => 'machine_name',
      '#title' => $this->t('ID'),
      '#default_value' => $import_run_set?->id(),
      '#machine_name' => ['exists' => [$this, 'exists'], 'source' => ['label']],
      '#disabled' => $import_run_set !== NULL,
      '#maxlength' => 64,
    ];
    $form['description'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Description'),
      '#default_value' => $import_run_set?->getDescription(),
    ];

    $options = [];
    foreach ($this->entityTypes->getStorage('import_definition')->loadMultiple() as $definition) {
      $options[(string) $definition->id()] = (string) $definition->label();
    }
    asort($options);
    $form['imports'] = [
      '#type' => 'table',
      '#header' => [$this->t('Import'), $this->t('Order')],
      '#tabledrag' => [['action' => 'order', 'relationship' => 'sibling', 'group' => 'import-set-weight']],
      '#prefix' => '<div id="import-set-imports">',
      '#suffix' => '</div>',
      '#caption' => $this->t('The imports run in this order, from the top. Drag a row to move it; a row without an import is left out.'),
    ];
    for ($i = 0; $i < $rows; $i++) {
      $form['imports'][$i] = [
        '#attributes' => ['class' => ['draggable']],
        '#weight' => $i,
        'import' => [
          '#type' => 'select',
          '#title' => $this->t('Import'),
          '#title_display' => 'invisible',
          '#options' => $options,
          '#empty_option' => $this->t('- None -'),
          '#default_value' => $saved[$i] ?? '',
        ],
        'weight' => [
          '#type' => 'weight',
          '#title' => $this->t('Order'),
          '#title_display' => 'invisible',
          '#default_value' => $i,
          '#delta' => 50,
          '#attributes' => ['class' => ['import-set-weight']],
        ],
      ];
    }
    $form['add'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add another import'),
      '#name' => 'add_import',
      '#limit_validation_errors' => [],
      '#submit' => ['::addRow'],
      '#ajax' => ['callback' => '::ajaxRows', 'wrapper' => 'import-set-imports'],
    ];
    $form['stop_on_errors'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Also stop when items of an import went wrong'),
      '#description' => $this->t('A set always stops when an import fails, is cancelled or cannot go on. With this on it also stops when an import completed with errors (items in the dead letter queue), for imports whose next one needs all of its items.'),
      '#default_value' => $import_run_set?->stopsOnErrors() ?? FALSE,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * Tells whether a set with this ID exists.
   */
  public function exists(string $id): bool {
    return $this->entityTypes->getStorage('import_run_set')->load($id) !== NULL;
  }

  /**
   * Adds a row.
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function addRow(array &$form, FormStateInterface $form_state): void {
    $form_state->set('rows', (int) $form_state->get('rows') + 1);
    $form_state->setRebuild();
  }

  /**
   * Returns the table of imports, after a row was added.
   *
   * @param array<mixed> $form
   *   The form.
   *
   * @return array<mixed>
   *   The table.
   */
  public function ajaxRows(array &$form): array {
    return $form['imports'];
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
    $entity = $this->entityTypes->getStorage('import_run_set')->create($this->values($form_state));
    foreach ($entity->getTypedData()->validate() as $violation) {
      $first = explode('.', $violation->getPropertyPath())[0];
      $form_state->setErrorByName(in_array($first, ['label', 'id', 'description', 'imports'], TRUE) ? $first : 'label', (string) $violation->getMessage());
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
    $values = $this->values($form_state);
    if ($this->set === NULL) {
      $this->entityTypes->getStorage('import_run_set')->create($values)->save();
    }
    else {
      foreach (['label', 'description', 'imports', 'stop_on_errors'] as $key) {
        $this->set->set($key, $values[$key]);
      }
      $this->set->save();
    }
    $this->messenger()->addStatus($this->t('The run set @label is saved.', ['@label' => $values['label']]));
    $form_state->setRedirect('import_engine_ui.run_sets');
  }

  /**
   * Turns the submitted values into the values of a set.
   *
   * @return array<string, mixed>
   *   The values.
   */
  private function values(FormStateInterface $form_state): array {
    $rows = [];
    foreach ((array) $form_state->getValue('imports') as $delta => $row) {
      if (is_array($row) && ($row['import'] ?? '') !== '') {
        $rows[] = [
          'import' => (string) $row['import'],
          'weight' => (int) ($row['weight'] ?? 0),
          'delta' => (int) $delta,
        ];
      }
    }
    // By weight; rows with the same weight stay as they were.
    usort($rows, static fn (array $a, array $b): int => [$a['weight'], $a['delta']] <=> [$b['weight'], $b['delta']]);
    return [
      'id' => $this->set?->id() ?? (string) $form_state->getValue('id'),
      'label' => trim((string) $form_state->getValue('label')),
      'description' => trim((string) $form_state->getValue('description')),
      'imports' => array_column($rows, 'import'),
      'stop_on_errors' => (bool) $form_state->getValue('stop_on_errors'),
    ];
  }

}
