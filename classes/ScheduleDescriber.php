<?php

/**
 * @file classes/ScheduleDescriber.php
 *
 * Copyright (c) 2026 Touhidur Rahman
 * Distributed under The MIT License. For full terms see the file LICENSE.
 *
 * @class ScheduleDescriber
 *
 * @brief Turns a cron expression into something a site administrator can read, and resolves
 *        the next occurrence of a scheduled event.
 */

namespace APP\plugins\generic\scheduledTaskManager\classes;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Event;
use PKP\facades\Locale;
use Throwable;

class ScheduleDescriber
{
    /**
     * Date format for every absolute timestamp. ISO rather than PHP syntax so Carbon localises it:
     * `ll` is the locale's own short date ("Sep 9, 2026" but "9 sept. 2026"), while the time stays
     * an explicit 24 hour clock -- a schedule is no place to parse an am/pm suffix.
     */
    public const DATE_FORMAT = 'll HH:mm';

    /**
     * The timezone the scheduler actually matches against: what initializeTimeZone() resolved from
     * [general] time_zone, and what the Schedule singleton was built with.
     */
    public static function applicationTimezone(): string
    {
        return date_default_timezone_get();
    }

    /**
     * The timezone a specific event runs in, which is the application's unless the event
     * deliberately opted out.
     */
    public static function eventTimezone(Event $event): string
    {
        return $event->timezone ? (string) $event->timezone : static::applicationTimezone();
    }

    /**
     * Next time the event is due, in the application timezone. Resolved in the event's own zone
     * first, as Laravel's ScheduleListCommand::getNextDueDateForEvent() does it.
     */
    public static function nextRun(Event $event): ?Carbon
    {
        $applicationTimezone = static::applicationTimezone();
        $eventTimezone = static::eventTimezone($event);

        try {
            return Carbon::instance(
                (new CronExpression($event->getExpression()))
                    ->getNextRunDate(Carbon::now($eventTimezone))
            )->setTimezone($applicationTimezone);
        } catch (Throwable $exception) {
            // A malformed expression should cost this one column, not the whole page.
            return null;
        }
    }

    /**
     * The most recent occurrence the event was due at, in the application timezone. Mirrors the
     * call ScheduleTaskRunner::run() makes to decide catch-up, arguments included, so the page's
     * notion of "should already have run" cannot drift from core's.
     */
    public static function previousRun(Event $event): ?Carbon
    {
        try {
            return Carbon::instance(
                (new CronExpression($event->getExpression()))
                    ->getPreviousRunDate(Carbon::now(), 0, true, $event->timezone ?: null)
            )->setTimezone(static::applicationTimezone());
        } catch (Throwable $exception) {
            return null;
        }
    }

    /**
     * A human label for a cron expression, matched on field shape rather than literal strings so
     * dailyAt('13:00') still reads as "Daily". Anything unrecognised falls back to "Custom"; the
     * raw expression is always shown alongside, so nothing is hidden.
     */
    public static function intervalLabel(string $expression): string
    {
        $fields = preg_split('/\s+/', trim($expression));

        if (count($fields) < 5) {
            return __('plugins.generic.scheduledTaskManager.interval.custom');
        }

        [$minute, $hour, $dayOfMonth, $month, $dayOfWeek] = $fields;

        if ($step = static::stepOf($minute)) {
            return $step === 1
                ? __('plugins.generic.scheduledTaskManager.interval.everyMinute')
                : __('plugins.generic.scheduledTaskManager.interval.everyMinutes', ['count' => $step]);
        }

        if ($step = static::stepOf($hour)) {
            return $step === 1
                ? __('plugins.generic.scheduledTaskManager.interval.hourly')
                : __('plugins.generic.scheduledTaskManager.interval.everyHours', ['count' => $step]);
        }

        $everyDayOfMonth = $dayOfMonth === '*';
        $everyMonth = $month === '*';
        $everyDayOfWeek = $dayOfWeek === '*';

        if ($everyDayOfMonth && $everyMonth && $everyDayOfWeek) {
            return __('plugins.generic.scheduledTaskManager.interval.daily');
        }

        if ($everyDayOfMonth && $everyMonth && !$everyDayOfWeek) {
            return __('plugins.generic.scheduledTaskManager.interval.weekly');
        }

        if (!$everyDayOfMonth && $everyMonth) {
            return __('plugins.generic.scheduledTaskManager.interval.monthly');
        }

        // quarterly() compiles to `0 0 1 1-12/3 *`; a four-value month list means the same thing.
        if (!$everyDayOfMonth && (static::stepOf($month) === 3 || count(explode(',', $month)) === 4)) {
            return __('plugins.generic.scheduledTaskManager.interval.quarterly');
        }

        if (!$everyDayOfMonth && !$everyMonth && ctype_digit($month)) {
            return __('plugins.generic.scheduledTaskManager.interval.yearly');
        }

        return __('plugins.generic.scheduledTaskManager.interval.custom');
    }

    /**
     * Absolute rendering of a moment, in the application timezone and the current language.
     */
    public static function absolute(?Carbon $date): ?string
    {
        return $date === null
            ? null
            : static::localize($date->copy()->setTimezone(static::applicationTimezone()))
                ->isoFormat(static::DATE_FORMAT);
    }

    /**
     * Relative rendering: "in 12 days", "3 hours ago". Carbon floors to a single unit, so a task
     * 12.65 days out would read as "1 week"; capping the largest unit at days keeps that honest
     * and matches what `scheduler.php list` prints on 3.5.
     */
    public static function relative(?Carbon $date): ?string
    {
        if (!$date) {
            return null;
        }

        $now = Carbon::now(static::applicationTimezone());

        // Without this the units come back in English ("in 13 hours") while the surrounding
        // sentence is translated, which reads worse than either language on its own.
        $magnitude = static::localize($date->copy())->diffForHumans($now, [
            'syntax' => CarbonInterface::DIFF_ABSOLUTE,
            'skip' => ['week', 'month', 'year'],
        ]);

        return $date->greaterThan($now)
            ? __('plugins.generic.scheduledTaskManager.relative.future', ['time' => $magnitude])
            : __('plugins.generic.scheduledTaskManager.relative.past', ['time' => $magnitude]);
    }

    /**
     * Render a moment as the {at, label, relative} triple the API returns everywhere.
     */
    public static function moment(?Carbon $date): ?array
    {
        if (!$date) {
            return null;
        }

        $date = $date->copy()->setTimezone(static::applicationTimezone());

        return [
            'at' => $date->toIso8601String(),
            'timestamp' => $date->getTimestamp(),
            'label' => static::absolute($date),
            'relative' => static::relative($date),
        ];
    }

    /**
     * Put a Carbon instance into the current interface language. PKP's locale codes are close
     * enough to Carbon's to hand over directly; an unknown one falls back and a malformed one
     * throws, so the worst outcome is English rather than a broken page.
     */
    private static function localize(Carbon $date): Carbon
    {
        try {
            return $date->locale(Locale::getLocale());
        } catch (Throwable $exception) {
            return $date;
        }
    }

    /**
     * The step of a cron field that covers its whole range: `*` is 1, `*&#47;5` is 5, anything
     * else is null (the field pins specific values rather than repeating).
     */
    private static function stepOf(string $field): ?int
    {
        if ($field === '*') {
            return 1;
        }

        if (preg_match('#^\*/(\d+)$#', $field, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }
}
