<?php

declare(strict_types=1);

namespace Drupal\import_engine_ui\Form;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\import_engine\Run\ImportRunInterface;
use Drupal\import_engine\Run\RunBusyException;
use Drupal\import_engine\Run\RunManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Asks for confirmation to cancel a run, and cancels it.
 */
final class RunCancelForm extends ConfirmFormBase {

  use AutowireTrait;

  /**
   * The run to cancel.
   */
  protected ?ImportRunInterface $run = NULL;

  /**
   * Constructs the form.
   */
  public function __construct(
    #[Autowire(service: 'import_engine.run_manager')]
    protected RunManager $manager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'import_engine_ui_run_cancel';
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\import_engine\Run\ImportRunInterface|null $import_run
   *   The run, from the route.
   *
   * @return array<string, mixed>
   *   The form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?ImportRunInterface $import_run = NULL): array {
    $this->run = $import_run;
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Cancel run @id?', ['@id' => (string) $this->run?->id()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return $this->t('Items that wait are skipped. Items that are being handled right now are finished. A cancelled run is not swept and not reported.');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return $this->run === NULL ? Url::fromRoute('import_engine_ui.runs') : Url::fromRoute('import_engine_ui.run', ['import_run' => $this->run->id()]);
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
    if ($this->run === NULL) {
      return;
    }
    try {
      $skipped = $this->manager->cancel($this->run);
      $this->messenger()->addStatus($this->t('Run @id is cancelled; @count waiting items were skipped.', [
        '@id' => (string) $this->run->id(),
        '@count' => (string) $skipped,
      ]));
    }
    catch (RunBusyException $exception) {
      $this->messenger()->addError($exception->getMessage());
    }
    catch (\LogicException) {
      $this->messenger()->addError($this->t('Run @id is already over.', ['@id' => (string) $this->run->id()]));
    }
  }

}
