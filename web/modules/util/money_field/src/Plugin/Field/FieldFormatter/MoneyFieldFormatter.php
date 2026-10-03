<?php

declare(strict_types=1);

namespace Drupal\money_field\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Plugin implementation of the 'Money field' formatter.
 */
#[FieldFormatter(
  id: 'money_field',
  label: new TranslatableMarkup('Money field'),
  field_types: ['money_field'],
)]
class MoneyFieldFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    return array_map(function ($item) {
      return [
        '#markup' => $this->t('@amount @currency', [
          '@amount' => $item->number,
          '@currency' => $item->currency_code,
        ]),
      ];
    }, (array) $items);
  }

}
