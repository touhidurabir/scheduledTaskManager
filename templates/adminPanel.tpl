{**
 * templates/adminPanel.tpl
 *
 * Copyright (c) 2026 Touhidur Rahman
 * Distributed under The MIT License. For full terms see the file LICENSE.
 *
 * Scheduled task manager entry point, appended to the site administration index page
 * through the Templates::Admin::Index::AdminFunctions hook.
 *}
<action-panel>
	<h2>{translate key="plugins.generic.scheduledTaskManager.displayName"}</h2>
	<p>
		{translate key="plugins.generic.scheduledTaskManager.admin.description"}
	</p>
	<template #actions>
		<pkp-button
			element="a"
			href="{$scheduledTaskManagerUrl|escape}"
		>
			{translate key="plugins.generic.scheduledTaskManager.admin.view"}
		</pkp-button>
	</template>
</action-panel>
