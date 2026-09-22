/**
 * @file resources/js/describeOrigin.js
 *
 * Copyright (c) 2026 Touhidur Rahman
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * What ran a task, as a short label and a sentence explaining it, or null when that is not known.
 *
 * Every key is spelled out rather than assembled from the origin: the build scans for literal
 * translation calls to decide which strings the page is sent.
 */
export function describeOrigin(t, origin) {
	return (
		{
			web: {
				text: t('plugins.generic.scheduledTaskManager.lastRun.origin.web'),
				title: t(
					'plugins.generic.scheduledTaskManager.lastRun.origin.web.description',
				),
			},
			cli: {
				text: t('plugins.generic.scheduledTaskManager.lastRun.origin.cli'),
				title: t(
					'plugins.generic.scheduledTaskManager.lastRun.origin.cli.description',
				),
			},
			manual: {
				text: t('plugins.generic.scheduledTaskManager.lastRun.origin.manual'),
				title: t(
					'plugins.generic.scheduledTaskManager.lastRun.origin.manual.description',
				),
			},
		}[origin] ?? null
	);
}
