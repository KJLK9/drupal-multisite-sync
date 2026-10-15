<?php

declare(strict_types=1);

namespace Drupal\import_engine\Schedule;

use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * What a schedule is, and when it is due.
 *
 * A schedule is "every so many minutes, from a time of day": every 60 minutes
 * from 00:15 is :15 past every hour; every 1440 minutes from 02:00 is daily at
 * two. Everything is in whole quarters of an hour, UTC. The scheduler is called
 * once per quarter (a Kubernetes CronJob, or the system cron, every 15
 * minutes), so nothing finer could ever run; a period has to be one that fits
 * in a day, so that every day looks the same.
 */
final class ScheduleRules {

  /**
   * The length of a slot, the time between two calls of the scheduler.
   */
  public const SLOT_SECONDS = 900;

  /**
   * The periods that can be chosen, in minutes: all quarters that divide a day.
   */
  public const EVERY = [15, 30, 60, 120, 180, 240, 360, 480, 720, 1440];

  /**
   * The schedule of something that has none.
   *
   * @return array{enabled: bool, every: int, start: int}
   *   Not enabled; hourly, from midnight, when it is.
   */
  public static function none(): array {
    return ['enabled' => FALSE, 'every' => 60, 'start' => 0];
  }

  /**
   * Returns the start of the slot a time is in.
   */
  public static function slotOf(int $time): int {
    return intdiv($time, self::SLOT_SECONDS) * self::SLOT_SECONDS;
  }

  /**
   * Returns whether a schedule is due in a slot.
   *
   * @param array{enabled: bool, every: int, start: int} $schedule
   *   The schedule.
   * @param int $slot
   *   The start of the slot, in seconds.
   */
  public static function isDue(array $schedule, int $slot): bool {
    if (!$schedule['enabled'] || $schedule['every'] < 15) {
      return FALSE;
    }
    $minute_of_day = intdiv($slot % 86400, 60);
    // PHP's % is negative for a negative left side; a start later in the day
    // than now is "the period before", so it is brought back into range.
    $since = (($minute_of_day - $schedule['start']) % $schedule['every'] + $schedule['every']) % $schedule['every'];
    return $since === 0;
  }

  /**
   * Config schema callback: a time of day in whole quarters of an hour.
   */
  public static function validateStart(mixed $value, ExecutionContextInterface $context): void {
    if (is_int($value) && ($value % 15 !== 0)) {
      $context->buildViolation('The start is a time of day in whole quarters of an hour.')->addViolation();
    }
  }

  /**
   * Describes a schedule in words, for lists.
   *
   * @param array{enabled: bool, every: int, start: int} $schedule
   *   The schedule.
   */
  public static function describe(array $schedule): string {
    if (!$schedule['enabled']) {
      return '';
    }
    $start = sprintf('%02d:%02d', intdiv($schedule['start'], 60), $schedule['start'] % 60);
    return match (TRUE) {
      $schedule['every'] === 1440 => sprintf('daily at %s UTC', $start),
      $schedule['every'] % 60 === 0 => sprintf('every %d hour(s) from %s UTC', intdiv($schedule['every'], 60), $start),
      default => sprintf('every %d minutes from %s UTC', $schedule['every'], $start),
    };
  }

}
