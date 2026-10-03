<?php

declare(strict_types=1);

namespace Drupal\money_field\Plugin\Field\FieldWidget;

use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\money_field\CurrencyCode;

/**
 * Defines the 'money_field' field widget.
 */
#[FieldWidget(
  id: 'money_field',
  label: new TranslatableMarkup('Money field'),
  field_types: ['money_field'],
)]
final class MoneyFieldWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   *
   * @param \Drupal\Core\Field\FieldItemListInterface<\Drupal\Core\Field\FieldItemInterface> $items
   *   The field items.
   * @param mixed $delta
   *   The delta of the item.
   * @param array<string, mixed> $element
   *   The form element.
   * @param array<string, mixed> $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array<string, mixed>
   *   The form element.
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state): array {
    $item = $items[$delta];

    $element['number'] = [
      '#type' => 'number',
      '#title' => $this->t('Amount'),
      '#default_value' => $item->number ?? NULL,
      '#step' => 0.01,
      '#min' => 0,
    ];

    $element['currency_code'] = [
      '#type' => 'select',
      '#title' => $this->t('Currency'),
      '#options' => CurrencyCode::options(),
      '#default_value' => $item->currency_code ?? NULL,
    ];

    return $element;
  }

}
