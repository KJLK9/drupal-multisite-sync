<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Unit;

use Drupal\import_engine\Plugin\ImportEngine\Validator\ChoiceValidator;
use Drupal\import_engine\Plugin\ImportEngine\Validator\EmailValidator;
use Drupal\import_engine\Plugin\ImportEngine\Validator\LengthValidator;
use Drupal\import_engine\Plugin\ImportEngine\Validator\NotBlankValidator;
use Drupal\import_engine\Plugin\ImportEngine\Validator\PhoneValidator;
use Drupal\import_engine\Plugin\ImportEngine\Validator\RangeValidator;
use Drupal\import_engine\Plugin\ImportEngine\Validator\RegexValidator;
use Drupal\import_engine\Plugin\ImportEngine\Validator\UrlValidator;
use Drupal\import_engine\Validator\ValidatorInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests what the validators accept, what they refuse, and what they say.
 */
#[Group('import_engine')]
#[CoversClass(EmailValidator::class)]
#[CoversClass(UrlValidator::class)]
#[CoversClass(PhoneValidator::class)]
#[CoversClass(RegexValidator::class)]
#[CoversClass(LengthValidator::class)]
#[CoversClass(RangeValidator::class)]
#[CoversClass(ChoiceValidator::class)]
#[CoversClass(NotBlankValidator::class)]
class ValidatorsTest extends UnitTestCase {

  /**
   * Builds a validator.
   *
   * @param class-string<\Drupal\import_engine\Validator\ValidatorInterface> $class
   *   The class.
   * @param array<string, mixed> $settings
   *   Its settings.
   */
  protected function make(string $class, array $settings = []): ValidatorInterface {
    return new $class($settings, 'test', []);
  }

  /**
   * Checks values: those that are valid, and the form they are kept in.
   *
   * @param class-string<\Drupal\import_engine\Validator\ValidatorInterface> $class
   *   The validator.
   * @param array<string, mixed> $settings
   *   Its settings.
   * @param string|int|float $value
   *   The value.
   * @param string|int|float|null $kept
   *   What is kept when it is valid, NULL when it is not valid.
   */
  #[DataProvider('cases')]
  public function testValues(string $class, array $settings, string|int|float $value, string|int|float|NULL $kept): void {
    $outcome = $this->make($class, $settings)->validate($value);

    $this->assertSame($kept !== NULL, $outcome->valid);
    if ($kept !== NULL) {
      $this->assertSame($kept, $outcome->value);
      $this->assertNull($outcome->reason);
    }
    else {
      $this->assertNotSame('', (string) $outcome->reason);
      // A reason is kept in events that others read: never the value. (A value
      // of a character or two can be in the words of the reason by chance.)
      if (strlen((string) $value) >= 4) {
        $this->assertStringNotContainsString((string) $value, (string) $outcome->reason);
      }
    }
  }

  /**
   * The values and what the validators make of them.
   *
   * @return array<string, array{class-string, array<string, mixed>, string|int|float, string|int|float|null}>
   *   The class, settings, value and what is kept (NULL: not valid).
   */
  public static function cases(): array {
    $phone = ['default_country_code' => '31'];
    return [
      'email: plain' => [EmailValidator::class, [], 'jan@example.nl', 'jan@example.nl'],
      'email: with a plus and subdomains' => [
        EmailValidator::class,
        [],
        'first.last+tag@mail.example.co.uk',
        'first.last+tag@mail.example.co.uk',
      ],
      'email: spaces around are removed' => [EmailValidator::class, [], '  jan@example.nl ', 'jan@example.nl'],
      'email: no at' => [EmailValidator::class, [], 'jan.example.nl', NULL],
      'email: no domain' => [EmailValidator::class, [], 'jan@', NULL],
      'email: no dot in the domain' => [EmailValidator::class, [], 'jan@localhost', NULL],
      'email: two ats' => [EmailValidator::class, [], 'jan@@example.nl', NULL],
      'email: a space inside' => [EmailValidator::class, [], 'jan jansen@example.nl', NULL],
      'email: longer than an address can be' => [EmailValidator::class, [], str_repeat('a', 250) . '@example.nl', NULL],
      'url: https with a path and a query' => [
        UrlValidator::class,
        [],
        'https://example.com/a/b?c=1',
        'https://example.com/a/b?c=1',
      ],
      'url: http' => [UrlValidator::class, [], 'http://example.com', 'http://example.com'],
      'url: another scheme' => [UrlValidator::class, [], 'ftp://example.com/file', NULL],
      'url: javascript' => [UrlValidator::class, [], 'javascript:alert(1)', NULL],
      'url: no scheme' => [UrlValidator::class, [], 'example.com/page', NULL],
      'url: no host' => [UrlValidator::class, [], 'https://', NULL],
      'phone: international with spaces' => [PhoneValidator::class, $phone, '+31 6 1234 5678', '+31612345678'],
      'phone: national with a code to add' => [PhoneValidator::class, $phone, '06-12345678', '+31612345678'],
      'phone: with brackets' => [PhoneValidator::class, $phone, '(020) 123 4567', '+31201234567'],
      'phone: the 0 in brackets is left out' => [PhoneValidator::class, [], '+31 (0)20 123 4567', '+31201234567'],
      'phone: written with 00' => [PhoneValidator::class, [], '0031 6 12345678', '+31612345678'],
      'phone: kept as it was when not normalizing' => [
        PhoneValidator::class,
        ['normalize' => FALSE],
        '+31 6 1234 5678',
        '+31 6 1234 5678',
      ],
      'phone: national without a code to add' => [PhoneValidator::class, [], '0612345678', NULL],
      'phone: letters' => [PhoneValidator::class, $phone, '06-ABC-1234', NULL],
      'phone: too short' => [PhoneValidator::class, $phone, '12345', NULL],
      'phone: too long' => [PhoneValidator::class, [], '+1234567890123456', NULL],
      'phone: a zero after the plus' => [PhoneValidator::class, [], '+0612345678', NULL],
      'phone: nothing but separators' => [PhoneValidator::class, [], '-- --', NULL],
      'regex: matches' => [RegexValidator::class, ['pattern' => '/^[A-Z]{2}[0-9]{4}$/'], 'AB1234', 'AB1234'],
      'regex: does not match' => [RegexValidator::class, ['pattern' => '/^[A-Z]{2}[0-9]{4}$/'], 'ab1234', NULL],
      'regex: a pattern that does not compile is not valid' => [RegexValidator::class, ['pattern' => '/[/'], 'x', NULL],
      'regex: no pattern is not valid' => [RegexValidator::class, [], 'x', NULL],
      'length: within' => [LengthValidator::class, ['min' => 2, 'max' => 5], 'abc', 'abc'],
      'length: counts characters, not bytes' => [LengthValidator::class, ['max' => 3], 'éèê', 'éèê'],
      'length: too short' => [LengthValidator::class, ['min' => 2], 'a', NULL],
      'length: too long' => [LengthValidator::class, ['max' => 3], 'abcd', NULL],
      'length: no limits' => [LengthValidator::class, [], str_repeat('x', 1000), str_repeat('x', 1000)],
      'range: within' => [RangeValidator::class, ['min' => 1, 'max' => 10], 5, 5],
      'range: the limits themselves' => [RangeValidator::class, ['min' => 1, 'max' => 10], '10', '10'],
      'range: below' => [RangeValidator::class, ['min' => 1.5], 1, NULL],
      'range: above' => [RangeValidator::class, ['max' => 10], 10.01, NULL],
      'range: not a number' => [RangeValidator::class, [], 'ten', NULL],
      'range: only a maximum' => [RangeValidator::class, ['max' => 10], -5, -5],
      'choice: one of them, as written in the list' => [
        ChoiceValidator::class,
        ['allowed' => ['Gold', 'Silver']],
        'gold',
        'Gold',
      ],
      'choice: with capitals that must match' => [
        ChoiceValidator::class,
        ['allowed' => ['Gold'], 'case_sensitive' => TRUE],
        'gold',
        NULL,
      ],
      'choice: not in the list' => [ChoiceValidator::class, ['allowed' => ['Gold', 'Silver']], 'bronze', NULL],
      'not blank: a value' => [NotBlankValidator::class, [], 'x', 'x'],
      'not blank: zero is a value' => [NotBlankValidator::class, [], 0, 0],
      'not blank: empty' => [NotBlankValidator::class, [], '', NULL],
      'not blank: white space' => [NotBlankValidator::class, [], "  \t ", NULL],
    ];
  }

  /**
   * Only the validator about emptiness judges an empty value.
   */
  public function testOnlyNotBlankJudgesEmptiness(): void {
    $classes = [
      EmailValidator::class,
      UrlValidator::class,
      PhoneValidator::class,
      RegexValidator::class,
      LengthValidator::class,
      RangeValidator::class,
      ChoiceValidator::class,
    ];
    foreach ($classes as $class) {
      $this->assertFalse($this->make($class)->validatesEmpty(), $class);
    }
    $this->assertTrue($this->make(NotBlankValidator::class)->validatesEmpty());
  }

  /**
   * A pattern is usable when it compiles.
   */
  public function testUsablePatterns(): void {
    $this->assertTrue(RegexValidator::isUsablePattern('/^[0-9]+$/'));
    $this->assertTrue(RegexValidator::isUsablePattern('#^a|b$#i'));
    $this->assertFalse(RegexValidator::isUsablePattern('abc'));
    $this->assertFalse(RegexValidator::isUsablePattern('/[/'));
    $this->assertFalse(RegexValidator::isUsablePattern(''));
  }

}
