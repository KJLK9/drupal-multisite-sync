<?php

declare(strict_types=1);

namespace Drupal\import_engine_ui\Form;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\import_engine\ImportDefinitionInterface;
use Drupal\import_engine_ui\Lock\WizardLock;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Asks whether to take over the editing of an import from someone else.
 */
final class DefinitionUnlockForm extends ConfirmFormBase {

  use AutowireTrait;

  /**
   * The import.
   */
  protected ?ImportDefinitionInterface $definition = NULL;

  /**
   * Constructs the form.
   */
  public function __construct(
    #[Autowire(service: 'import_engine_ui.wizard_lock')]
    protected WizardLock $lock,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'import_engine_ui_definition_unlock';
  }

  /**
   * {@inheritdoc}
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\import_engine\ImportDefinitionInterface|null $import_definition
   *   The import, from the route.
   *
   * @return array<string, mixed>
   *   The form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?ImportDefinitionInterface $import_definition = NULL): array {
    $this->definition = $import_definition;
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Take over editing the import @label?', ['@label' => (string) $this->definition?->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    $holder = $this->definition === NULL ? NULL : $this->lock->holder((string) $this->definition->id());
    return $holder === NULL
      ? $this->t('Nobody is editing it any more.')
      : $this->t('@name is editing it. What @name has not saved is lost: the next step they take tells them that you took over, and nothing they have is saved over your work.', ['@name' => $holder['name']]);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): TranslatableMarkup {
    return $this->t('Take over');
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
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if ($this->definition === NULL) {
      return;
    }
    $this->lock->takeOver((string) $this->definition->id(), $this->currentUser());
    $form_state->setRedirect('import_engine_ui.definition_edit', ['import_definition' => $this->definition->id()]);
  }

}
