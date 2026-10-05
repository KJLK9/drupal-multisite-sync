<?php

declare(strict_types=1);

namespace Drupal\import_engine_ui\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\import_engine\ImportRunSetInterface;

/**
 * Asks for confirmation to delete a run set.
 */
final class RunSetDeleteForm extends ConfirmFormBase {

  /**
   * The set to delete.
   */
  protected ?ImportRunSetInterface $set = NULL;

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'import_engine_ui_run_set_delete';
  }

  /**
   * {@inheritdoc}
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\import_engine\ImportRunSetInterface|null $import_run_set
   *   The set, from the route.
   *
   * @return array<string, mixed>
   *   The form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?ImportRunSetInterface $import_run_set = NULL): array {
    $this->set = $import_run_set;
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Delete the run set @label?', ['@label' => (string) $this->set?->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return $this->t('Only the set is deleted: its imports and their runs stay as they are.');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('import_engine_ui.run_sets');
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
    $form_state->setRedirectUrl($this->getCancelUrl());
    if ($this->set === NULL) {
      return;
    }
    $label = (string) $this->set->label();
    $this->set->delete();
    $this->messenger()->addStatus($this->t('The run set @label is deleted.', ['@label' => $label]));
  }

}
