<?php

declare(strict_types=1);

namespace Drupal\import_engine_ui\Form;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\import_engine\Storage\ItemState;
use Drupal\import_engine\Storage\ItemStorage;
use Drupal\import_engine_ui\DeadLetter\DeadLetterOperations;
use Drupal\import_engine_ui\Help\Chrome;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Edits the payload of an item that is waiting or dead.
 *
 * The payload is the source item as read; fixing it here is for data that is
 * wrong at the source and cannot be fixed there right now. The next run reads
 * the source again, so an edit never outlives the run.
 */
final class DeadLetterEditForm extends FormBase {

  use AutowireTrait;

  /**
   * Constructs the form.
   */
  public function __construct(
    #[Autowire(service: 'import_engine.item_storage')]
    protected ItemStorage $items,
    #[Autowire(service: 'import_engine_ui.dead_letter')]
    protected DeadLetterOperations $operations,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'import_engine_ui_dead_letter_edit';
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param int|string $item_id
   *   The item, from the route.
   *
   * @return array<string, mixed>
   *   The form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, int|string $item_id = 0): array {
    $item = $this->items->find((int) $item_id);
    // Only an item that is dead or waiting for a retry is edited: a pending
    // one may be in the hands of a worker already.
    if ($item === NULL || $item->payload === NULL || !in_array($item->state, [ItemState::Dead, ItemState::Retrying], TRUE)) {
      throw new NotFoundHttpException();
    }
    $form_state->set('item_id', $item->id);
    $form['#attributes']['class'][] = 'import-engine-form';
    $form['#attached']['library'][] = 'import_engine_ui/wizard';
    $dead = $item->state === ItemState::Dead;
    $form['item_header'] = Chrome::header(
      $item->key,
      [$dead
          ? ['failed', (string) $this->t('Dead')]
          : ['template', (string) $this->t('Waiting for a retry')],
      ],
      (string) $this->t('Run @run · @attempts attempts', [
        '@run' => (string) $item->runId,
        '@attempts' => (string) $item->attempts,
      ]),
    );
    $form['summary_plain'] = Chrome::panel(
      'dead-item-summary-plain',
      (string) $this->t('In plain words'),
      (string) $this->t('What is wrong with this item, and what saving does.'),
      [
        (string) $this->t('This item could not be saved. Last error: @error', ['@error' => $item->error ?? '-']),
        (string) $this->t('Change the data on the left so that it fits, then save. It must stay a JSON object.'),
        (string) $this->t('With "Retry the item after saving" on, a worker handles it again straight away.'),
      ],
    );
    $form['payload'] = [
      '#attributes' => ['class' => ['import-payload']],
      '#type' => 'textarea',
      '#title' => $this->t('Payload (JSON)'),
      '#rows' => 20,
      '#default_value' => json_encode($item->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
      '#required' => TRUE,
    ];
    $form['retry'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Retry the item after saving'),
      '#default_value' => TRUE,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Save'), '#button_type' => 'primary'];
    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    try {
      $decoded = json_decode((string) $form_state->getValue('payload'), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $exception) {
      $form_state->setErrorByName('payload', $this->t('The payload is not valid JSON: @message', ['@message' => $exception->getMessage()]));
      return;
    }
    if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
      $form_state->setErrorByName('payload', $this->t('The payload must be a JSON object.'));
      return;
    }
    $form_state->set('decoded', $decoded);
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
    $id = (int) $form_state->get('item_id');
    /** @var array<string, mixed> $payload */
    $payload = $form_state->get('decoded');
    if ($this->items->updatePayload($id, $payload)) {
      $this->messenger()->addStatus($this->t('The payload is saved.'));
      if ($form_state->getValue('retry')) {
        $this->operations->retry([$id]);
      }
    }
    else {
      $this->messenger()->addError($this->t('The item can no longer be edited.'));
    }
    $form_state->setRedirectUrl(Url::fromRoute('import_engine_ui.dead_letter'));
  }

}
