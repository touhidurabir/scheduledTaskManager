<?php

/**
 * @file classes/TaskRunner.php
 *
 * Copyright (c) 2026 Touhidur Rahman
 * Distributed under The MIT License. For full terms see the file LICENSE.
 *
 * @class TaskRunner
 *
 * @brief Runs a single scheduled task on demand.
 *
 * Event::run() honours the withoutOverlapping() mutex but not the event's own when()/skip()
 * conditions, so those are checked first: running a filtered ProcessQueueJobs would process the
 * queue behind the installation's back.
 */

namespace APP\plugins\generic\scheduledTaskManager\classes;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Contracts\Events\Dispatcher;
use PKP\core\PKPContainer;
use Throwable;

class TaskRunner
{
    public const RESULT_COMPLETED = 'completed';
    public const RESULT_FAILED = 'failed';
    public const RESULT_ERROR = 'error';

    public function __construct(private TaskCollector $collector)
    {
    }

    /**
     * Run one task synchronously.
     *
     * @return array{ok: bool, status: string, message: string, lastRun: ?array, logFile: ?string}
     */
    public function run(string $identity): array
    {
        $event = $this->collector->event($identity);

        if (!$event) {
            return $this->refuse(__('plugins.generic.scheduledTaskManager.run.notFound'));
        }

        if ($blocked = $this->collector->blockedReason($identity)) {
            return $this->refuse($blocked['message'], $blocked['reason']);
        }

        // A manual run is a deliberate, authenticated action; some tasks download or import
        // large datasets and would otherwise be cut off by the request time limit. The client
        // is warned about this before confirming.
        @set_time_limit(0);
        @ignore_user_abort(true);

        $before = $this->newestLogTimestamp($identity);

        // Anything the recorder hears during this call is a manual run. Without saying so it
        // would be filed as a web task runner execution -- this request is a web request -- and
        // the page would then claim a runner that may not even be switched on.
        return ExecutionHistory::asManualRun(fn () => $this->execute($identity, $event, $before));
    }

    /**
     * Dispatch the scheduler's lifecycle events around the actual execution.
     *
     * @return array{ok: bool, status: string, message: string, lastRun: ?array, logFile: ?string}
     */
    private function execute(string $identity, ScheduledEvent $event, ?int $before): array
    {
        $container = PKPContainer::getInstance();
        $dispatcher = $container->get(Dispatcher::class); /** @var Dispatcher $dispatcher */
        $startedAt = microtime(true);

        // Event::run() does not announce anything by itself -- ScheduleTaskRunner and Laravel's
        // ScheduleRunCommand are what wrap it in these events. Emitting them here keeps a manual
        // run indistinguishable from a scheduled one for every listener, this plugin's own
        // execution recorder included.
        $dispatcher->dispatch(new ScheduledTaskStarting($event));

        try {
            // Only CallbackEvent hands back what the task returned; Event::run() is declared void,
            // so for any other kind of event -- a plugin scheduling a console command, say -- the
            // most that can honestly be said is that it finished without throwing.
            $result = $event instanceof CallbackEvent
                ? $event->run($container)
                : $this->runWithoutResult($event, $container);
        } catch (Throwable $exception) {
            $dispatcher->dispatch(new ScheduledTaskFailed($event, $exception));

            return [
                'ok' => false,
                'status' => static::RESULT_ERROR,
                'message' => __('plugins.generic.scheduledTaskManager.run.error', [
                    'message' => $exception->getMessage(),
                ]),
            ] + $this->outcome($identity, $before);
        }

        $dispatcher->dispatch(new ScheduledTaskFinished($event, round(microtime(true) - $startedAt, 2)));

        // The scheduled callback is `fn () => $task->execute()`, and CallbackEvent keeps that
        // return value, so a task that reports its own failure is distinguishable from one that
        // threw and from one that succeeded. null means the event kind cannot report either way.
        $succeeded = $result !== false;

        return [
            'ok' => $succeeded,
            'status' => $succeeded ? static::RESULT_COMPLETED : static::RESULT_FAILED,
            'message' => $succeeded
                ? __('plugins.generic.scheduledTaskManager.run.completed')
                : __('plugins.generic.scheduledTaskManager.run.failed'),
        ] + $this->outcome($identity, $before);
    }

    /**
     * Run an event that reports nothing back, and say so.
     */
    private function runWithoutResult(ScheduledEvent $event, PKPContainer $container): ?bool
    {
        $event->run($container);

        return null;
    }

    /**
     * A refusal that never touched the task.
     */
    private function refuse(string $message, string $status = self::RESULT_ERROR): array
    {
        return [
            'ok' => false,
            'status' => $status,
            'message' => $message,
            'lastRun' => null,
            'logFile' => null,
        ];
    }

    /**
     * What the run left behind: the refreshed last-run moment and the log file it produced.
     */
    private function outcome(string $identity, ?int $before): array
    {
        // A fresh repository: the one held by the collector cached its directory scan before the
        // task wrote anything.
        $logs = new LogRepository();
        $newest = $logs->filesFor($identity, new LogQuery(limit: 1))['files'][0] ?? null;

        return [
            'lastRun' => ScheduleDescriber::moment($logs->lastRunFor($identity)),
            'logFile' => $newest && $newest['modified'] >= ($before ?? 0) ? $newest['name'] : null,
        ];
    }

    /**
     * Timestamp of the newest existing log file for a task, before the run.
     */
    private function newestLogTimestamp(string $identity): ?int
    {
        $newest = (new LogRepository())->filesFor($identity, new LogQuery(limit: 1))['files'][0] ?? null;

        return $newest['modified'] ?? null;
    }
}
