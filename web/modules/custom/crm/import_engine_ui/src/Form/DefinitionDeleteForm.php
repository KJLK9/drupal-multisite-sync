<?php

declare(strict_types=1);

namespace Drupal\import_engine_ui\Form;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\import_engine\ImportDefinitionInterface;
use Drupal\import_engine\Run\RunStarter;
use Drupal\import_engine\Storage\ImportDefinitionStorage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Asks for confirmation to delete an import definition.
 *
 * An import with a run that is not over cannot be deleted: its items would
 * lose the definition they are processed with.
 */
final class DefinitionDeleteForm extends ConfirmFormBase {

  use AutowireTrait;

  /**
   * The definition to delete.
   */
  protected ?ImportDefinitionInterface $definition = NULL;

  /**
   * Constructs the form.
   */
  public function __construct(
    #[Autowire(service: 'import_engine.run_starter')]
    protected RunStarter $starter,
    #[Autowire(service: 'entity_type.manager')]
    protected EntityTypeManagerInterface $entityTypes,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'import_engine_ui_definition_delete';
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\import_engine\ImportDefinitionInterface|null $import_definition
   *   The definition, from the route.
   *
   * @return array<string, mixed>
   *   The form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?ImportDefinitionInterface $import_definition = NULL): array {
    $this->definition = $import_definition;
    $form = parent::buildForm($form, $form_state);
    $sets = $this->setsOf();
    if ($sets !== []) {
      // Nothing to confirm: say why it cannot be done.
      $form['blocked'] = [
        '#markup' => '<p>' . $this->t('The run sets @sets list this import. Take it out of them first.', ['@sets' => implode(', ', $sets)]) . '</p>',
        '#weight' => -5,
      ];
      unset($form['actions']['submit']);
    }
    return $form;
  }

  /**
   * Returns the run sets that list the import.
   *
   * @return list<string>
   *   Their IDs.
   */
  private function setsOf(): array {
    $storage = $this->entityTypes->getStorage('import_definition');
    if ($this->definition === NULL || !$storage instanceof ImportDefinitionStorage) {
      return [];
    }
    return $storage->setsOf((string) $this->definition->id());
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Delete the import @label?', ['@label' => (string) $this->definition?->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return $this->t('The definition is deleted. Its runs and the items it wrote stay as they are.');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('import_engine_ui.definitions');
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $form_state->setRedirectUrl($this->getCancelUrl());
    if ($this->definition === NULL || $this->setsOf() !== []) {
      return;
    }
    $active = $this->starter->activeRunId((string) $this->definition->id());
    if ($active !== NULL) {
      $this->messenger()->addError($this->t('Run @run of this import is not over. Cancel it first.', ['@run' => (string) $active]));
      return;
    }
    $label = (string) $this->definition->label();
    $this->definition->delete();
    $this->messenger()->addStatus($this->t('The import @label is deleted.', ['@label' => $label]));
  }

}
