<?php

declare(strict_types=1);

namespace Drupal\import_engine\Plugin\ImportEngine\Mapper;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\import_engine\Attribute\ImportMapper;
use Drupal\import_engine\Mapper\MapperPluginBase;
use Drupal\import_engine\Mapper\MappingException;
use Drupal\import_engine\Target\TargetField;

/**
 * Maps an amount and a currency to a money field.
 *
 * Sources: amount (required) and currency. Setting: default_currency, used
 * when the item has no currency.
 */
#[ImportMapper(
  id: 'money',
  label: new TranslatableMarkup('Amount and currency'),
  field_types: ['money_field'],
  sources: ['amount' => TRUE, 'currency' => FALSE],
  description: new TranslatableMarkup('An amount with a currency code.'),
)]
final class MoneyMapper extends MapperPluginBase {

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The default settings.
   */
  public function defaultConfiguration(): array {
    return ['default_currency' => 'EUR'];
  }

  /**
   * {@inheritdoc}
   */
  public function map(array $sources, TargetField $field): mixed {
    $amount = $this->text($sources['amount'] ?? NULL);
    if ($amount === NULL || trim($amount) === '') {
      return NULL;
    }
    $amount = trim($amount);
    if (!is_numeric($amount)) {
      throw new MappingException(sprintf('"%s" is not an amount.', $amount));
    }
    $currency = $this->text($sources['currency'] ?? NULL);
    $currency = strtoupper(trim($currency ?? '')) ?: (string) $this->configuration['default_currency'];
    if (!preg_match('/^[A-Z]{3}$/', $currency)) {
      throw new MappingException(sprintf('"%s" is not a currency code.', $currency));
    }
    return ['number' => $amount, 'currency_code' => $currency];
  }

}
