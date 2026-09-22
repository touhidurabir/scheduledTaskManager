<?php

/**
 * @file ScheduledTaskManagerPlugin.php
 *
 * Copyright (c) 2026 Touhidur Rahman
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class ScheduledTaskManagerPlugin
 *
 * @brief Site administration UI for the Laravel-backed scheduled task system
 */

namespace APP\plugins\generic\scheduledTaskManager;

use APP\core\Application;
use APP\plugins\generic\scheduledTaskManager\classes\ExecutionHistory;
use APP\plugins\generic\scheduledTaskManager\classes\ScheduledTaskApiController;
use APP\plugins\generic\scheduledTaskManager\classes\ScheduledTasksHandler;
use APP\template\TemplateManager;
use Illuminate\Support\Facades\DB;
use PKP\core\APIRouter;
use PKP\core\PKPApplication;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use Throwable;

class ScheduledTaskManagerPlugin extends GenericPlugin
{
    /** The page the task manager is grafted onto. */
    public const PAGE = 'admin';

    /** The operation that renders the task manager. */
    public const OP = 'scheduledTasks';

    /**
     * Asset loading context. getResourcesByContext() resolves "backend-{page}-{op}", so naming it
     * this precisely scopes the bundle to this page with no per-request branching.
     */
    public const ASSET_CONTEXT = 'backend-' . self::PAGE . '-' . self::OP;

    /**
     * Memoised context count, so isSitePlugin()/getEnabled() stay cheap when called
     * repeatedly within a request (the plugin grid does exactly that).
     */
    private static ?int $contextCount = null;

    /** Memoised sole context id for single-context installations. */
    private static ?int $soleContextId = null;

    /** Memoised answer to isEnabledAnywhere(), so a batch of task runs asks once. */
    private static ?bool $enabledAnywhere = null;

    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);

        if (!$success || !Application::isInstalled() || Application::isUpgrading()) {
            return $success;
        }

        $enabledHere = (bool) $this->getEnabled($mainContextId);

        // The recorder must answer for contexts other than this request's -- cron runs with none
        // at all -- so it is gated on isEnabledAnywhere() rather than on the local answer. That
        // query is deferred to the moment a task finishes; attaching the listener itself is free.
        ExecutionHistory::listen(
            $this,
            fn (): bool => $enabledHere || $this->isEnabledAnywhere()
        );

        if (!$enabledHere) {
            return $success;
        }

        Hook::add('Templates::Admin::Index::AdminFunctions', $this->addAdminPanel(...));
        Hook::add('LoadHandler', $this->setPageHandler(...));
        Hook::add('APIHandler::endpoints::plugin', $this->registerApiControllers(...));

        return $success;
    }

    /**
     * @copydoc Plugin::isSitePlugin()
     *
     * Dynamic: core hides Site Settings > Plugins on single-context installs
     * (AdminHandler::siteSettingsAvailability() gates it on exactly this), so there the plugin
     * must present itself as a context plugin or it could never be enabled at all.
     */
    public function isSitePlugin()
    {
        if (!Application::isInstalled() || Application::isUpgrading()) {
            return true;
        }

        return $this->getContextCount() !== 1;
    }

    /**
     * @copydoc LazyLoadPlugin::getEnabled()
     *
     * On a single-context install the plugin is enabled against the journal, but its UI runs at
     * site level where the request carries no context. Resolve the sole context explicitly.
     *
     * @param null|int $contextId
     */
    public function getEnabled($contextId = null)
    {
        if ($contextId === null && !$this->isSitePlugin()) {
            $contextId = $this->getSoleContextId();
        }

        return parent::getEnabled($contextId);
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName()
    {
        return __('plugins.generic.scheduledTaskManager.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription()
    {
        return __('plugins.generic.scheduledTaskManager.description');
    }

    /**
     * Append the task manager panel to the site administration index page.
     *
     * @param array $args [&$params, $smarty, &$output]
     */
    public function addAdminPanel(string $hookName, array $args): bool
    {
        $templateMgr = $args[1]; /** @var TemplateManager $templateMgr */
        $output = &$args[2];

        $templateMgr->assign('scheduledTaskManagerUrl', $this->getPageUrl());

        $output .= $templateMgr->fetch($this->getTemplateResource('adminPanel.tpl'));

        // Let any other plugin keep decorating this hook.
        return Hook::CONTINUE;
    }

    /**
     * Substitute the task manager handler for admin/scheduledTasks.
     *
     * @param array $args [&$page, &$op, &$sourceFile, &$handler]
     */
    public function setPageHandler(string $hookName, array $args): bool
    {
        $page = $args[0];
        $op = $args[1];

        if ($page !== self::PAGE || $op !== self::OP) {
            return Hook::CONTINUE;
        }

        $handler = &$args[3];
        $handler = new ScheduledTasksHandler($this);

        return Hook::ABORT;
    }

    /**
     * Register the plugin's site-wide API controller.
     */
    public function registerApiControllers(string $hookName, APIRouter $apiRouter): bool
    {
        $apiRouter->registerPluginApiControllers([
            new ScheduledTaskApiController($this),
        ]);

        return Hook::CONTINUE;
    }

    /**
     * URL of the task manager page.
     */
    public function getPageUrl(): string
    {
        $request = Application::get()->getRequest();

        return $request->getDispatcher()->url(
            $request,
            PKPApplication::ROUTE_PAGE,
            Application::SITE_CONTEXT_PATH,
            self::PAGE,
            self::OP
        );
    }

    /**
     * Base URL of the plugin's compiled front-end bundle.
     */
    public function getAssetUrl(string $file): string
    {
        $request = Application::get()->getRequest();

        return "{$request->getBaseUrl()}/{$this->getPluginPath()}/public/build/{$file}";
    }

    /**
     * Queue the compiled Vue bundle. Called by the handler rather than register(), so requests
     * that never render this page do not pay for a TemplateManager.
     */
    public function loadAssets(): void
    {
        $templateMgr = TemplateManager::getManager(Application::get()->getRequest());

        $templateMgr->addJavaScript(
            'scheduledTaskManager',
            $this->getAssetUrl('build.iife.js'),
            [
                'contexts' => [self::ASSET_CONTEXT],
                'priority' => TemplateManager::STYLE_SEQUENCE_LAST,
            ]
        );

        $templateMgr->addStyleSheet(
            'scheduledTaskManager',
            $this->getAssetUrl('build.css'),
            [
                'contexts' => [self::ASSET_CONTEXT],
                'priority' => TemplateManager::STYLE_SEQUENCE_LAST,
            ]
        );
    }

    /**
     * Whether the plugin is enabled in any context at all. Reads the settings table directly
     * because the inherited accessors answer for one context at a time.
     */
    protected function isEnabledAnywhere(): bool
    {
        if (self::$enabledAnywhere !== null) {
            return self::$enabledAnywhere;
        }

        try {
            return self::$enabledAnywhere = DB::table('plugin_settings')
                ->where('plugin_name', strtolower($this->getName()))
                ->where('setting_name', 'enabled')
                ->whereIn('setting_value', ['1', 'true'])
                ->exists();
        } catch (Throwable $exception) {
            return self::$enabledAnywhere = false;
        }
    }

    /**
     * Number of contexts installed, enabled or not. Mirrors
     * AdminHandler::siteSettingsAvailability() so "single context" cannot drift from core's.
     */
    protected function getContextCount(): int
    {
        return self::$contextCount ??= app()->get('context')->getCount();
    }

    /**
     * Id of the only context, when there is exactly one.
     */
    protected function getSoleContextId(): ?int
    {
        if (self::$soleContextId !== null) {
            return self::$soleContextId;
        }

        $ids = app()->get('context')->getIds();

        return count($ids) === 1
            ? self::$soleContextId = (int) reset($ids)
            : null;
    }
}
