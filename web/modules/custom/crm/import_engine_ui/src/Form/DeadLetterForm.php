<?php

declare(strict_types=1);

namespace Drupal\import_engine_ui\Form;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Url;
use Drupal\import_engine\Storage\ItemState;
use Drupal\import_engine\Storage\ItemStorage;
use Drupal\import_engine_ui\DeadLetter\DeadLetterOperations;
use Drupal\import_engine_ui\Help\Chrome;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The dead letter queue: items that ran out of attempts, with their errors.
 *
 * Select items to retry (a worker handles them), to handle right now with a
 * progress bar, or to discard. An item can be opened to edit its payload.
 */
final class DeadLetterForm extends FormBase {

  use AutowireTrait;

  /**
   * The items shown per page.
   */
  private const PER_PAGE = 50;

  /**
   * Constructs the form.
   */
  public function __construct(
    #[Autowire(service: 'import_engine.item_storage')]
    protected ItemStorage $items,
    #[Autowire(service: 'import_engine_ui.dead_letter')]
    protected DeadLetterOperations $operations,
    #[Autowire(service: 'pager.manager')]
    protected PagerManagerInterface $pagerManager,
    #[Autowire(service: 'entity_type.manager')]
    protected EntityTypeManagerInterface $entityTypes,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'import_engine_ui_dead_letter';
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<string, mixed>
   *   The form.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $discard = $form_state->get('confirm_discard');
    if (is_array($discard)) {
      return $this->buildConfirm($form, $discard);
    }

    $form['#attributes']['class'][] = 'import-engine-form';
    $form['#attached']['library'][] = 'import_engine_ui/runs';
    $import = $this->selectedImport();
    $run_ids = $this->runIds($import);
    $total = $this->items->countItems($run_ids, ItemState::Dead);
    $form['queue_header'] = $this->queueHeader($import, $total);
    $form['filter'] = $this->filterPills($import);

    $pager = $this->pagerManager->createPager($total, self::PER_PAGE);
    $options = [];
    foreach ($this->items->listItems($run_ids, ItemState::Dead, self::PER_PAGE, $pager->getCurrentPage() * self::PER_PAGE) as $item) {
      $options[$item->id] = [
        'run' => $item->runId,
        'key' => ['data' => $item->key, 'class' => ['import-cell--key']],
        'attempts' => $item->attempts,
        'error' => ['data' => $item->error ?? '', 'class' => ['import-cell--error']],
        'operations' => ['data' => $this->editLink($item->id)],
      ];
    }
    $form['items'] = [
      '#type' => 'tableselect',
      '#header' => [
        'run' => $this->t('Run'),
        'key' => $this->t('Key'),
        'attempts' => $this->t('Attempts'),
        'error' => $this->t('Error'),
        'operations' => $this->t('Operations'),
      ],
      '#options' => $options,
      '#empty' => $this->t('The dead letter queue is empty.'),
    ];
    $form['summary_plain'] = Chrome::panel(
      'dead-letter-summary-plain',
      (string) $this->t('In plain words'),
      (string) $this->t('What is waiting here, and what you can do with it.'),
      $this->queueSentences($run_ids, $total),
    );

    $allowed = $this->currentUser()->hasPermission('administer import runs');
    $form['actions'] = ['#type' => 'actions', '#access' => $allowed && $options !== []];
    $form['actions']['retry'] = [
      '#type' => 'submit',
      '#value' => $this->t('Retry selected'),
      '#button_type' => 'primary',
      '#submit' => ['::submitRetry'],
    ];
    $form['actions']['process'] = [
      '#type' => 'submit',
      '#value' => $this->t('Handle selected now'),
      '#submit' => ['::submitProcess'],
    ];
    $form['actions']['discard'] = [
      '#type' => 'submit',
      '#value' => $this->t('Discard selected'),
      '#button_type' => 'danger',
      '#submit' => ['::submitDiscard'],
    ];
    $form['pager'] = ['#type' => 'pager'];
    $form['#cache'] = ['max-age' => 0];
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
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->assertMayAdminister();
    // The buttons have their own handlers; this is the confirmation step.
    $ids = $form_state->get('confirm_discard');
    if (is_array($ids) && ($form_state->getTriggeringElement()['#name'] ?? '') === 'confirm') {
      $count = $this->operations->discard($this->ids($ids));
      $this->messenger()->addStatus($this->t('@count items were discarded.', ['@count' => (string) $count]));
    }
    $form_state->set('confirm_discard', NULL);
    $form_state->setRebuild();
  }

  /**
   * Retries the selected items.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submitRetry(array &$form, FormStateInterface $form_state): void {
    $this->assertMayAdminister();
    $ids = $this->selected($form_state);
    if ($ids === []) {
      return;
    }
    $count = $this->operations->retry($ids);
    $this->messenger()->addStatus($this->t('@count items are pending again; a worker handles them.', ['@count' => (string) $count]));
  }

  /**
   * Handles the selected items right now, in a batch.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submitProcess(array &$form, FormStateInterface $form_state): void {
    $this->assertMayAdminister();
    $ids = $this->selected($form_state);
    if ($ids === []) {
      return;
    }
    $batch = (new BatchBuilder())
      ->setTitle($this->t('Handling items'))
      ->setFinishCallback('import_engine_ui.dead_letter:finished');
    foreach (array_chunk($ids, DeadLetterOperations::CHUNK) as $chunk) {
      $batch->addOperation('import_engine_ui.dead_letter:processNow', [$chunk]);
    }
    batch_set($batch->toArray());
  }

  /**
   * Asks to confirm that the selected items are discarded.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submitDiscard(array &$form, FormStateInterface $form_state): void {
    $this->assertMayAdminister();
    $ids = $this->selected($form_state);
    if ($ids === []) {
      return;
    }
    $form_state->set('confirm_discard', $ids)->setRebuild();
  }

  /**
   * Builds the question before items are thrown away.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param array<mixed> $ids
   *   The item IDs.
   *
   * @return array<string, mixed>
   *   The form.
   */
  private function buildConfirm(array $form, array $ids): array {
    $form['question'] = [
      '#markup' => '<p>' . $this->t('Discard @count items? Their payload is deleted and they cannot be retried. The pages they were on are read again by the next run.', ['@count' => (string) count($ids)]) . '</p>',
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['confirm'] = [
      '#type' => 'submit',
      '#name' => 'confirm',
      '#value' => $this->t('Discard'),
      '#submit' => ['::submitForm'],
    ];
    $form['actions']['cancel'] = [
      '#type' => 'submit',
      '#name' => 'cancel',
      '#value' => $this->t('Cancel'),
      '#submit' => ['::submitForm'],
    ];
    return $form;
  }

  /**
   * Returns the IDs of the items that were checked.
   *
   * @return list<int>
   *   The item IDs.
   */
  private function selected(FormStateInterface $form_state): array {
    $ids = $this->ids(array_filter((array) $form_state->getValue('items')));
    if ($ids === []) {
      $this->messenger()->addWarning($this->t('Select at least one item.'));
    }
    return $ids;
  }

  /**
   * Turns keys or values of a selection into item IDs.
   *
   * @param array<mixed> $values
   *   The selection.
   *
   * @return list<int>
   *   The IDs.
   */
  private function ids(array $values): array {
    return array_values(array_map(intval(...), array_values($values)));
  }

  /**
   * Returns the import to show, from the request.
   */
  private function selectedImport(): ?string {
    $import = $this->getRequest()->query->get('import');
    return is_string($import) && $import !== '' ? $import : NULL;
  }

  /**
   * Returns the runs of an import; NULL for all runs.
   *
   * @return list<int>|null
   *   The run IDs.
   */
  private function runIds(?string $import): ?array {
    if ($import === NULL) {
      return NULL;
    }
    $ids = $this->entityTypes->getStorage('import_run')->getQuery()
      ->accessCheck(FALSE)
      ->condition('definition_id', $import)
      ->execute();
    return array_values(array_map(intval(...), $ids));
  }

  /**
   * Builds the line above the queue: what it is and how many items wait.
   *
   * @param string|null $import
   *   The import that is filtered on, or NULL for all.
   * @param int $total
   *   How many items wait.
   *
   * @return array<string, mixed>
   *   The element.
   */
  private function queueHeader(?string $import, int $total): array {
    $pill = $total === 0
      ? ['on', (string) $this->t('Empty')]
      : ['template', (string) $this->formatPlural($total, '1 item waits', '@count items wait')];
    $definition = $import === NULL ? NULL : $this->entityTypes->getStorage('import_definition')->load($import);
    $meta = $definition === NULL ? (string) $this->t('All imports') : (string) $definition->label();
    return Chrome::header((string) $this->t('Waiting for a person'), [$pill], $meta);
  }

  /**
   * Builds the buttons that filter on an import, each with its count.
   *
   * @param string|null $selected
   *   The import that is filtered on, or NULL for all.
   *
   * @return array<int|string, mixed>
   *   The element.
   */
  private function filterPills(?string $selected): array {
    $filter = ['#type' => 'container', '#attributes' => ['class' => ['import-run-filter']]];
    $pills = [[NULL, (string) $this->t('All imports'), $this->items->countItems(NULL, ItemState::Dead)]];
    foreach ($this->entityTypes->getStorage('import_definition')->loadMultiple() as $id => $definition) {
      $count = $this->items->countItems($this->runIds((string) $id), ItemState::Dead);
      $pills[] = [(string) $id, (string) $definition->label(), $count];
    }
    foreach ($pills as $delta => [$id, $label, $count]) {
      $filter[$delta] = [
        '#type' => 'link',
        '#title' => $this->t('@label (@count)', ['@label' => $label, '@count' => (string) $count]),
        '#url' => Url::fromRoute('import_engine_ui.dead_letter', [], $id === NULL ? [] : ['query' => ['import' => $id]]),
        '#attributes' => ['class' => ['import-run-pill', $selected === $id ? 'is-active' : '']],
      ];
    }
    return $filter;
  }

  /**
   * Says in sentences what waits, why, and what can be done.
   *
   * @param list<int>|null $run_ids
   *   The runs that are looked at; all when NULL.
   * @param int $total
   *   How many items wait.
   *
   * @return list<string>
   *   Sentences, made by t() so that what is put in them is escaped.
   */
  private function queueSentences(?array $run_ids, int $total): array {
    if ($total === 0) {
      return [
        (string) $this->t('Nothing waits for a person.'),
        (string) $this->t('An item that runs out of attempts is put here, with its data, so that it can be fixed and tried again.'),
      ];
    }
    $lines = [(string) $this->formatPlural($total, '1 item ran out of attempts and waits for a person.', '@count items ran out of attempts and wait for a person.')];
    foreach ($this->items->countByError($run_ids, ItemState::Dead, 3) as $error => $count) {
      $lines[] = (string) $this->formatPlural($count, '1 item: @error', '@count items: @error', ['@error' => $error]);
    }
    $lines[] = (string) $this->t('Edit payload fixes the data of one item. Retry puts the selected items back in the queue for a worker. Handle now runs them straight away. Discard removes them, after asking.');
    return $lines;
  }

  /**
   * Returns the link to edit an item, if the person may.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function editLink(int $id): array {
    if (!$this->currentUser()->hasPermission('administer import runs')) {
      return [];
    }
    return Link::fromTextAndUrl($this->t('Edit payload'), Url::fromRoute('import_engine_ui.dead_letter_edit', ['item_id' => $id]))->toRenderable();
  }

  /**
   * Refuses a person who may only look.
   *
   * The buttons are hidden for them, which is a convenience; what they may do
   * is decided here as well.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   When the person may not administer import runs.
   */
  private function assertMayAdminister(): void {
    if (!$this->currentUser()->hasPermission('administer import runs')) {
      throw new AccessDeniedHttpException();
    }
  }

}
