<?php

/**
 * @file classes/ScheduledTaskApiController.php
 *
 * Copyright (c) 2026 Touhidur Rahman
 * Distributed under The MIT License. For full terms see the file LICENSE.
 *
 * @class ScheduledTaskApiController
 *
 * @brief Site-wide API backing the scheduled task manager page.
 *
 * Registered through APIHandler::endpoints::plugin, so it sits at
 * BASE_URL/index.php/index/api/v1/scheduled-tasks with core's standard middleware stack.
 */

namespace APP\plugins\generic\scheduledTaskManager\classes;

use APP\core\Application;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use PKP\core\PKPApplication;
use PKP\core\PKPBaseController;
use PKP\plugins\Plugin;
use PKP\security\Role;

class ScheduledTaskApiController extends PKPBaseController
{
    public function __construct(private Plugin $plugin)
    {
    }

    /**
     * @copydoc \PKP\core\PKPBaseController::getHandlerPath()
     */
    public function getHandlerPath(): string
    {
        return 'scheduled-tasks';
    }

    /**
     * @copydoc \PKP\core\PKPBaseController::isSiteWide()
     *
     * The scheduler belongs to the installation, not to any one journal, however many contexts
     * exist -- unlike isSitePlugin(), which only decides where the plugin can be switched on.
     */
    public function isSiteWide(): bool
    {
        return true;
    }

    /**
     * @copydoc \PKP\core\PKPBaseController::getRouteGroupMiddleware()
     */
    public function getRouteGroupMiddleware(): array
    {
        return [
            'has.user',
            self::roleAuthorizer([
                Role::ROLE_ID_SITE_ADMIN,
            ]),
        ];
    }

    /**
     * @copydoc \PKP\core\PKPBaseController::getGroupRoutes()
     */
    public function getGroupRoutes(): void
    {
        Route::get('', $this->index(...))
            ->name('plugins.scheduledTaskManager.index');

        Route::post('run', $this->run(...))
            ->name('plugins.scheduledTaskManager.run');

        Route::get('logs', $this->logs(...))
            ->name('plugins.scheduledTaskManager.logs');

        Route::delete('logs', $this->deleteLogs(...))
            ->name('plugins.scheduledTaskManager.logs.delete');
    }

    /**
     * Every registered task, plus the scheduler configuration summary.
     */
    public function index(Request $illuminateRequest): JsonResponse
    {
        $collector = new TaskCollector($this->plugin);

        return response()->json([
            'status' => $collector->status(),
            'tasks' => $collector->rows(),
        ], Response::HTTP_OK);
    }

    /**
     * Run one task immediately.
     */
    public function run(Request $illuminateRequest): JsonResponse
    {
        $taskName = (string) $illuminateRequest->input('task', '');

        if ($taskName === '') {
            return response()->json([
                'error' => __('plugins.generic.scheduledTaskManager.run.missingTask'),
            ], Response::HTTP_BAD_REQUEST);
        }

        $collector = new TaskCollector($this->plugin);

        if (!$collector->event($taskName)) {
            return response()->json([
                'error' => __('plugins.generic.scheduledTaskManager.run.notFound'),
            ], Response::HTTP_NOT_FOUND);
        }

        if ($blocked = $collector->blockedReason($taskName)) {
            return response()->json([
                'error' => $blocked['message'],
                'reason' => $blocked['reason'],
            ], $blocked['reason'] === TaskCollector::BLOCKED_RUNNING
                ? Response::HTTP_CONFLICT
                : Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = (new TaskRunner($collector))->run($taskName);

        if ($result['logFile'] ?? null) {
            $result['logFileUrl'] = $this->downloadUrl($result['logFile']);
        }

        return response()->json($result, Response::HTTP_OK);
    }

    /**
     * One page of a task's execution log files, ordered and filtered as asked.
     */
    public function logs(Request $illuminateRequest): JsonResponse
    {
        $taskName = (string) $illuminateRequest->query('task', '');

        if ($taskName === '') {
            return response()->json([
                'error' => __('plugins.generic.scheduledTaskManager.run.missingTask'),
            ], Response::HTTP_BAD_REQUEST);
        }

        $query = LogQuery::fromRequest($illuminateRequest);

        $logs = new LogRepository();
        $result = $logs->filesFor($taskName, $query);

        return response()->json([
            'directory' => $logs->directory(),
            'total' => $result['total'],
            // How many files this request opened, and whether the scan hit its ceiling before
            // reaching the end -- so a duration answer can say what it was drawn from.
            'scanned' => $result['scanned'],
            'truncated' => $result['truncated'],
            // How deep the repository is willing to page, so the pager can stop offering a
            // next page instead of walking into empty ones.
            'maxWindow' => LogRepository::MAX_WINDOW,
            'files' => array_map(
                fn (array $file) => [
                    'name' => $file['name'],
                    'modified' => ScheduleDescriber::moment(
                        Carbon::createFromTimestamp($file['modified'])
                    ),
                    'started' => $file['started'] === null
                        ? null
                        : ScheduleDescriber::moment(Carbon::createFromTimestamp($file['started'])),
                    'duration' => $file['duration'],
                    'lines' => $file['lines'],
                    'downloadUrl' => $this->downloadUrl($file['name']),
                ],
                $result['files']
            ),
            // Clamped and normalised server-side, so the pager and the filter form follow what
            // was applied rather than what was asked for.
        ] + $query->toArray(), Response::HTTP_OK);
    }

    /**
     * Delete a task's older log files.
     *
     * One task at a time by design: core's admin page already clears the whole directory. The
     * name is not checked against the registered tasks -- logs left by a task that no longer
     * exists are exactly the ones worth removing.
     */
    public function deleteLogs(Request $illuminateRequest): JsonResponse
    {
        $taskName = (string) $illuminateRequest->input('task', '');

        if ($taskName === '') {
            return response()->json([
                'error' => __('plugins.generic.scheduledTaskManager.run.missingTask'),
            ], Response::HTTP_BAD_REQUEST);
        }

        $days = $illuminateRequest->input('olderThanDays');
        $days = $days === null || $days === '' ? null : max(0, (int) $days);

        $logs = new LogRepository();
        $result = $logs->deleteFor($taskName, $days);

        return response()->json([
            'deleted' => $result['deleted'],
            'failed' => $result['failed'],
            'remaining' => $logs->countFor($taskName),
        ], Response::HTTP_OK);
    }

    /**
     * Download URL for a log file. Reuses core's site-admin-only downloadScheduledTaskLogFile
     * rather than serving files here: its access check and basename() guard already exist.
     */
    private function downloadUrl(string $filename): string
    {
        $request = $this->getRequest();

        return $request->getDispatcher()->url(
            $request,
            PKPApplication::ROUTE_PAGE,
            Application::SITE_CONTEXT_PATH,
            'admin',
            'downloadScheduledTaskLogFile',
            null,
            ['file' => $filename]
        );
    }
}
