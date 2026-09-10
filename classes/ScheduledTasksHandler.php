<?php

/**
 * @file classes/ScheduledTasksHandler.php
 *
 * Copyright (c) 2026 Touhidur Rahman
 * Distributed under The MIT License. For full terms see the file LICENSE.
 *
 * @class ScheduledTasksHandler
 *
 * @brief Renders the scheduled task manager page at index.php/index/admin/scheduledTasks.
 *
 * Substituted for the core AdminHandler by the plugin's LoadHandler hook, so it declares its own
 * role assignment and site access policy rather than inheriting AdminHandler's.
 */

namespace APP\plugins\generic\scheduledTaskManager\classes;

use APP\core\Application;
use APP\handler\Handler;
use APP\plugins\generic\scheduledTaskManager\ScheduledTaskManagerPlugin;
use APP\template\TemplateManager;
use PKP\core\PKPApplication;
use PKP\core\PKPRequest;
use PKP\security\authorization\PKPSiteAccessPolicy;
use PKP\security\Role;

class ScheduledTasksHandler extends Handler
{
    /** @copydoc PKPHandler::_isBackendPage */
    public $_isBackendPage = true;

    public function __construct(private ScheduledTaskManagerPlugin $plugin)
    {
        parent::__construct();

        $this->addRoleAssignment(
            [Role::ROLE_ID_SITE_ADMIN],
            [ScheduledTaskManagerPlugin::OP]
        );
    }

    /**
     * @copydoc PKPHandler::authorize()
     */
    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new PKPSiteAccessPolicy($request, null, $roleAssignments));

        $returner = parent::authorize($request, $args, $roleAssignments);

        // The scheduler is site-level; like the rest of Administration this page is not reachable
        // from inside a journal.
        if ($request->getContext()) {
            return false;
        }

        return $returner;
    }

    /**
     * Display the scheduled task manager. Named after the op, since PKPPageRouter dispatches to
     * the method matching it.
     *
     * @param array $args
     * @param \PKP\core\PKPRequest $request
     */
    public function scheduledTasks($args, $request)
    {
        $this->setupTemplate($request);
        $this->plugin->loadAssets();

        $templateMgr = TemplateManager::getManager($request);
        $router = $request->getRouter();

        $templateMgr->setState([
            'pageInitConfig' => [
                'apiUrl' => $this->apiUrl($request),
                'runApiUrl' => $this->apiUrl($request, 'run'),
                'logsApiUrl' => $this->apiUrl($request, 'logs'),
            ],
        ]);

        $templateMgr->assign([
            'pageComponent' => 'Page',
            'pageTitle' => __('plugins.generic.scheduledTaskManager.pageTitle'),
            'breadcrumbs' => [
                [
                    'id' => 'admin',
                    'url' => $router->url($request, Application::SITE_CONTEXT_PATH, 'admin'),
                    'name' => __('navigation.admin'),
                ],
                [
                    'id' => 'scheduledTasks',
                    'name' => __('plugins.generic.scheduledTaskManager.pageTitle'),
                ],
            ],
        ]);

        $templateMgr->display($this->plugin->getTemplateResource('scheduledTasks.tpl'));
    }

    /**
     * URL of the plugin's API, optionally for a specific operation.
     */
    private function apiUrl(PKPRequest $request, string $operation = ''): string
    {
        return $request->getDispatcher()->url(
            $request,
            PKPApplication::ROUTE_API,
            Application::SITE_CONTEXT_PATH,
            'scheduled-tasks' . ($operation === '' ? '' : "/{$operation}")
        );
    }
}
