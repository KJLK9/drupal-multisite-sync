<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Unit;

use Drupal\import_engine\Schedule\ScheduleRules;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests when a schedule is due.
 */
#[Group('import_engine')]
#[CoversClass(ScheduleRules::class)]
class ScheduleRulesTest extends UnitTestCase {

  /**
   * Returns a time on a day, as a timestamp.
   */
  protected static function at(string $time): int {
    return (int) strtotime('2026-10-12 ' . $time . ' UTC');
  }

  /**
   * A time is in the slot of its quarter of an hour.
   */
  public function testSlots(): void {
    $this->assertSame(self::at('10:15:00'), ScheduleRules::slotOf(self::at('10:15:00')));
    $this->assertSame(self::at('10:15:00'), ScheduleRules::slotOf(self::at('10:29:59')));
    $this->assertSame(self::at('10:30:00'), ScheduleRules::slotOf(self::at('10:30:00')));
  }

  /**
   * Which quarters of the day a schedule is due in.
   *
   * @param array{enabled: bool, every: int, start: int} $schedule
   *   The schedule.
   * @param list<string> $due
   *   Times of day, out of those of the day, at which it is due.
   */
  #[DataProvider('schedules')]
  public function testDueAt(array $schedule, array $due): void {
    $found = [];
    for ($slot = self::at('00:00:00'); $slot < self::at('00:00:00') + 86400; $slot += 900) {
      if (ScheduleRules::isDue($schedule, $slot)) {
        $found[] = gmdate('H:i', $slot);
      }
    }
    $this->assertSame($due, $found);
  }

  /**
   * Schedules and the times they are due.
   *
   * @return array<string, array{array{enabled: bool, every: int, start: int}, list<string>}>
   *   The schedules and the times.
   */
  public static function schedules(): array {
    $every = static fn (int $minutes, int $start = 0, bool $on = TRUE): array => [
      'enabled' => $on,
      'every' => $minutes,
      'start' => $start,
    ];
    // The times of day that start at a minute past the hour and repeat.
    $hours = static fn (string $minute, int $step = 1, int $from = 0): array => array_map(
      static fn (int $hour): string => sprintf('%02d:%s', $hour, $minute),
      range($from, 23, $step),
    );
    return [
      'not enabled' => [$every(15, 0, FALSE), []],
      'every quarter' => [
        $every(15),
        array_map(static fn (int $i): string => gmdate('H:i', $i * 900), range(0, 95)),
      ],
      'hourly, at a quarter past' => [$every(60, 15), $hours('15')],
      'every 2 hours from 01:15' => [$every(120, 75), $hours('15', 2, 1)],
      'every 6 hours from midnight' => [$every(360), ['00:00', '06:00', '12:00', '18:00']],
      'every 6 hours from 02:00' => [$every(360, 120), ['02:00', '08:00', '14:00', '20:00']],
      'a start late in the day' => [$every(360, 1425), ['05:45', '11:45', '17:45', '23:45']],
      'daily at 02:00' => [$every(1440, 120), ['02:00']],
      'daily at midnight' => [$every(1440), ['00:00']],
      'twice an hour' => [
        $every(30, 45),
        array_merge(...array_map(static fn (string $h): array => ["$h:15", "$h:45"], array_map(static fn (int $h): string => sprintf('%02d', $h), range(0, 23)))),
      ],
    ];
  }

  /**
   * Every period that can be chosen runs the same number of times every day.
   */
  public function testEveryPeriodRepeatsEvenlyPerDay(): void {
    foreach (ScheduleRules::EVERY as $minutes) {
      $runs = 0;
      for ($slot = 0; $slot < 86400; $slot += 900) {
        $runs += ScheduleRules::isDue(['enabled' => TRUE, 'every' => $minutes, 'start' => 0], $slot) ? 1 : 0;
      }
      $this->assertSame(intdiv(1440, $minutes), $runs, 'Every ' . $minutes . ' minutes.');
    }
  }

  /**
   * A schedule is described in words.
   */
  public function testDescribe(): void {
    $on = static fn (int $every, int $start): array => [
      'enabled' => TRUE,
      'every' => $every,
      'start' => $start,
    ];
    $this->assertSame('', ScheduleRules::describe(ScheduleRules::none()));
    $this->assertSame('daily at 02:00 UTC', ScheduleRules::describe($on(1440, 120)));
    $this->assertSame('every 2 hour(s) from 01:15 UTC', ScheduleRules::describe($on(120, 75)));
    $this->assertSame('every 15 minutes from 00:00 UTC', ScheduleRules::describe($on(15, 0)));
  }

}
