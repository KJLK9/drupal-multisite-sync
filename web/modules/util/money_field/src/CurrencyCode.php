<?php

declare(strict_types=1);

namespace Drupal\money_field;

/**
 * Supported currency codes for money_field.
 *
 * Do not remove a case without first writing a hook_update_N() that
 * migrates existing money_field data away from that currency. Removing
 * a case directly will cause save() to fail on any entity that still
 * references it, via the Choice constraint in MoneyFieldItem.
 */
enum CurrencyCode: string {
  case EUR = 'EUR';
  case USD = 'USD';
  case GBP = 'GBP';

  public function label(): string {
    return match ($this) {
      self::EUR => 'Euro',
      self::USD => 'US Dollar',
      self::GBP => 'British Pound',
    };
  }

  public static function options(): array {
    return array_combine(
      array_map(fn ($case) => $case->value, self::cases()),
      array_map(fn ($case) => $case->label(), self::cases())
    );
  }
}
