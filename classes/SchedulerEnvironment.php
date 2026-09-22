<?php

/**
 * @file classes/SchedulerEnvironment.php
 *
 * Copyright (c) 2026 Touhidur Rahman
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class SchedulerEnvironment
 *
 * @brief Reports what can be known for certain about how this installation runs its scheduler:
 *        whether the web based task runner exists, is switched on, and how much slack a task
 *        should be given before it counts as late.
 *
 * Says nothing about cron on purpose. A crontab entry is not observable from a web request, and
 * an earlier version that inferred it from console executions announced cron on a machine that
 * had none.
 */

namespace APP\plugins\generic\scheduledTaskManager\classes;

use Carbon\Carbon;
use PKP\config\Config;
use PKP\core\Core;
use PKP\plugins\Plugin;
use PKP\scheduledTask\PKPScheduler;
use Throwable;

class SchedulerEnvironment
{
    /** @param array<string, array{at: int, origin: string}> $history */
    public function __construct(private array $history)
    {
    }

    public static function for(Plugin $plugin): static
    {
        return new static(ExecutionHistory::all($plugin));
    }

    /**
     * Does this release still have a web based task runner? A candidate for removal on main, so an
     * installation that never had one is told that rather than shown an "off" it cannot turn on.
     */
    public function webRunnerAvailable(): bool
    {
        return class_exists(\PKP\scheduledTask\ScheduleTaskRunner::class)
            && method_exists(PKPScheduler::class, 'runWebBasedScheduleTaskRunner');
    }

    /**
     * Is the web based task runner switched on in config.inc.php?
     */
    public function webRunnerEnabled(): bool
    {
        return $this->webRunnerAvailable() && (bool) Config::getVar('schedule', 'task_runner', true);
    }

    /**
     * How often the web runner is allowed to fire, in seconds.
     */
    public function webRunnerInterval(): int
    {
        return (int) Config::getVar('schedule', 'task_runner_interval', 60);
    }

    /**
     * How long a task may sit past a due boundary before the page calls it overdue.
     *
     * The web runner only fires when a request arrives, so it needs a couple of its own intervals
     * to have had a fair chance; with cron alone a boundary is only ever as late as the next
     * minute. Deliberately not configurable -- a knob here is support burden with no upside.
     */
    public function overdueGrace(): int
    {
        return $this->webRunnerEnabled()
            ? max(2 * $this->webRunnerInterval(), 300)
            : 900;
    }

    /**
     * The crontab line this installation would need, path filled in, so it can be copied as it
     * stands. The interpreter stays a bare `php`: this request's binary is the web server's,
     * which is frequently not the one cron would use.
     */
    public function cronCommand(): string
    {
        return '* * * * * cd ' . Core::getBaseDir() . ' && php lib/pkp/tools/scheduler.php run';
    }

    /**
     * What is known about the web based task runner.
     *
     * @return array{state: string, interval: int, lastRun: ?array}
     */
    public function webRunner(): array
    {
        return [
            'state' => match (true) {
                !$this->webRunnerAvailable() => 'unavailable',
                $this->webRunnerEnabled() => 'on',
                default => 'off',
            },
            'interval' => $this->webRunnerInterval(),
            'lastRun' => $this->moment(ExecutionHistory::ORIGIN_WEB),
        ];
    }


    private function latest(string $origin): ?int
    {
        return ExecutionHistory::latestByOrigin($this->history)[$origin] ?? null;
    }

    private function moment(string $origin): ?array
    {
        $timestamp = $this->latest($origin);

        try {
            return $timestamp === null
                ? null
                : ScheduleDescriber::moment(Carbon::createFromTimestamp($timestamp));
        } catch (Throwable $exception) {
            return null;
        }
    }
}
