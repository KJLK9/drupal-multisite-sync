<?php

declare(strict_types=1);

namespace Drupal\import_engine_ui\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * How long the engine keeps what it stores, and whether cron goes on with runs.
 *
 * The stores have different lifetimes (ADR 0009): the work queue is short, the
 * history is long. The purge runs from cron in bounded batches.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * The settings that are a number of days.
   */
  private const RETENTION_KEYS = [
    'retention_items_days',
    'retention_dead_days',
    'retention_events_days',
    'retention_runs_days',
  ];

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'import_engine_ui_settings';
  }

  /**
   * {@inheritdoc}
   *
   * @return list<string>
   *   The names of the configuration objects the form edits.
   */
  protected function getEditableConfigNames(): array {
    return ['import_engine.settings'];
  }

  /**
   * {@inheritdoc}
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<mixed>
   *   The form.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('import_engine.settings');
    $form['#attributes']['class'][] = 'import-engine-form';
    $form['#attached']['library'][] = 'import_engine_ui/wizard';

    $form['retention'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('How long things are kept, in days'),
      '#description' => $this->t('Cron removes what is older, a bounded amount per run. 0 keeps it for ever. The tables grow with the data and with what changes, not with the number of runs, so the defaults are meant to be left alone unless there is a reason.'),
    ];
    $form['retention']['retention_items_days'] = $this->days(
      'retention_items_days',
      $this->t('Items that are done'),
      $this->t('The work queue of a run. An item that is done only keeps its key, hash and outcome.'),
    );
    $form['retention']['retention_dead_days'] = $this->days(
      'retention_dead_days',
      $this->t('Dead items (the dead letter queue)'),
      $this->t('Items that ran out of attempts, with their payload, so they can be edited and retried.'),
    );
    $form['retention']['retention_events_days'] = $this->days(
      'retention_events_days',
      $this->t('Events (the history)'),
      $this->t('What changed or went wrong, per item: the answer to "what happened to this item".'),
    );
    $form['retention']['retention_runs_days'] = $this->days(
      'retention_runs_days',
      $this->t('Finished runs'),
      $this->t('One row per run, with its counters.'),
    );

    $form['cron'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Cron'),
    ];
    $form['cron']['cron_resume_seconds'] = [
      '#type' => 'number',
      '#title' => $this->t('Seconds per cron run to continue runs that are not over'),
      '#description' => $this->t('0 (the default) leaves cron alone. Cron only continues runs that are already going; it never starts one. Starting is up to a person, a scheduled drush command or a job (see docs/scheduling.md).'),
      '#default_value' => (int) $config->get('cron_resume_seconds'),
      '#min' => 0,
      '#max' => 3600,
      '#required' => TRUE,
    ];
    return parent::buildForm($form, $form_state);
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
    $config = $this->config('import_engine.settings');
    foreach ([...self::RETENTION_KEYS, 'cron_resume_seconds'] as $key) {
      $config->set($key, (int) $form_state->getValue($key));
    }
    $config->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * Builds the field of a number of days.
   *
   * @param string $key
   *   The setting.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $title
   *   The title.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $description
   *   What it keeps.
   *
   * @return array<string, mixed>
   *   The field.
   */
  private function days(string $key, TranslatableMarkup $title, TranslatableMarkup $description): array {
    return [
      '#type' => 'number',
      '#title' => $title,
      '#description' => $description,
      '#default_value' => (int) $this->config('import_engine.settings')->get($key),
      '#min' => 0,
      '#max' => 3650,
      '#required' => TRUE,
    ];
  }

}
