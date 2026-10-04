<?php

declare(strict_types=1);

namespace Drupal\import_engine_ui\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\import_engine\Breaker\BreakerStore;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Asks for confirmation to open or close a circuit breaker by hand.
 */
final class BreakerActionForm extends ConfirmFormBase {

  use AutowireTrait;

  /**
   * What to do: trip (open) or reset (close).
   */
  protected string $action = 'reset';

  /**
   * The server.
   */
  protected string $endpoint = '';

  /**
   * Constructs the form.
   */
  public function __construct(
    #[Autowire(service: 'import_engine.breaker_store')]
    protected BreakerStore $breakers,
    #[Autowire(service: 'datetime.time')]
    protected TimeInterface $clock,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'import_engine_ui_breaker_action';
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $action
   *   Trip or reset, from the route.
   * @param string $endpoint
   *   The server, from the route.
   *
   * @return array<string, mixed>
   *   The form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $action = '', string $endpoint = ''): array {
    if (!in_array($action, ['trip', 'reset'], TRUE) || $endpoint === '') {
      throw new NotFoundHttpException();
    }
    $this->action = $action;
    $this->endpoint = $endpoint;
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->action === 'trip'
      ? $this->t('Open the circuit breaker for @server?', ['@server' => $this->endpoint])
      : $this->t('Close the circuit breaker for @server?', ['@server' => $this->endpoint]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return $this->action === 'trip'
      ? $this->t('No import will call this server until the breaker is closed by hand.')
      : $this->t('Imports call this server again, and the failures are counted from zero.');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('import_engine_ui.breakers');
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
    $now = $this->clock->getCurrentTime();
    if ($this->action === 'trip') {
      $this->breakers->trip($this->endpoint, $now);
      $this->messenger()->addStatus($this->t('The circuit breaker for @server is open.', ['@server' => $this->endpoint]));
    }
    else {
      $this->breakers->reset($this->endpoint, $now);
      $this->messenger()->addStatus($this->t('The circuit breaker for @server is closed.', ['@server' => $this->endpoint]));
    }
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
