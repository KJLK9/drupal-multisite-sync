<?php

declare(strict_types=1);

namespace Drupal\import_engine_ui\Form;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\import_engine\ImportDefinitionInterface;
use Drupal\import_engine\Run\RunAlreadyActiveException;
use Drupal\import_engine\Run\RunStarter;
use Drupal\import_engine\Run\Trigger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Starts a run of an import, or continues the one that is not over.
 *
 * The progress is shown while the run goes on.
 *
 * An import has at most one run that is not over. When there is one, the form
 * continues it, so a run that was stopped (a closed browser, an outage at the
 * source) is picked up again here.
 */
final class RunStartForm extends ConfirmFormBase {

  use AutowireTrait;

  /**
   * The import.
   */
  protected ?ImportDefinitionInterface $definition = NULL;

  /**
   * The ID of the run that is not over, if there is one.
   */
  protected ?int $activeRun = NULL;

  /**
   * Constructs the form.
   */
  public function __construct(
    #[Autowire(service: 'import_engine.run_starter')]
    protected RunStarter $starter,
    #[Autowire(service: 'current_user')]
    protected AccountInterface $account,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'import_engine_ui_run_start';
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
    $this->activeRun = $import_definition === NULL ? NULL : $this->starter->activeRunId((string) $import_definition->id());
    $form = parent::buildForm($form, $form_state);

    if ($import_definition !== NULL && !$import_definition->status()) {
      $this->messenger()->addError($this->t('The import @label is disabled. Enable it in its settings to run it.', ['@label' => (string) $import_definition->label()]));
      $form['actions']['submit']['#access'] = FALSE;
      return $form;
    }
    if ($this->activeRun === NULL) {
      $form['full'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Read every page again'),
        '#description' => $this->t('Pages that did not change since the last run are skipped. Switch this on to handle every item anyway, for example after a change in the source that the engine cannot see.'),
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    $label = (string) $this->definition?->label();
    return $this->activeRun === NULL
      ? $this->t('Run the import @label?', ['@label' => $label])
      : $this->t('Continue run @id of the import @label?', ['@id' => (string) $this->activeRun, '@label' => $label]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return $this->t('The run goes on while this page is open, with a progress bar. If you close it, the run stops where it is and can be continued here, or by a worker.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): TranslatableMarkup {
    return $this->activeRun === NULL ? $this->t('Run') : $this->t('Continue');
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
    $form_state->setRedirectUrl($this->getCancelUrl());
    $run_id = $this->activeRun;
    if ($run_id === NULL) {
      try {
        $run_id = (int) $this->starter->start($this->definition, Trigger::Ui, (int) $this->account->id(), (bool) $form_state->getValue('full'))->id();
      }
      catch (RunAlreadyActiveException $exception) {
        // Someone started a run between this form and the click.
        $run_id = $exception->runId;
      }
      catch (\RuntimeException $exception) {
        $this->messenger()->addError($exception->getMessage());
        return;
      }
    }

    $batch = (new BatchBuilder())
      ->setTitle($this->t('Running the import @label', ['@label' => (string) $this->definition->label()]))
      ->setInitMessage($this->t('Starting run @id.', ['@id' => (string) $run_id]))
      ->setFinishCallback('import_engine_ui.run_batch:finished')
      ->addOperation('import_engine_ui.run_batch:drive', [$run_id]);
    batch_set($batch->toArray());
    $form_state->setRedirectUrl(Url::fromRoute('import_engine_ui.run', ['import_run' => $run_id]));
  }

}
