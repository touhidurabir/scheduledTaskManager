{**
 * templates/scheduledTasks.tpl
 *
 * Copyright (c) 2026 Touhidur Rahman
 * Distributed under The MIT License. For full terms see the file LICENSE.
 *
 * Scheduled task manager page.
 *
 * The page component is core's generic `Page`; the plugin's own component is registered
 * globally by the compiled bundle and resolved from this in-DOM template, the same shape
 * core uses for admin/jobs.tpl.
 *}
{extends file="layouts/backend.tpl"}

{block name="page"}
	<h1 class="app__pageHeading">
		{$pageTitle|escape}
	</h1>
	<div class="app__contentPanel">
		<stm-scheduled-tasks-page v-bind="pageInitConfig" />
	</div>
{/block}
