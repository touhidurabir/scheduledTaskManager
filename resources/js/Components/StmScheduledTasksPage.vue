<!--
  @file resources/js/Components/StmScheduledTasksPage.vue

  Copyright (c) 2026 Touhidur Rahman
  Distributed under The MIT License. For full terms see the file LICENSE.
-->

<script setup>
import {computed, onMounted, ref} from 'vue';
import StmTaskLogsModal from './StmTaskLogsModal.vue';

const {useLocalize} = pkp.modules.useLocalize;
const {useFetch} = pkp.modules.useFetch;
const {useModal} = pkp.modules.useModal;
const {useNotify} = pkp.modules.useNotify;

const props = defineProps({
	apiUrl: {type: String, required: true},
	runApiUrl: {type: String, required: true},
	logsApiUrl: {type: String, required: true},
});

const {t} = useLocalize();
const {openDialog, openSideModal} = useModal();
const {notify} = useNotify();

const status = ref(null);
const tasks = ref([]);
const runningTask = ref(null);

const {data: listing, isLoading, fetch: fetchListing} = useFetch(props.apiUrl);

// The task to run is written into this object immediately before each request: useFetch keeps
// a reference to the object it was given, so mutating it is how a single instance serves
// every row.
const runPayload = {task: ''};

const {
	data: runResult,
	isLoading: isRunning,
	fetch: sendRun,
} = useFetch(props.runApiUrl, {
	method: 'POST',
	body: runPayload,
	onError(error) {
		// Surfaced as a notification next to the row instead of the generic network error
		// dialog, because a refusal (a switched-off or already-running task) is an expected
		// answer here rather than a fault.
		notify(
			error?.data?.error ||
				t('plugins.generic.scheduledTaskManager.run.notFound'),
			'warning',
		);
		return true;
	},
});

/**
 * How far a task runner checkpoint may sit from the run it describes and still be treated as
 * that same run, in seconds. The runner stamps its checkpoint around the execution, not at the
 * instant of it.
 */
const CHECKPOINT_TOLERANCE = 300;

const webRunner = computed(() => status.value?.webRunner ?? null);

/**
 * Shown whenever the web task runner is on, and on nothing else: whether a crontab entry also
 * exists is not observable from here, so the page states the hazard and leaves the check to the
 * administrator rather than guessing and getting it wrong.
 */
const showsRunnerNotice = computed(() => webRunner.value?.state === 'on');

const webRunnerCard = computed(() => {
	const runner = webRunner.value;

	// Every key is spelled out rather than assembled from the state name: the build scans this
	// file for literal translation calls to decide which strings the page is sent, so a key
	// that only exists at runtime would arrive untranslated.
	const value = {
		on: t('plugins.generic.scheduledTaskManager.status.webRunner.on'),
		off: t('plugins.generic.scheduledTaskManager.status.webRunner.off'),
		unavailable: t(
			'plugins.generic.scheduledTaskManager.status.webRunner.unavailable',
		),
	}[runner.state];

	const detail = {
		on: t('plugins.generic.scheduledTaskManager.status.webRunner.onDetail', {
			interval: runner.interval,
		}),
		off: t('plugins.generic.scheduledTaskManager.status.webRunner.offDetail'),
		unavailable: t(
			'plugins.generic.scheduledTaskManager.status.webRunner.unavailableDetail',
		),
	}[runner.state];

	return {
		key: 'webRunner',
		label: t('plugins.generic.scheduledTaskManager.status.webRunner'),
		value,
		detail,
		note: runner.lastRun
			? t('plugins.generic.scheduledTaskManager.status.webRunner.lastSeen', {
					time: runner.lastRun.relative,
				})
			: null,
		tone: runner.state === 'on' ? 'good' : 'neutral',
	};
});

const cards = computed(() => {
	if (!status.value) {
		return [];
	}

	const summary = status.value;

	return [
		webRunnerCard.value,
		{
			key: 'tasks',
			label: t('plugins.generic.scheduledTaskManager.status.tasks'),
			value: String(summary.taskCount),
			detail: t('plugins.generic.scheduledTaskManager.status.timezoneValue', {
				timezone: summary.timezone,
				key: summary.timezoneConfigKey,
			}),
			note: null,
			tone: 'neutral',
		},
		{
			key: 'logFiles',
			label: t('plugins.generic.scheduledTaskManager.status.logFiles'),
			value: summary.logDirectoryExists
				? String(summary.logFileCount)
				: t('plugins.generic.scheduledTaskManager.status.logDirectoryNone'),
			detail: summary.logDirectory,
			note: null,
			tone: 'neutral',
		},
	];
});

/**
 * Short name for headings and dialogs. Not the task's own getName(): core returns a
 * sentence-length description there, which reads badly as a title. The table shows both.
 */
function taskLabel(task) {
	return shortName(task.name);
}

function shortName(name) {
	const parts = String(name).split('\\');
	return parts[parts.length - 1];
}

/**
 * Split a fully qualified class name so the template can offer a break opportunity after each
 * namespace separator. Without it the browser breaks mid-word ("DOAJI / nfoSender"), which makes
 * the one column an administrator most needs to read the hardest to read.
 */
function nameSegments(name) {
	return String(name).split('\\');
}

/**
 * Noise on every row, but the whole story in one case: a checkpoint standing clear of the last
 * real run means the runner marked an occurrence covered that never happened -- what someone
 * chasing a task that "never runs" needs to see. Only while that runner is on; otherwise the
 * stored checkpoints are leftovers that explain nothing.
 */
function showsCheckpoint(task) {
	if (!task.runnerCheckpoint || webRunner.value?.state !== 'on') {
		return false;
	}

	if (!task.lastRun) {
		return true;
	}

	// A checkpoint written moments around the run it belongs to agrees with that run and says
	// nothing worth a line of its own. Only one that stands clear of the last real execution --
	// a boundary marked covered for an occurrence that did not happen -- is worth showing.
	return (
		task.runnerCheckpoint.timestamp >
		task.lastRun.timestamp + CHECKPOINT_TOLERANCE
	);
}

function lastRunTitle(task) {
	if (!task.lastRun) {
		return null;
	}

	return task.lastRun.source === 'history'
		? t('plugins.generic.scheduledTaskManager.lastRun.fromHistory')
		: t('plugins.generic.scheduledTaskManager.lastRun.fromLog');
}

/**
 * A few words naming why a task cannot be run, for the row itself. The full explanation stays
 * in the title attribute.
 */
function blockedLabel(task) {
	if (!task.blocked) {
		return null;
	}

	return {
		filtered: t('plugins.generic.scheduledTaskManager.blocked.badge.filtered'),
		running: t('plugins.generic.scheduledTaskManager.blocked.badge.running'),
		unnamed: t('plugins.generic.scheduledTaskManager.blocked.badge.unnamed'),
	}[task.blocked.reason];
}

async function reload() {
	await fetchListing();

	if (listing.value) {
		status.value = listing.value.status;
		tasks.value = listing.value.tasks;
	}
}

function confirmRun(task) {
	openDialog({
		name: 'stmRunTask',
		title: t('plugins.generic.scheduledTaskManager.run.confirm.title', {
			task: taskLabel(task),
		}),
		message: [
			t('plugins.generic.scheduledTaskManager.run.confirm.message'),
			t('plugins.generic.scheduledTaskManager.run.confirm.warning'),
		].join(' '),
		modalStyle: 'negative',
		actions: [
			{
				label: t('plugins.generic.scheduledTaskManager.run.confirm.action'),
				isWarnable: true,
				callback: (close) => {
					close();
					run(task);
				},
			},
			{
				label: t('common.cancel'),
				callback: (close) => close(),
			},
		],
	});
}

async function run(task) {
	runningTask.value = task.name;
	runPayload.task = task.name;

	try {
		await sendRun();

		if (runResult.value) {
			notify(
				runResult.value.message,
				runResult.value.ok ? 'success' : 'warning',
			);
		}
	} finally {
		runningTask.value = null;
	}

	// The run changes last-run, log counts and possibly the next due date, so the whole
	// listing is re-read rather than patched in place.
	await reload();
}

function showLogs(task) {
	openSideModal(StmTaskLogsModal, {
		taskName: task.name,
		taskLabel: taskLabel(task),
		logsApiUrl: props.logsApiUrl,
		// Deleting logs there changes the count shown here, and may change a last run that was
		// only ever known from a log file.
		onLogsChanged: reload,
	});
}

onMounted(reload);
</script>

<template>
	<div class="stm">
		<div
			v-if="showsRunnerNotice"
			class="stm__alert stm__alert--warning"
			role="note"
		>
			<span class="stm__alertTitle">
				{{ t('plugins.generic.scheduledTaskManager.notice.cron.title') }}
			</span>
			<span class="stm__alertMessage">
				{{ t('plugins.generic.scheduledTaskManager.notice.cron.message') }}
			</span>
			<code class="stm__command">{{ status.cronCommand }}</code>
			<span class="stm__alertMessage">
				{{ t('plugins.generic.scheduledTaskManager.notice.cron.warning') }}
			</span>
		</div>

		<div v-if="status" class="stm__cards">
			<div
				v-for="card in cards"
				:key="card.key"
				class="stm__card"
				:class="`stm__card--${card.tone}`"
			>
				<span class="stm__cardLabel">{{ card.label }}</span>
				<span class="stm__cardValue">{{ card.value }}</span>
				<span v-if="card.detail" class="stm__cardDetail">
					{{ card.detail }}
				</span>
				<span v-if="card.note" class="stm__cardDetail">{{ card.note }}</span>
			</div>
		</div>

		<PkpTable>
			<template #label>
				{{ t('plugins.generic.scheduledTaskManager.table.label') }}
			</template>
			<template #description>
				{{
					t('plugins.generic.scheduledTaskManager.table.description', {
						count: tasks.length,
					})
				}}
			</template>
			<template #top-controls>
				<PkpButton :is-disabled="isLoading" @click="reload">
					{{ t('plugins.generic.scheduledTaskManager.action.refresh') }}
				</PkpButton>
			</template>

			<PkpTableHeader>
				<PkpTableColumn>
					{{ t('plugins.generic.scheduledTaskManager.column.task') }}
				</PkpTableColumn>
				<PkpTableColumn>
					{{ t('plugins.generic.scheduledTaskManager.column.interval') }}
				</PkpTableColumn>
				<PkpTableColumn>
					{{ t('plugins.generic.scheduledTaskManager.column.lastRun') }}
				</PkpTableColumn>
				<PkpTableColumn>
					{{ t('plugins.generic.scheduledTaskManager.column.due') }}
				</PkpTableColumn>
				<PkpTableColumn>
					{{ t('plugins.generic.scheduledTaskManager.column.actions') }}
				</PkpTableColumn>
			</PkpTableHeader>

			<PkpTableBody>
				<template #no-content>
					<span v-if="isLoading" class="stm__loading">
						<PkpSpinner />
						{{ t('common.loading') }}
					</span>
					<span v-else>
						{{ t('plugins.generic.scheduledTaskManager.table.empty') }}
					</span>
				</template>

				<PkpTableRow
					v-for="task in tasks"
					:key="task.name"
					:class="{'stm__row--blocked': !task.canRun}"
				>
					<PkpTableCell :is-row-header="true">
						<span class="stm__taskName">
							<template
								v-for="(segment, index) in nameSegments(task.name)"
								:key="index"
							>
								<template v-if="index">\</template>
								<wbr v-if="index" />
								{{ segment }}
							</template>
						</span>
						<span v-if="task.displayName" class="stm__taskDescription">
							{{ task.displayName }}
						</span>
						<span
							v-if="task.overdue"
							class="stm__badge stm__badge--overdue"
							:title="
								t('plugins.generic.scheduledTaskManager.overdue.description')
							"
						>
							{{ t('plugins.generic.scheduledTaskManager.overdue.badge') }}
						</span>
						<span
							v-if="task.blocked"
							class="stm__badge"
							:title="task.blocked.message"
						>
							{{ blockedLabel(task) }}
						</span>
					</PkpTableCell>

					<PkpTableCell>
						<span>{{ task.intervalLabel }}</span>
						<span class="stm__expression">({{ task.expression }})</span>
					</PkpTableCell>

					<PkpTableCell>
						<template v-if="task.lastRun">
							<span class="stm__moment" :title="lastRunTitle(task)">
								{{ task.lastRun.label }}
							</span>
							<span class="stm__muted">{{ task.lastRun.relative }}</span>
							<span v-if="task.lastRun.status === 'failed'" class="stm__failed">
								{{ t('plugins.generic.scheduledTaskManager.lastRun.failed') }}
							</span>
						</template>
						<span v-else class="stm__muted">
							{{ t('plugins.generic.scheduledTaskManager.lastRun.never') }}
						</span>
						<span v-if="task.overdue" class="stm__overdue">
							{{
								t('plugins.generic.scheduledTaskManager.overdue.expected', {
									date: task.overdue.label,
								})
							}}
						</span>
						<span
							v-if="showsCheckpoint(task)"
							class="stm__muted"
							:title="
								t('plugins.generic.scheduledTaskManager.checkpoint.description')
							"
						>
							{{
								t('plugins.generic.scheduledTaskManager.checkpoint.label', {
									date: task.runnerCheckpoint.label,
								})
							}}
						</span>
					</PkpTableCell>

					<PkpTableCell>
						<template v-if="task.nextRun">
							<span class="stm__moment">{{ task.nextRun.label }}</span>
							<span class="stm__muted">{{ task.nextRun.relative }}</span>
						</template>
					</PkpTableCell>

					<PkpTableCell>
						<div class="stm__actions">
							<PkpButton
								:is-disabled="!task.canRun || isRunning"
								:is-warnable="true"
								:title="task.blocked ? task.blocked.message : null"
								@click="confirmRun(task)"
							>
								<PkpSpinner v-if="runningTask === task.name" />
								<template v-else>
									{{ t('plugins.generic.scheduledTaskManager.action.run') }}
								</template>
							</PkpButton>
							<PkpButton @click="showLogs(task)">
								{{ t('plugins.generic.scheduledTaskManager.action.logs') }}
								<template v-if="task.logCount">({{ task.logCount }})</template>
							</PkpButton>
						</div>
					</PkpTableCell>
				</PkpTableRow>
			</PkpTableBody>
		</PkpTable>
	</div>
</template>

<style scoped>
/*
 * Deliberately plain CSS rather than the core utility classes: the utility set is the layer
 * that moves most between releases, and this plugin ships a single build for 3.5 and main.
 */

/* Not dismissible on purpose: it describes a configuration, not an event. */
.stm__alert {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
	margin-bottom: 1rem;
	padding: 1rem 1.25rem;
	border: 1px solid;
	border-left-width: 0.375rem;
	border-radius: 0.25rem;
}

.stm__alert--warning {
	border-color: #a37b16;
	background: #fdf9ef;
	color: #6f5310;
}

.stm__alertTitle {
	font-size: 1rem;
	font-weight: 700;
}

.stm__alertMessage {
	font-size: 0.875rem;
	line-height: 1.5;
}

/* Wide enough to be copied in one piece; scrolls rather than wrapping mid-path. */
.stm__command {
	display: block;
	margin: 0.25rem 0;
	padding: 0.5rem 0.75rem;
	border: 1px solid rgba(0, 0, 0, 0.15);
	border-radius: 0.25rem;
	background: rgba(255, 255, 255, 0.7);
	font-family: monospace;
	font-size: 0.8125rem;
	overflow-x: auto;
	white-space: pre;
}

.stm__cards {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr));
	gap: 1rem;
	margin-bottom: 1.5rem;
}

.stm__card {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
	padding: 1rem 1.25rem;
	border: 1px solid #d6d6d6;
	border-top-width: 0.25rem;
	border-radius: 0.25rem;
	background: #fff;
}

.stm__card--good {
	border-top-color: #00747a;
}

.stm__card--warning {
	border-top-color: #a37b16;
}

.stm__cardLabel {
	font-size: 0.75rem;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	opacity: 0.7;
}

.stm__cardValue {
	font-size: 1.5rem;
	font-weight: 700;
	line-height: 1.2;
	overflow-wrap: break-word;
}

.stm__cardDetail {
	font-size: 0.8125rem;
	line-height: 1.45;
	opacity: 0.8;
	overflow-wrap: anywhere;
}

/*
 * A task that cannot be run right now -- switched off by configuration, already running, or
 * unidentifiable -- is faded so the runnable rows read first. This is de-emphasis, not
 * disabling: the row stays selectable and its text stays legible.
 */
.stm__row--blocked {
	opacity: 0.6;
}

.stm__taskName {
	display: block;
	font-weight: 700;
	font-family: monospace;
	/* break-word, not anywhere: with the <wbr> hints above this breaks at namespace
	   separators and only splits a segment when one cannot fit on a line by itself. */
	overflow-wrap: break-word;
}

.stm__taskDescription,
.stm__expression,
.stm__muted {
	display: block;
	font-size: 0.75rem;
	opacity: 0.7;
	overflow-wrap: anywhere;
}

.stm__expression {
	font-family: monospace;
	white-space: nowrap;
}

/* A date and its time belong on one line; the column is given the width for it. */
.stm__moment {
	display: block;
	white-space: nowrap;
}

.stm__badge {
	display: inline-block;
	margin-top: 0.25rem;
	padding: 0.0625rem 0.5rem;
	border: 1px solid currentColor;
	border-radius: 0.75rem;
	font-size: 0.6875rem;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.03em;
}

/* Red, not faded: a blocked row is de-emphasised, an overdue one is the point of the page. */
.stm__badge--overdue {
	color: #a3161a;
}

.stm__failed,
.stm__overdue {
	display: block;
	font-size: 0.75rem;
	font-weight: 700;
}

.stm__overdue {
	color: #a3161a;
}

/*
 * The two actions share the column evenly, so their edges line up down the table however wide
 * the labels are; when the column is too narrow for both they wrap together into a stack of
 * equal, full-width buttons rather than half a row.
 */
.stm__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 0.5rem;
}

.stm__actions > * {
	flex: 1 1 5rem;
	justify-content: center;
	text-align: center;
	white-space: nowrap;
}

.stm__loading {
	display: inline-flex;
	align-items: center;
	gap: 0.5rem;
}
</style>
