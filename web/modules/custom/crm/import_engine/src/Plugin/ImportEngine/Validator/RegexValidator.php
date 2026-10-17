<?php

declare(strict_types=1);

namespace Drupal\import_engine\Plugin\ImportEngine\Validator;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\import_engine\Attribute\ImportValidator;
use Drupal\import_engine\Validator\ValidationOutcome;
use Drupal\import_engine\Validator\ValidatorPluginBase;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Accepts a text that matches a regular expression.
 *
 * The expression is a PCRE with its delimiters, for example
 * /^[A-Z]{2}[0-9]{4}$/. It is set by someone who may edit imports, and its
 * running time is limited by PHP's backtrack limit; a text that cannot be
 * judged within it is not valid.
 */
#[ImportValidator(
  id: 'regex',
  label: new TranslatableMarkup('Pattern (regular expression)'),
  mappers: ['string', 'join', 'text'],
  description: new TranslatableMarkup('The text must match a regular expression.'),
  weight: 10,
)]
final class RegexValidator extends ValidatorPluginBase {

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The default settings.
   */
  public function defaultConfiguration(): array {
    return ['pattern' => ''];
  }

  /**
   * Returns whether a pattern can be used: it compiles.
   */
  public static function isUsablePattern(string $pattern): bool {
    return $pattern !== '' && @preg_match($pattern, '') !== FALSE;
  }

  /**
   * Config schema callback: the pattern compiles.
   */
  public static function validatePattern(mixed $value, ExecutionContextInterface $context): void {
    if (is_string($value) && $value !== '' && !self::isUsablePattern($value)) {
      $context->buildViolation('The pattern is not a valid regular expression; write it with delimiters, for example /^[0-9]+$/.')->addViolation();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validate(string|int|float $value): ValidationOutcome {
    $pattern = (string) $this->configuration['pattern'];
    $result = self::isUsablePattern($pattern) ? @preg_match($pattern, (string) $value) : FALSE;
    if ($result === 1) {
      return ValidationOutcome::valid($value);
    }
    return ValidationOutcome::invalid($result === 0 ? 'does not match the pattern' : 'could not be checked against the pattern');
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
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['pattern'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Pattern'),
      '#description' => $this->t('A regular expression with delimiters, for example <code>/^[A-Z]{2}[0-9]{4}$/</code>.'),
      '#default_value' => $this->configuration['pattern'],
      '#required' => TRUE,
    ];
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
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
    if (!self::isUsablePattern((string) $form_state->getValue('pattern'))) {
      $form_state->setErrorByName('pattern', $this->t('This is not a valid regular expression. Write it with delimiters, for example /^[0-9]+$/.'));
    }
  }

}
