<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Guarantee;

use Drupal\import_engine\Schedule\ScheduleRules;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A schedule is due exactly when it says, for every period and every start.
 *
 * Over all the periods that can be chosen and all the starts of a day, in
 * every quarter of two days: the due quarters are the ones the schedule names,
 * evenly spread, the same on every day, and never off the grid.
 */
#[Group('guarantee')]
class ScheduleGuaranteeTest extends TestCase {

  /**
   * A day that starts at midnight UTC.
   */
  private const DAY = 1_790_000_000 - (1_790_000_000 % 86400);

  /**
   * Every period with a few starts, including the ones at the edges of a day.
   *
   * @return array<string, array{int, int}>
   *   The period in minutes and the start in minutes.
   */
  public static function schedules(): array {
    $cases = [];
    foreach (ScheduleRules::EVERY as $every) {
      foreach ([0, 15, 45, 60, 600, 1425] as $start) {
        $cases["every $every from $start"] = [$every, $start];
      }
    }
    return $cases;
  }

  /**
   * The quarters of two days in which the schedule is due.
   *
   * @return list<int>
   *   The offsets, in minutes from the first midnight.
   */
  private function dueMinutes(int $every, int $start): array {
    $schedule = ['enabled' => TRUE, 'every' => $every, 'start' => $start];
    $due = [];
    for ($slot = self::DAY; $slot < self::DAY + 2 * 86400; $slot += ScheduleRules::SLOT_SECONDS) {
      if (ScheduleRules::isDue($schedule, $slot)) {
        $due[] = intdiv($slot - self::DAY, 60);
      }
    }
    return $due;
  }

  /**
   * The due quarters follow the period, from the start, on both days.
   */
  #[\PHPUnit\Framework\Attributes\DataProvider('schedules')]
  public function testDueQuartersFollowThePeriod(int $every, int $start): void {
    $due = $this->dueMinutes($every, $start);

    $this->assertNotSame([], $due);
    foreach ($due as $minute) {
      $this->assertSame(0, $minute % 15, 'Only on the quarters.');
      $this->assertSame(0, (($minute % 1440) - $start + $every * 100) % $every, 'On the grid of the period, from the start.');
    }
    $steps = [];
    for ($i = 1; $i < count($due); $i++) {
      $steps[$due[$i] - $due[$i - 1]] = TRUE;
    }
    $this->assertSame([$every], array_keys($steps), 'Evenly spread, a period apart.');
    // The same on the second day as on the first (the period divides a day).
    $first = array_filter($due, static fn (int $m): bool => $m < 1440);
    $second = array_map(static fn (int $m): int => $m - 1440, array_filter($due, static fn (int $m): bool => $m >= 1440));
    $this->assertSame(array_values($first), array_values($second), 'Every day looks the same.');
    $this->assertCount((int) (1440 / $every), $first, 'As many times a day as the period gives.');
  }

  /**
   * A schedule that is off is never due, and neither is one with no period.
   */
  public function testOffAndNonsenseAreNeverDue(): void {
    for ($slot = self::DAY; $slot < self::DAY + 86400; $slot += ScheduleRules::SLOT_SECONDS) {
      $this->assertFalse(ScheduleRules::isDue(['enabled' => FALSE, 'every' => 15, 'start' => 0], $slot));
      $this->assertFalse(ScheduleRules::isDue(['enabled' => TRUE, 'every' => 5, 'start' => 0], $slot), 'Under a quarter is not allowed.');
    }
  }

  /**
   * Every time in a quarter belongs to that quarter and no other.
   */
  public function testSlotBelongsToItsQuarter(): void {
    foreach ([0, 1, 899, 900, 901, 1799, 1800] as $offset) {
      $slot = ScheduleRules::slotOf(self::DAY + $offset);
      $this->assertSame(0, ($slot - self::DAY) % 900);
      $this->assertLessThanOrEqual(self::DAY + $offset, $slot);
      $this->assertGreaterThan(self::DAY + $offset - 900, $slot);
    }
  }

}
