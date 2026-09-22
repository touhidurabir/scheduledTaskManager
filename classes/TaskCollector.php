<?php

/**
 * @file classes/TaskCollector.php
 *
 * Copyright (c) 2026 Touhidur Rahman
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class TaskCollector
 *
 * @brief Discovers every registered scheduled task and describes it for display.
 *
 * Disk-loads plugins the way the CLI does, because a site-level web request registers only
 * site-enabled ones and would miss every journal-level plugin's tasks. Idempotent: addSchedule()
 * is keyed by task name and PluginRegistry refuses to re-register a loaded plugin.
 */

namespace APP\plugins\generic\scheduledTaskManager\classes;

use APP\scheduler\Scheduler;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use PKP\core\PKPContainer;
use PKP\plugins\interfaces\HasTaskScheduler;
use PKP\plugins\Plugin;
use PKP\plugins\PluginRegistry;
use PKP\scheduledTask\ScheduledTask;
use PKP\scheduledTask\ScheduledTaskHelper;
use ReflectionClass;
use Throwable;

class TaskCollector
{
    public const BLOCKED_FILTERED = 'filtered';
    public const BLOCKED_RUNNING = 'running';
    public const BLOCKED_UNNAMED = 'unnamed';

    /** @var array<string, ScheduledEvent> Discovered events, keyed by task identity. */
    private array $events = [];

    /** @var array<string, string> Identities that cannot be acted on, and why. */
    private array $unrunnable = [];

    /** @var ?array<string, array> Execution history, read once and shared with the environment. */
    private ?array $history = null;

    private ?SchedulerEnvironment $environment = null;

    private bool $collected = false;

    public function __construct(
        private Plugin $plugin,
        private LogRepository $logs = new LogRepository()
    ) {
    }

    /**
     * The stable identity of a scheduled event -- in practice its task class, since that is what
     * core and plugins name events after. An unnamed event falls back to Laravel's 'Callback',
     * which cannot identify anything: those rows are shown but cannot be run.
     */
    public static function identify(ScheduledEvent $event): ?string
    {
        $name = $event->command ?? $event->getSummaryForDisplay();
        $name = is_string($name) ? trim($name) : '';

        return $name === '' ? null : $name;
    }

    /**
     * @return array<string, ScheduledEvent>
     */
    public function events(): array
    {
        $this->collect();

        return $this->events;
    }

    public function event(string $identity): ?ScheduledEvent
    {
        return $this->events()[$identity] ?? null;
    }

    /**
     * Why the given task cannot be run manually, or null when it can.
     */
    public function blockedReason(string $identity): ?array
    {
        $this->collect();

        if (isset($this->unrunnable[$identity])) {
            return [
                'reason' => $this->unrunnable[$identity],
                'message' => __('plugins.generic.scheduledTaskManager.blocked.unnamed'),
            ];
        }

        $event = $this->event($identity);

        if (!$event) {
            return null;
        }

        if (!$this->filtersPass($event)) {
            return [
                'reason' => static::BLOCKED_FILTERED,
                'message' => __('plugins.generic.scheduledTaskManager.blocked.filtered'),
            ];
        }

        if ($this->hasMutex($event)) {
            return [
                'reason' => static::BLOCKED_RUNNING,
                'message' => __('plugins.generic.scheduledTaskManager.blocked.running'),
            ];
        }

        return null;
    }

    /**
     * One row per registered task.
     */
    public function rows(): array
    {
        $this->collect();

        $history = $this->history();
        $checkpoints = static::runnerCheckpoints();
        $rows = [];

        foreach ($this->events as $identity => $event) {
            $rows[] = $this->describe($identity, $event, $history, $checkpoints);
        }

        // Soonest due first; tasks with no resolvable due date sink to the bottom.
        usort($rows, fn (array $a, array $b) => ($a['nextRun']['timestamp'] ?? PHP_INT_MAX)
            <=> ($b['nextRun']['timestamp'] ?? PHP_INT_MAX));

        return $rows;
    }

    /**
     * The summary shown above the table. Two things are deliberately absent: a "last execution"
     * across all tasks, whose only cheap source is the newest log file and which cannot say what
     * ran it, and any claim about cron, which a web request cannot observe.
     */
    public function status(): array
    {
        $this->collect();

        $environment = $this->environment();

        return [
            'timezone' => ScheduleDescriber::applicationTimezone(),
            'timezoneConfigKey' => 'general.time_zone',
            'webRunner' => $environment->webRunner(),
            'cronCommand' => $environment->cronCommand(),
            'hasRunnerCheckpoints' => static::supportsRunnerCheckpoints(),
            'taskCount' => count($this->events),
            'logDirectory' => $this->logs->directory(),
            'logDirectoryExists' => $this->logs->exists(),
            'logFileCount' => $this->logs->scan()['total'],
        ];
    }

    /**
     * Whether this installation records the web task runner's per-task checkpoints. Added by
     * pkp/pkp-lib#13041 on 3.5 and absent on main, so it is detected rather than assumed.
     */
    public static function supportsRunnerCheckpoints(): bool
    {
        return method_exists(ScheduledTaskHelper::class, 'getLastRunTimes');
    }

    /**
     * The web task runner's per-task checkpoints, keyed by task name. Explicitly *not* a last-run
     * record: on first sighting the runner seeds it with a boundary for a task that never ran.
     */
    public static function runnerCheckpoints(): array
    {
        if (!static::supportsRunnerCheckpoints()) {
            return [];
        }

        try {
            return ScheduledTaskHelper::getLastRunTimes();
        } catch (Throwable $exception) {
            return [];
        }
    }

    /**
     * Build the display record for one task.
     */
    private function describe(string $identity, ScheduledEvent $event, array $history, array $checkpoints): array
    {
        $expression = $event->getExpression();
        $blocked = $this->blockedReason($identity);

        $lastRun = $this->lastRun($identity, $history[$identity] ?? null);

        $checkpoint = $checkpoints[$identity] ?? null;

        return [
            'name' => $identity,
            'displayName' => $this->displayNameOf($identity),
            'expression' => $expression,
            'intervalLabel' => ScheduleDescriber::intervalLabel($expression),
            'timezone' => ScheduleDescriber::eventTimezone($event),
            'nextRun' => ScheduleDescriber::moment(ScheduleDescriber::nextRun($event)),
            'lastRun' => $lastRun,
            'overdue' => $this->overdue($event, $lastRun, $blocked),
            'runnerCheckpoint' => $checkpoint
                ? ScheduleDescriber::moment(Carbon::createFromTimestamp((int) $checkpoint))
                : null,
            'logCount' => $this->logs->countFor($identity),
            'canRun' => $blocked === null,
            'blocked' => $blocked,
        ];
    }

    /**
     * When the task last ran: whichever of its log files and its recorded runs is more recent.
     *
     * Both describe the same event; what differs is reach -- logs predate this plugin, records
     * survive the logs being deleted. The runner's checkpoint is deliberately not a third
     * candidate: seeded to a boundary without running, it would report a run that never happened
     * and suppress the overdue flag for exactly that task.
     */
    private function lastRun(string $identity, ?array $record): ?array
    {
        $logged = $this->logs->newestRunFor($identity);

        if ($record && (!$logged || $record['at'] >= $logged['finished'])) {
            return ScheduleDescriber::moment(Carbon::createFromTimestamp($record['at'])) + [
                'source' => 'history',
                'status' => $record['status'],
                'runtime' => $record['runtime'],
                // What ran it, when that is known: a record older than origin tracking is not
                // attributed to anything rather than guessed at.
                'origin' => in_array($record['origin'], [
                    ExecutionHistory::ORIGIN_CLI,
                    ExecutionHistory::ORIGIN_WEB,
                    ExecutionHistory::ORIGIN_MANUAL,
                ], true) ? $record['origin'] : null,
            ];
        }

        if ($logged) {
            // A log file knows when a run started and finished, and nothing about how it went or
            // what ran it -- the start and stop entries are written the same either way.
            return ScheduleDescriber::moment(Carbon::createFromTimestamp($logged['finished'])) + [
                'source' => 'log',
                'status' => null,
                'runtime' => $logged['duration'],
                'origin' => null,
            ];
        }

        return null;
    }

    /**
     * The due boundary a task has missed, or null when it is not late.    /**
     * The due boundary a task has missed, or null when it is not late.
     *
     * Silent in the three cases where "late" is not a fault: a task that has never run (a fresh
     * install would flag every row), one switched off by configuration, and one scheduled no
     * further apart than the runner's interval, whose lateness is the runner's, not its own.
     */
    private function overdue(ScheduledEvent $event, ?array $lastRun, ?array $blocked): ?array
    {
        if ($lastRun === null || ($blocked['reason'] ?? null) === static::BLOCKED_FILTERED) {
            return null;
        }

        $previous = ScheduleDescriber::previousRun($event);
        $next = ScheduleDescriber::nextRun($event);

        if ($previous === null || $next === null) {
            return null;
        }

        // Core's own test for a frequent task (ScheduleTaskRunner::run()).
        $interval = $this->environment()->webRunnerInterval();

        if ($next->getTimestamp() - $previous->getTimestamp() <= $interval) {
            return null;
        }

        $boundary = $previous->getTimestamp();

        // The boundary has to be old enough that a run could have happened since.
        if (Carbon::now()->getTimestamp() - $boundary < $this->environment()->overdueGrace()) {
            return null;
        }

        return $lastRun['timestamp'] < $boundary
            ? ScheduleDescriber::moment($previous)
            : null;
    }

    /**
     * Execution history, read once per collector: rows() and the environment both want it.
     */
    private function history(): array
    {
        return $this->history ??= ExecutionHistory::all($this->plugin);
    }

    private function environment(): SchedulerEnvironment
    {
        return $this->environment ??= new SchedulerEnvironment($this->history());
    }

    /**
     * The task's own display name, when it can be had cheaply and safely. getName() is an instance
     * method, so the task must be constructed -- side-effect free, but signatures vary
     * (UsageStatsLoader needs an argument), so any failure degrades to the class name alone.
     */
    private function displayNameOf(string $identity): ?string
    {
        try {
            if (!class_exists($identity)) {
                return null;
            }

            $reflection = new ReflectionClass($identity);

            if (!$reflection->isSubclassOf(ScheduledTask::class) || !$reflection->isInstantiable()) {
                return null;
            }

            $required = $reflection->getConstructor()?->getNumberOfRequiredParameters() ?? 0;
            $task = $required > 0 ? $reflection->newInstance([]) : $reflection->newInstance();

            $name = $task->getName();

            return is_string($name) && $name !== '' ? $name : null;
        } catch (Throwable $exception) {
            return null;
        }
    }

    /**
     * Does this event's own conditions allow it to run right now?
     */
    public function filtersPass(ScheduledEvent $event): bool
    {
        try {
            return $event->filtersPass(PKPContainer::getInstance());
        } catch (Throwable $exception) {
            return false;
        }
    }

    /**
     * Is an overlap mutex currently held for this event?
     */
    public function hasMutex(ScheduledEvent $event): bool
    {
        try {
            return $event->withoutOverlapping && $event->mutex->exists($event);
        } catch (Throwable $exception) {
            return false;
        }
    }

    /**
     * Discover every task, attributing each to core, the application, or a plugin.
     */
    private function collect(): void
    {
        if ($this->collected) {
            return;
        }

        $this->collected = true;

        // Resolving the Schedule is what triggers ScheduleServiceProvider's deferred
        // registration of the core and application tasks.
        $schedule = app()->get(Schedule::class); /** @var Schedule $schedule */

        $this->registerPluginSchedules();
        $this->index($schedule);

    }

    /**
     * Give every plugin on disk the chance to register its tasks. Core does this under the CLI but
     * not in a web request; each is registered separately so one failure costs only its own tasks.
     */
    private function registerPluginSchedules(): void
    {
        try {
            $scheduler = app()->get(Scheduler::class); /** @var Scheduler $scheduler */
        } catch (Throwable $exception) {
            error_log('ScheduledTaskManager could not resolve the scheduler: ' . $exception->getMessage());
            return;
        }

        // enabledOnly = false takes the loadFromDisk branch, which is how every CLI tool loads
        // plugins and therefore what cron actually sees.
        try {
            $plugins = PluginRegistry::loadAllPlugins(false);
        } catch (Throwable $exception) {
            error_log('ScheduledTaskManager could not load plugins: ' . $exception->getMessage());
            return;
        }

        foreach ($plugins as $plugin) {
            if (!$plugin instanceof HasTaskScheduler) {
                continue;
            }

            try {
                $plugin->registerSchedules($scheduler);
            } catch (Throwable $exception) {
                error_log(
                    'ScheduledTaskManager could not register schedules for ' . $plugin::class
                    . ': ' . $exception->getMessage()
                );
            }
        }
    }

    /**
     * Fold every registered event into the index.
     */
    private function index(Schedule $schedule): void
    {
        $summaries = [];

        foreach ($schedule->events() as $event) {
            $identity = static::identify($event);

            if ($identity === null) {
                continue;
            }

            $summaries[$identity] = ($summaries[$identity] ?? 0) + 1;
        }

        foreach ($schedule->events() as $event) {
            $identity = static::identify($event);

            if ($identity === null || isset($this->events[$identity])) {
                continue;
            }

            $this->events[$identity] = $event;

            // An event registered without ->name() has no identity of its own: Laravel reports
            // every such event as 'Callback'. It is listed, but running one by name would be a
            // coin toss, so it is refused.
            if ($identity === 'Callback' || ($summaries[$identity] ?? 0) > 1) {
                $this->unrunnable[$identity] = static::BLOCKED_UNNAMED;
            }
        }
    }
}
