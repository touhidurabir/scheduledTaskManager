<?php

/**
 * @file classes/ExecutionHistory.php
 *
 * Copyright (c) 2026 Touhidur Rahman
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class ExecutionHistory
 *
 * @brief Records each task's most recent execution, by listening to the scheduler's own
 *        lifecycle events -- which the web runner, cron and this plugin's Run now all dispatch.
 *
 * The outcome is read from the event's exit code rather than from which event fired. Finished
 * only means Event::run() returned: it fires as well for a task that reported its own failure by
 * returning false -- the way core's tasks that can fail report it -- and for a run the overlap
 * lock kept from starting at all. Failed fires only when an exception escapes the task.
 *
 * ScheduledTaskSkipped is ignored on purpose: a disabled ProcessQueueJobs would otherwise write
 * to the database every minute, and a skip is not an execution.
 */

namespace APP\plugins\generic\scheduledTaskManager\classes;

use APP\core\Application;
use Carbon\Carbon;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Support\Facades\Event;
use PKP\core\PKPContainer;
use PKP\plugins\Plugin;
use Throwable;

class ExecutionHistory
{
    /** Plugin setting the per-task history is stored under. */
    public const SETTING = 'executionHistory';

    /**
     * Plugin setting holding the detail of each task whose latest run failed -- the reason included,
     * which the history record does not repeat. Kept apart from the history, which is rewritten on
     * every run of every task, and cleared by the task's next
     * success: a plugin's settings are loaded together on every request, so only failures that
     * are still current are worth their bytes.
     */
    public const FAILURE_SETTING = 'lastFailures';

    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    /** Handed to a background process, which reports its outcome to nothing this side can hear. */
    public const STATUS_BACKGROUND = 'background';

    /** An exception escaped the task. */
    public const FAILURE_EXCEPTION = 'exception';

    /** The task returned false: it caught its own problem, and said why in its log if anywhere. */
    public const FAILURE_REPORTED = 'reported';

    /** Run by a console process: `scheduler.php run` from crontab, or a hand-run CLI command. */
    public const ORIGIN_CLI = 'cli';

    /** Run by the web based task runner at the end of a web request. */
    public const ORIGIN_WEB = 'web';

    /** Run by an administrator from this plugin's own page. */
    public const ORIGIN_MANUAL = 'manual';

    /** Recorded before origins were tracked, so it cannot be attributed either way. */
    public const ORIGIN_UNKNOWN = 'unknown';

    /** Bounds on what one failure may store. */
    private const DETAIL_MESSAGE_LIMIT = 4000;

    private const TRACE_LIMIT = 12000;

    /** How many causes (getPrevious()) are kept, without their own traces. */
    private const CAUSE_LIMIT = 3;

    private const CAUSE_MESSAGE_LIMIT = 1000;

    /** The last this many log entries of the failed run. */
    private const ENTRY_LIMIT = 30;

    private const ENTRY_LENGTH_LIMIT = 500;

    /**
     * Set while this plugin runs a task on demand: that happens inside a web request and would
     * otherwise be filed as the web task runner's work.
     */
    private static bool $manual = false;

    /**
     * Runs this process has seen start, keyed by the event object's id: when each started,
     * whether the overlap lock was going to keep it from running, and whether it has already
     * reported finishing.
     *
     * @var array<int, array{startedAt: float, lockedOut: bool, finished: bool}>
     */
    private static array $runs = [];

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
            ScheduledTaskStarting::class,
            fn (ScheduledTaskStarting $event) => static::starting($event->task)
        );

        Event::listen(
            ScheduledTaskFinished::class,
            fn (ScheduledTaskFinished $event) => static::finished($plugin, $shouldRecord, $event)
        );

        Event::listen(
            ScheduledTaskFailed::class,
            fn (ScheduledTaskFailed $event) => static::failed($plugin, $shouldRecord, $event)
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
     * @return array<string, array{at: int, status: string, runtime: ?float, origin: string}>
     */
    public static function all(Plugin $plugin): array
    {
        $history = [];
        foreach (static::stored($plugin, static::SETTING) as $taskName => $record) {
            $record = (array) $record;

            if (!isset($record['at'])) {
                continue;
            }

            $history[(string) $taskName] = [
                'at' => (int) $record['at'],
                'status' => (string) ($record['status'] ?? static::STATUS_COMPLETED),
                'runtime' => isset($record['runtime']) ? (float) $record['runtime'] : null,
                'origin' => (string) ($record['origin'] ?? static::ORIGIN_UNKNOWN),
            ];
        }

        return $history;
    }

    /**
     * The detail of a task's failed latest run, or null when it has none.
     *
     * @return ?array{at: int, kind: string, origin: string, runtime: ?float, message: ?string, exception: ?array, log: ?array}
     */
    public static function failure(Plugin $plugin, string $taskName): ?array
    {
        $failure = static::stored($plugin, static::FAILURE_SETTING)[$taskName] ?? null;

        return is_array($failure) && isset($failure['at'], $failure['kind']) ? $failure : null;
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
     * Note a run beginning. Memory only -- no query, and nothing to gate.
     */
    protected static function starting(ScheduledEvent $event): void
    {
        static::$runs[spl_object_id($event)] = [
            'startedAt' => microtime(true),
            'lockedOut' => static::lockedOut($event),
            'finished' => false,
        ];
    }

    /**
     * Event::run() returned. Whether anything ran, and how it went, is in the exit code it left:
     * 0 for success, 1 for a task that returned false, and still null when the overlap lock sent
     * it back before starting.
     */
    protected static function finished(Plugin $plugin, ?callable $shouldRecord, ScheduledTaskFinished $event): void
    {
        $task = $event->task;
        $id = spl_object_id($task);
        $run = static::$runs[$id] ?? ['startedAt' => null, 'lockedOut' => false];

        // Whatever Failed arrives after this for the same run is not the task's: Laravel's
        // schedule:run raises its own "exit code [1]" exception for a task that returned false,
        // and a failing listener further down this Finished is reported the same way.
        static::$runs[$id] = ['finished' => true] + $run;

        if (!$task->runInBackground && ($task->exitCode === null || $run['lockedOut'])) {
            return;
        }

        if ($shouldRecord !== null && !$shouldRecord()) {
            return;
        }

        match (true) {
            $task->runInBackground => static::record($plugin, $task, static::STATUS_BACKGROUND, null),
            $task->exitCode === 0 => static::record($plugin, $task, static::STATUS_COMPLETED, $event->runtime),
            default => static::record($plugin, $task, static::STATUS_FAILED, $event->runtime, null, $run['startedAt']),
        };
    }

    /**
     * An exception escaped the task -- unless this run already finished, see finished().
     */
    protected static function failed(Plugin $plugin, ?callable $shouldRecord, ScheduledTaskFailed $event): void
    {
        $id = spl_object_id($event->task);
        $run = static::$runs[$id] ?? null;
        unset(static::$runs[$id]);

        if ($run['finished'] ?? false) {
            return;
        }

        if ($shouldRecord !== null && !$shouldRecord()) {
            return;
        }

        // ScheduledTaskFailed carries no runtime of its own.
        $startedAt = $run['startedAt'] ?? null;
        $runtime = $startedAt === null ? null : round(microtime(true) - $startedAt, 2);

        static::record($plugin, $event->task, static::STATUS_FAILED, $runtime, $event->exception, $startedAt);
    }

    /**
     * Whether Event::run() is about to return without running anything.
     *
     * The exit code says so by itself on an event's first run in a process: it stays null. A
     * second run of the same event object -- Run now followed by the web runner in one request --
     * would still carry the first run's code, so only then is the lock itself consulted.
     */
    private static function lockedOut(ScheduledEvent $event): bool
    {
        if ($event->exitCode === null || !$event->withoutOverlapping) {
            return false;
        }

        try {
            return $event->mutex->exists($event);
        } catch (Throwable $exception) {
            return false;
        }
    }

    /**
     * Store one execution. Runs inside the scheduler, so it must never throw: a bookkeeping
     * failure must not take down the task run that produced it.
     */
    protected static function record(
        Plugin $plugin,
        ScheduledEvent $event,
        string $status,
        ?float $runtime,
        ?Throwable $exception = null,
        ?float $startedAt = null
    ): void {
        try {
            $taskName = TaskCollector::identify($event);

            if ($taskName === null) {
                return;
            }

            $at = Carbon::now()->getTimestamp();
            $origin = static::origin();

            $failure = $status === static::STATUS_FAILED
                ? static::describeFailure($taskName, $exception, $startedAt)
                : null;

            // Re-read immediately before writing so a concurrent recorder's entries for other
            // tasks survive, the same merge-on-write shape core uses for saveLastRunTimes().
            $history = static::all($plugin);

            $history[$taskName] = [
                'at' => $at,
                'status' => $status,
                'runtime' => $runtime,
                'origin' => $origin,
            ];

            $plugin->updateSetting(Application::SITE_CONTEXT_ID, static::SETTING, $history, 'object');

            // A background hand-off says nothing either way, so it leaves a failure standing.
            if ($status !== static::STATUS_BACKGROUND) {
                static::keepFailure($plugin, $taskName, $failure === null ? null : [
                    'at' => $at,
                    'origin' => $origin,
                    'runtime' => $runtime,
                ] + $failure);
            }
        } catch (Throwable $exception) {
            error_log('ScheduledTaskManager could not record a task execution: ' . $exception->getMessage());
        }
    }

    /**
     * What is known about why a run failed.
     *
     * A task that threw carries its exception. One that returned false carries nothing, and the
     * only account of it is what the task wrote to its own log during the run -- found by
     * reading the directory, which is why this happens only when a run has failed.
     */
    private static function describeFailure(string $taskName, ?Throwable $exception, ?float $startedAt): array
    {
        $log = static::runLog($taskName, $startedAt, $exception === null);

        if ($exception !== null) {
            return [
                'kind' => static::FAILURE_EXCEPTION,
                'message' => static::cut($exception->getMessage(), static::DETAIL_MESSAGE_LIMIT),
                'exception' => static::describeException($exception),
                'log' => $log,
            ];
        }

        $entries = $log['entries'] ?? [];
        $last = $entries ? end($entries) : null;

        return [
            'kind' => static::FAILURE_REPORTED,
            // The task's last word before giving up, less its stamp and its (translated) label.
            'message' => $last === null
                ? null
                : preg_replace('/^\[[^\]\n]{1,40}\] /', '', LogRepository::textOf($last)),
            'exception' => null,
            'log' => $log,
        ];
    }

    /**
     * The failed run's own log file and the entries the task wrote in it, when there is one: a
     * task that is not a ScheduledTask writes none.
     */
    private static function runLog(string $taskName, ?float $startedAt, bool $returned): ?array
    {
        try {
            $logs = new LogRepository();
            $newest = $logs->filesFor($taskName, new LogQuery(limit: 1))['files'][0] ?? null;

            // Last written before this run began: it belongs to an earlier one.
            if ($newest === null || ($startedAt !== null && $newest['modified'] < (int) floor($startedAt))) {
                return null;
            }

            $log = $logs->runEntries($newest['name'], $returned, static::ENTRY_LIMIT);

            if ($log === null) {
                return null;
            }

            $log['entries'] = array_map(
                fn (string $entry) => static::cut($entry, static::ENTRY_LENGTH_LIMIT),
                $log['entries']
            );

            return $log;
        } catch (Throwable $exception) {
            return null;
        }
    }

    /**
     * An exception, bounded. getTraceAsString() honours zend.exception_ignore_args and PHP 8.2's
     * #[\SensitiveParameter], so arguments appear only where the installation lets them.
     */
    private static function describeException(Throwable $exception): array
    {
        $causes = [];
        for ($cause = $exception->getPrevious(); $cause && count($causes) < static::CAUSE_LIMIT; $cause = $cause->getPrevious()) {
            $causes[] = [
                'class' => $cause::class,
                'message' => static::cut($cause->getMessage(), static::CAUSE_MESSAGE_LIMIT),
                'file' => $cause->getFile(),
                'line' => $cause->getLine(),
            ];
        }

        $trace = $exception->getTraceAsString();

        return [
            'class' => $exception::class,
            'message' => static::cut($exception->getMessage(), static::DETAIL_MESSAGE_LIMIT),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => static::cut($trace, static::TRACE_LIMIT),
            'traceTruncated' => mb_strlen($trace) > static::TRACE_LIMIT,
            'previous' => $causes,
        ];
    }

    /**
     * Store a task's failure detail, or clear it after a success. Writes only when something
     * changes, so a task that keeps succeeding costs nothing here.
     */
    private static function keepFailure(Plugin $plugin, string $taskName, ?array $failure): void
    {
        $failures = static::stored($plugin, static::FAILURE_SETTING);

        if ($failure === null && !array_key_exists($taskName, $failures)) {
            return;
        }

        if ($failure === null) {
            unset($failures[$taskName]);
        } else {
            $failures[$taskName] = $failure;
        }

        $plugin->updateSetting(Application::SITE_CONTEXT_ID, static::FAILURE_SETTING, $failures, 'object');
    }

    /**
     * A stored setting as an array, whatever shape it was read back in.
     */
    private static function stored(Plugin $plugin, string $setting): array
    {
        try {
            $stored = $plugin->getSetting(Application::SITE_CONTEXT_ID, $setting);
        } catch (Throwable $exception) {
            return [];
        }

        if (is_string($stored)) {
            $stored = json_decode($stored, true);
        }

        return is_array($stored) ? $stored : [];
    }

    private static function cut(?string $text, int $limit): ?string
    {
        return $text === null ? null : mb_substr($text, 0, $limit);
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
