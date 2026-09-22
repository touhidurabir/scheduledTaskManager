/**
 * @file resources/js/main.js
 *
 * Copyright (c) 2026 Touhidur Rahman
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * Entry point for the Scheduled Task Manager bundle.
 *
 * Components are registered globally so the page's Smarty template can resolve them from the
 * in-DOM template of core's generic `Page` container, the same way core resolves <jobs-page>.
 * Registration happens when this bundle is evaluated, which is before backend.tpl calls
 * pkp.registry.init().
 */
import StmScheduledTasksPage from './Components/StmScheduledTasksPage.vue';

pkp.registry.registerComponent('StmScheduledTasksPage', StmScheduledTasksPage);
