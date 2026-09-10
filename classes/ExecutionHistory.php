<?php

/**
 * @file classes/ExecutionHistory.php
 *
 * Copyright (c) 2026 Touhidur Rahman
 * Distributed under The MIT License. For full terms see the file LICENSE.
 *
 * @class ExecutionHistory
 *
 * @brief Records each task's most recent execution, by listening to the scheduler's own
 *        lifecycle events -- which the web runner and cron both dispatch.
 *
 * ScheduledTaskSkipped is ignored on purpose: a disabled ProcessQueueJobs would otherwise write
 * to the database every minute, and a skip is not an execution.
 */

namespace APP\plugins\generic\scheduledTaskManager\classes;

use APP\core\Application;
use Carbon\Carbon;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Support\Facades\Event;
use PKP\core\PKPContainer;
use PKP\plugins\Plugin;
use Throwable;

class ExecutionHistory
{
    /** Plugin setting the per-task history is stored under. */
    public const SETTING = 'executionHistory';

    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    /** Run by a console process: `scheduler.php run` from crontab, or a hand-run CLI command. */
    public const ORIGIN_CLI = 'cli';

    /** Run by the web based task runner at the end of a web request. */
    public const ORIGIN_WEB = 'web';

    /** Run by an administrator from this plugin's own page. */
    public const ORIGIN_MANUAL = 'manual';

    /** Recorded before origins were tracked, so it cannot be attributed either way. */
    public const ORIGIN_UNKNOWN = 'unknown';

    /**
     * Set while this plugin runs a task on demand: that happens inside a web request and would
     * otherwise be filed as the web task runner's work.
     */
    private static bool $manual = false;

    /**
     * Attach the recorder to the scheduler's lifecycle events.
     *
     * Attaching is free, so it happens on every request; whether anything is written is decided
     * per execution instead, which keeps the check off the request path.
     *
     * @param ?callable(): bool $shouldRecord Consulted per execution; null records everything.
     */
    public static function listen(Plugin $plugin, ?callable $shouldRecord = null): void
    {
        Event::listen(
            ScheduledTaskFinished::class,
            function (ScheduledTaskFinished $event) use ($plugin, $shouldRecord) {
                if ($shouldRecord === null || $shouldRecord()) {
                    static::record($plugin, $event->task, static::STATUS_COMPLETED, $event->runtime);
                }
            }
        );

        Event::listen(
            ScheduledTaskFailed::class,
            function (ScheduledTaskFailed $event) use ($plugin, $shouldRecord) {
                if ($shouldRecord === null || $shouldRecord()) {
                    static::record(
                        $plugin,
                        $event->task,
                        static::STATUS_FAILED,
                        null,
                        $event->exception->getMessage()
                    );
                }
            }
        );
    }

    /**
     * Run the given callback with executions attributed to a manual run.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function asManualRun(callable $callback)
    {
        $previous = static::$manual;
        static::$manual = true;

        try {
            return $callback();
        } finally {
            static::$manual = $previous;
        }
    }

    /**
     * The whole history, keyed by task name.
     *
     * @return array<string, array{at: int, status: string, runtime: ?float, message: ?string, origin: string}>
     */
    public static function all(Plugin $plugin): array
    {
        try {
            $stored = $plugin->getSetting(Application::SITE_CONTEXT_ID, static::SETTING);
        } catch (Throwable $exception) {
            return [];
        }

        if (is_string($stored)) {
            $stored = json_decode($stored, true);
        }

        if (!is_array($stored)) {
            return [];
        }

        $history = [];
        foreach ($stored as $taskName => $record) {
            $record = (array) $record;

            if (!isset($record['at'])) {
                continue;
            }

            $history[(string) $taskName] = [
                'at' => (int) $record['at'],
                'status' => (string) ($record['status'] ?? static::STATUS_COMPLETED),
                'runtime' => isset($record['runtime']) ? (float) $record['runtime'] : null,
                'message' => isset($record['message']) ? (string) $record['message'] : null,
                'origin' => (string) ($record['origin'] ?? static::ORIGIN_UNKNOWN),
            ];
        }

        return $history;
    }

    /**
     * The most recent execution recorded for each origin. Records written before origins were
     * tracked count for nothing: guessing at them is what this is trying to avoid.
     *
     * @param array<string, array{at: int, origin: string}> $history
     * @return array<string, ?int> Keyed by origin, timestamp or null.
     */
    public static function latestByOrigin(array $history): array
    {
        $latest = [
            static::ORIGIN_CLI => null,
            static::ORIGIN_WEB => null,
            static::ORIGIN_MANUAL => null,
        ];

        foreach ($history as $record) {
            $origin = $record['origin'] ?? static::ORIGIN_UNKNOWN;

            if (!array_key_exists($origin, $latest)) {
                continue;
            }

            $latest[$origin] = max($latest[$origin] ?? 0, (int) $record['at']);
        }

        return $latest;
    }

    /**
     * Store one execution. Runs inside the scheduler, so it must never throw: a bookkeeping
     * failure must not take down the task run that produced it.
     */
    protected static function record(
        Plugin $plugin,
        ScheduledEvent $event,
        string $status,
        ?float $runtime = null,
        ?string $message = null
    ): void {
        try {
            $taskName = TaskCollector::identify($event);

            if ($taskName === null) {
                return;
            }

            // Re-read immediately before writing so a concurrent recorder's entries for other
            // tasks survive, the same merge-on-write shape core uses for saveLastRunTimes().
            $history = static::all($plugin);

            $origin = static::origin();

            $history[$taskName] = [
                'at' => Carbon::now()->getTimestamp(),
                'status' => $status,
                'runtime' => $runtime,
                // Bounded: an exception message goes into a settings row, not a log file.
                'message' => $message === null ? null : mb_substr($message, 0, 500),
                'origin' => $origin,
            ];

            $plugin->updateSetting(Application::SITE_CONTEXT_ID, static::SETTING, $history, 'object');
        } catch (Throwable $exception) {
            error_log('ScheduledTaskManager could not record a task execution: ' . $exception->getMessage());
        }
    }

    /**
     * What drove the execution being recorded.
     */
    protected static function origin(): string
    {
        if (static::$manual) {
            return static::ORIGIN_MANUAL;
        }

        try {
            return PKPContainer::getInstance()->runningInConsole()
                ? static::ORIGIN_CLI
                : static::ORIGIN_WEB;
        } catch (Throwable $exception) {
            return static::ORIGIN_UNKNOWN;
        }
    }
}
