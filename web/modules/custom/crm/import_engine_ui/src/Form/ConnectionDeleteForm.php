<?php

declare(strict_types=1);

namespace Drupal\import_engine_ui\Form;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\import_engine\ImportConnectionInterface;
use Drupal\import_engine\Storage\ImportConnectionStorage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Asks for confirmation to delete a connection that no import uses.
 */
final class ConnectionDeleteForm extends ConfirmFormBase {

  use AutowireTrait;

  /**
   * The connection to delete.
   */
  protected ?ImportConnectionInterface $connection = NULL;

  /**
   * Constructs the form.
   */
  public function __construct(
    #[Autowire(service: 'entity_type.manager')]
    protected EntityTypeManagerInterface $entityTypes,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'import_engine_ui_connection_delete';
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\import_engine\ImportConnectionInterface|null $import_connection
   *   The connection, from the route.
   *
   * @return array<string, mixed>
   *   The form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?ImportConnectionInterface $import_connection = NULL): array {
    $this->connection = $import_connection;
    $form = parent::buildForm($form, $form_state);
    $used = $this->usedBy();
    if ($used !== []) {
      // Nothing to confirm: say why it cannot be done.
      $form['blocked'] = [
        '#markup' => '<p>' . $this->t('The imports @imports use this connection. Give them another connection, or their own settings, first.', ['@imports' => implode(', ', $used)]) . '</p>',
        '#weight' => -5,
      ];
      unset($form['actions']['submit']);
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Delete the connection @label?', ['@label' => (string) $this->connection?->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return $this->t('No import uses it. This cannot be undone.');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('import_engine_ui.connections');
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
    if ($this->connection === NULL || $this->usedBy() !== []) {
      return;
    }
    $label = (string) $this->connection->label();
    $this->connection->delete();
    $this->messenger()->addStatus($this->t('The connection @label is deleted.', ['@label' => $label]));
  }

  /**
   * Returns the imports that use the connection.
   *
   * @return list<string>
   *   Their IDs.
   */
  private function usedBy(): array {
    $storage = $this->entityTypes->getStorage('import_connection');
    if ($this->connection === NULL || !$storage instanceof ImportConnectionStorage) {
      return [];
    }
    return $storage->usedBy((string) $this->connection->id());
  }

}
