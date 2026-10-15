<?php

declare(strict_types=1);

namespace Drupal\import_engine_ui\Form;

use Drupal\import_engine\Schedule\ScheduleRules;

/**
 * The fields of a schedule, for the forms of imports and of run sets.
 *
 * The class that uses it has the string translation of a form ($this->t()).
 */
trait ScheduleFormTrait {

  /**
   * Builds the fields of a schedule.
   *
   * @param array{enabled: bool, every: int, start: int} $schedule
   *   The schedule as it is.
   *
   * @return array<string, mixed>
   *   A details element.
   */
  protected function scheduleElement(array $schedule): array {
    $every = [];
    foreach (ScheduleRules::EVERY as $minutes) {
      $every[$minutes] = match (TRUE) {
        $minutes === 60 => $this->t('every hour'),
        $minutes === 1440 => $this->t('every day'),
        $minutes % 60 === 0 => $this->t('every @count hours', ['@count' => (string) intdiv($minutes, 60)]),
        default => $this->t('every @count minutes', ['@count' => (string) $minutes]),
      };
    }
    $start = [];
    for ($minute = 0; $minute < 1440; $minute += 15) {
      $start[$minute] = sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
    }

    return [
      '#type' => 'details',
      '#title' => $this->t('Schedule'),
      '#open' => $schedule['enabled'],
      '#description' => $this->t('Runs by itself, on the quarter of an hour that is due. Something has to ask every 15 minutes what is due: the job that runs <code>drush import:run-due</code> (docs/scheduling.md). Times are UTC.'),
      'enabled' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Run by itself'),
        '#default_value' => $schedule['enabled'],
      ],
      'every' => [
        '#type' => 'select',
        '#title' => $this->t('How often'),
        '#options' => $every,
        '#default_value' => $schedule['every'],
      ],
      'start' => [
        '#type' => 'select',
        '#title' => $this->t('From'),
        '#description' => $this->t('The time of day (UTC) of the first run; the others follow every period from there. For every day: the time it runs at.'),
        '#options' => $start,
        '#default_value' => $schedule['start'],
      ],
    ];
  }

  /**
   * Turns the submitted fields of a schedule into a schedule.
   *
   * @param array<string, mixed> $input
   *   What was submitted.
   *
   * @return array{enabled: bool, every: int, start: int}
   *   The schedule; what is not one of the choices becomes the default.
   */
  protected function scheduleFrom(array $input): array {
    $every = (int) ($input['every'] ?? 60);
    $start = (int) ($input['start'] ?? 0);
    return [
      'enabled' => !empty($input['enabled']),
      'every' => in_array($every, ScheduleRules::EVERY, TRUE) ? $every : 60,
      'start' => $start >= 0 && $start < 1440 ? $start - ($start % 15) : 0,
    ];
  }

}
