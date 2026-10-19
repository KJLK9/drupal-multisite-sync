<?php

declare(strict_types=1);

namespace Drupal\import_audit\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\import_audit\Hook\CronHooks;

/**
 * How long the changes of the imports are kept.
 */
final class AuditSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'import_audit_settings';
  }

  /**
   * {@inheritdoc}
   *
   * @return list<string>
   *   The names of the configuration objects the form edits.
   */
  protected function getEditableConfigNames(): array {
    return ['import_audit.settings'];
  }

  /**
   * {@inheritdoc}
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The state of the form.
   *
   * @return array<mixed>
   *   The form.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['retention_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Days to keep the changes'),
      '#description' => $this->t('The old and new value of every field that an import changed. They can hold personal data, so keep them no longer than you need. The events of the engine itself are kept as long as the engine setting says.'),
      '#min' => 1,
      '#max' => 3650,
      '#required' => TRUE,
      '#default_value' => $this->config('import_audit.settings')->get('retention_days') ?? CronHooks::DEFAULT_DAYS,
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The state of the form.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('import_audit.settings')
      ->set('retention_days', (int) $form_state->getValue('retention_days'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
