/**
 * @file resources/js/formatDuration.js
 *
 * Copyright (c) 2026 Touhidur Rahman
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * A duration in seconds, short enough for a table cell. Units stay here rather than in the
 * translation files: they are the same in every language this plugin ships.
 *
 * Under ten seconds a tenth is kept: a recorded runtime is measured to the hundredth, and "3s"
 * would hide that a task now takes 3.9. A duration read from log stamps is whole seconds and
 * comes out unchanged.
 */
export function formatDuration(seconds) {
	if (seconds === null || seconds === undefined) {
		return '—';
	}

	if (seconds < 1) {
		return '<1s';
	}

	if (seconds < 10) {
		return `${Math.round(seconds * 10) / 10}s`;
	}

	const whole = Math.round(seconds);

	if (whole < 60) {
		return `${whole}s`;
	}

	if (whole < 3600) {
		return `${Math.floor(whole / 60)}m ${whole % 60}s`;
	}

	return `${Math.floor(whole / 3600)}h ${Math.floor((whole % 3600) / 60)}m`;
}
