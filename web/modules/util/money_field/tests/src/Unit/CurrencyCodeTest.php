<?php

declare(strict_types=1);

namespace Drupal\Tests\money_field\Unit;

use Drupal\money_field\CurrencyCode;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the CurrencyCode enum.
 */
#[CoversClass(CurrencyCode::class)]
#[Group('money_field')]
class CurrencyCodeTest extends UnitTestCase {

  /**
   * Options are keyed by currency code and hold the label.
   */
  public function testOptions(): void {
    $options = CurrencyCode::options();
    $this->assertSame('Euro', $options['EUR']);
    $this->assertSame(['EUR', 'USD', 'GBP'], array_keys($options));
  }

}
