<?php

declare(strict_types=1);

namespace Drupal\import_engine_ui\Form;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\import_engine\ImportRunSetInterface;

/**
 * Runs the imports of a set in order, with a progress bar.
 */
final class RunSetRunForm extends ConfirmFormBase {

  /**
   * The set to run.
   */
  protected ?ImportRunSetInterface $set = NULL;

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'import_engine_ui_run_set_run';
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
    $form = parent::buildForm($form, $form_state);
    $form['full'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Read every page again'),
      '#description' => $this->t('For every import of the set: handle every item, also those of pages that did not change.'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Run the set @label?', ['@label' => (string) $this->set?->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return $this->t('The imports run one after the other: @imports. The set stops at the first one that goes wrong. It goes on while this page is open; if you close it, an import that is not over can be continued by running the set again.', [
      '@imports' => implode(', ', $this->set?->getImports() ?? []),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): TranslatableMarkup {
    return $this->t('Run');
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
    $form_state->setRedirect('import_engine_ui.runs');
    if ($this->set === NULL) {
      return;
    }
    $batch = (new BatchBuilder())
      ->setTitle($this->t('Running the set @label', ['@label' => (string) $this->set->label()]))
      ->setInitMessage($this->t('Starting the set.'))
      ->setFinishCallback('import_engine_ui.run_set_batch:finished')
      ->addOperation('import_engine_ui.run_set_batch:drive', [
        (string) $this->set->id(),
        (bool) $form_state->getValue('full'),
      ]);
    batch_set($batch->toArray());
  }

}
