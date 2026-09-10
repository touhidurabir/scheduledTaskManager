<!--
  @file resources/js/Components/StmTaskLogsModal.vue

  Copyright (c) 2026 Touhidur Rahman
  Distributed under The MIT License. For full terms see the file LICENSE.
-->

<script setup>
import {computed, onMounted, ref} from 'vue';

const {useLocalize} = pkp.modules.useLocalize;
const {useFetch} = pkp.modules.useFetch;
const {useModal} = pkp.modules.useModal;
const {useNotify} = pkp.modules.useNotify;

const props = defineProps({
	taskName: {type: String, required: true},
	taskLabel: {type: String, required: true},
	logsApiUrl: {type: String, required: true},
	// Lets the listing behind the modal pick up a changed log count without a manual refresh.
	onLogsChanged: {type: Function, default: () => {}},
});

const {t} = useLocalize();
const {openDialog} = useModal();
const {notify} = useNotify();

const PER_PAGE_OPTIONS = [10, 20, 30, 40, 50];

/** Cleanup cutoffs, in days. Null keeps nothing back. */
const CLEANUP_OPTIONS = [7, 30, 90, null];

const files = ref([]);
const total = ref(0);
const directory = ref('');
const offset = ref(0);
const perPage = ref(20);
const maxWindow = ref(null);
const scanned = ref(0);
const truncated = ref(false);

const sortDescriptor = ref({column: 'started', direction: 'descending'});

// What the form holds. Only copied into `filters` when applied, so typing a date does not fire
// a request per keystroke.
const draft = ref({from: '', to: '', minDuration: '', maxDuration: ''});
const filters = ref({from: '', to: '', minDuration: '', maxDuration: ''});

// Mutated immediately before each request: useFetch holds a reference to the object it was
// given, so this one object serves every page.
const query = {task: props.taskName, limit: perPage.value, offset: 0};

const {data, isLoading, fetch} = useFetch(props.logsApiUrl, {query});

const cleanupDays = ref(30);

// Same one-object-per-instance arrangement as the listing query above.
const cleanupQuery = {task: props.taskName, olderThanDays: ''};

const {
	data: cleanupResult,
	isLoading: isCleaning,
	fetch: sendCleanup,
} = useFetch(props.logsApiUrl, {
	method: 'DELETE',
	query: cleanupQuery,
	onError(error) {
		notify(
			error?.data?.error ||
				t('plugins.generic.scheduledTaskManager.logs.cleanup.error'),
			'warning',
		);
		return true;
	},
});

const firstShown = computed(() => (files.value.length ? offset.value + 1 : 0));
const lastShown = computed(() => offset.value + files.value.length);

const hasPrevious = computed(() => offset.value > 0);

const hasFilters = computed(() =>
	Object.values(filters.value).some((value) => value !== ''),
);

/**
 * A next page exists when the server reported more files and the repository will still reach that
 * far: it narrows rather than sorting the whole directory, so paging depth is capped.
 */
const hasNext = computed(
	() =>
		lastShown.value < total.value &&
		(maxWindow.value === null ||
			offset.value + perPage.value < maxWindow.value),
);

async function load() {
	query.limit = perPage.value;
	query.offset = offset.value;
	query.sort = sortDescriptor.value.column;
	query.direction = sortDescriptor.value.direction;
	Object.assign(query, filters.value);

	await fetch();

	if (!data.value) {
		return;
	}

	files.value = data.value.files;
	total.value = data.value.total;
	directory.value = data.value.directory;
	maxWindow.value = data.value.maxWindow ?? null;
	scanned.value = data.value.scanned ?? 0;
	truncated.value = data.value.truncated ?? false;

	// Everything below is clamped and normalised server-side, so the pager, the sort arrows and
	// the filter form follow what was applied rather than what was asked for.
	offset.value = data.value.offset;
	perPage.value = data.value.limit;
	sortDescriptor.value = {
		column: data.value.sort,
		direction: data.value.direction,
	};
	filters.value = {
		from: data.value.from ?? '',
		to: data.value.to ?? '',
		minDuration: data.value.minDuration ?? '',
		maxDuration: data.value.maxDuration ?? '',
	};
	draft.value = {...filters.value};
}

/**
 * Sorting and filtering both change which files are on page one, so both return there rather
 * than leaving the pager pointing into a set that no longer exists.
 */
function applySort(column) {
	sortDescriptor.value =
		sortDescriptor.value.column === column
			? {
					column,
					direction:
						sortDescriptor.value.direction === 'descending'
							? 'ascending'
							: 'descending',
				}
			: {column, direction: 'descending'};

	offset.value = 0;
	load();
}

function applyFilters() {
	filters.value = {...draft.value};
	offset.value = 0;
	load();
}

function clearFilters() {
	draft.value = {from: '', to: '', minDuration: '', maxDuration: ''};
	applyFilters();
}

function previous() {
	offset.value = Math.max(0, offset.value - perPage.value);
	load();
}

function next() {
	offset.value += perPage.value;
	load();
}

function changePerPage(event) {
	perPage.value = Number(event.target.value);
	// The current offset would land mid-page under a different page size, and page one is
	// where someone changing the size is looking anyway.
	offset.value = 0;
	load();
}

function cleanupOptionLabel(days) {
	return days === null
		? t('plugins.generic.scheduledTaskManager.logs.cleanup.all')
		: t('plugins.generic.scheduledTaskManager.logs.cleanup.days', {
				count: days,
			});
}

function confirmCleanup() {
	const days = cleanupDays.value;

	openDialog({
		name: 'stmCleanupLogs',
		title: t(
			'plugins.generic.scheduledTaskManager.logs.cleanup.confirm.title',
			{
				task: props.taskLabel,
			},
		),
		message: [
			days === null
				? t(
						'plugins.generic.scheduledTaskManager.logs.cleanup.confirm.messageAll',
						{total: total.value},
					)
				: t(
						'plugins.generic.scheduledTaskManager.logs.cleanup.confirm.message',
						{
							count: days,
						},
					),
			t('plugins.generic.scheduledTaskManager.logs.cleanup.confirm.warning'),
		].join(' '),
		modalStyle: 'negative',
		actions: [
			{
				label: t(
					'plugins.generic.scheduledTaskManager.logs.cleanup.confirm.action',
				),
				isWarnable: true,
				callback: (close) => {
					close();
					cleanup(days);
				},
			},
			{
				label: t('common.cancel'),
				callback: (close) => close(),
			},
		],
	});
}

async function cleanup(days) {
	// Sent as an empty string rather than omitted: the server reads that as "keep nothing back".
	cleanupQuery.olderThanDays = days === null ? '' : String(days);

	await sendCleanup();

	if (!cleanupResult.value) {
		return;
	}

	const {deleted, failed} = cleanupResult.value;

	if (failed) {
		notify(
			t('plugins.generic.scheduledTaskManager.logs.cleanup.partial', {
				count: deleted,
				failed,
			}),
			'warning',
		);
	} else {
		notify(
			deleted
				? t('plugins.generic.scheduledTaskManager.logs.cleanup.done', {
						count: deleted,
					})
				: t('plugins.generic.scheduledTaskManager.logs.cleanup.none'),
			deleted ? 'success' : 'notice',
		);
	}

	// Whatever page we were on no longer describes the same set of files.
	offset.value = 0;
	await load();

	props.onLogsChanged();
}

/**
 * Elapsed seconds as a compact figure. Units stay here rather than in the translation files:
 * they are the same in every language this plugin ships.
 */
function formatDuration(seconds) {
	if (seconds === null || seconds === undefined) {
		return '—';
	}

	if (seconds < 1) {
		return '<1s';
	}

	if (seconds < 60) {
		return `${seconds}s`;
	}

	if (seconds < 3600) {
		return `${Math.floor(seconds / 60)}m ${seconds % 60}s`;
	}

	return `${Math.floor(seconds / 3600)}h ${Math.floor((seconds % 3600) / 60)}m`;
}

onMounted(load);
</script>

<template>
	<PkpSideModalBody>
		<template #title>
			{{ t('plugins.generic.scheduledTaskManager.logs.title') }}
		</template>
		<template #description>
			{{ taskLabel }}
		</template>

		<div class="stmLogs">
			<div class="stmLogs__head">
				<p v-if="total" class="stmLogs__summary">
					{{
						t('plugins.generic.scheduledTaskManager.logs.range', {
							from: firstShown,
							to: lastShown,
							total,
						})
					}}
				</p>
				<div v-if="total || hasFilters" class="stmLogs__cleanup">
					<label class="stmLogs__field">
						<span>
							{{ t('plugins.generic.scheduledTaskManager.logs.cleanup.label') }}
						</span>
						<select
							v-model="cleanupDays"
							class="stmLogs__select"
							:disabled="isCleaning"
						>
							<option
								v-for="days in CLEANUP_OPTIONS"
								:key="String(days)"
								:value="days"
							>
								{{ cleanupOptionLabel(days) }}
							</option>
						</select>
					</label>
					<PkpButton
						:is-warnable="true"
						:is-disabled="isCleaning"
						@click="confirmCleanup"
					>
						<PkpSpinner v-if="isCleaning" />
						<template v-else>
							{{
								t('plugins.generic.scheduledTaskManager.logs.cleanup.action')
							}}
						</template>
					</PkpButton>
				</div>
			</div>

			<p v-if="directory" class="stmLogs__directory">
				{{
					t('plugins.generic.scheduledTaskManager.logs.directory', {
						directory,
					})
				}}
			</p>

			<div class="stmLogs__filters">
				<label class="stmLogs__field">
					<span>
						{{ t('plugins.generic.scheduledTaskManager.logs.filter.from') }}
					</span>
					<input v-model="draft.from" class="stmLogs__input" type="date" />
				</label>
				<label class="stmLogs__field">
					<span>
						{{ t('plugins.generic.scheduledTaskManager.logs.filter.to') }}
					</span>
					<input v-model="draft.to" class="stmLogs__input" type="date" />
				</label>
				<label class="stmLogs__field">
					<span>
						{{
							t('plugins.generic.scheduledTaskManager.logs.filter.minDuration')
						}}
					</span>
					<input
						v-model="draft.minDuration"
						class="stmLogs__input stmLogs__input--number"
						type="number"
						min="0"
					/>
				</label>
				<label class="stmLogs__field">
					<span>
						{{
							t('plugins.generic.scheduledTaskManager.logs.filter.maxDuration')
						}}
					</span>
					<input
						v-model="draft.maxDuration"
						class="stmLogs__input stmLogs__input--number"
						type="number"
						min="0"
					/>
				</label>
				<PkpButton :is-disabled="isLoading" @click="applyFilters">
					{{ t('plugins.generic.scheduledTaskManager.logs.filter.apply') }}
				</PkpButton>
				<PkpButton
					v-if="hasFilters"
					:is-disabled="isLoading"
					@click="clearFilters"
				>
					{{ t('plugins.generic.scheduledTaskManager.logs.filter.clear') }}
				</PkpButton>
			</div>

			<p v-if="truncated" class="stmLogs__note">
				{{
					t('plugins.generic.scheduledTaskManager.logs.truncated', {
						count: scanned,
					})
				}}
			</p>

			<PkpTable :sort-descriptor="sortDescriptor" @sort="applySort">
				<PkpTableHeader>
					<PkpTableColumn>
						{{ t('plugins.generic.scheduledTaskManager.logs.column.file') }}
					</PkpTableColumn>
					<PkpTableColumn id="started" :allows-sorting="true">
						{{ t('plugins.generic.scheduledTaskManager.logs.column.started') }}
					</PkpTableColumn>
					<PkpTableColumn id="duration" :allows-sorting="true">
						{{ t('plugins.generic.scheduledTaskManager.logs.column.duration') }}
					</PkpTableColumn>
					<PkpTableColumn>
						{{ t('plugins.generic.scheduledTaskManager.column.actions') }}
					</PkpTableColumn>
				</PkpTableHeader>

				<PkpTableBody>
					<template #no-content>
						<span v-if="isLoading" class="stmLogs__loading">
							<PkpSpinner />
							{{ t('common.loading') }}
						</span>
						<span v-else>
							{{ t('plugins.generic.scheduledTaskManager.logs.empty') }}
						</span>
					</template>

					<PkpTableRow v-for="file in files" :key="file.name">
						<PkpTableCell :is-row-header="true">
							<span class="stmLogs__file">{{ file.name }}</span>
						</PkpTableCell>
						<PkpTableCell>
							<template v-if="file.started">
								<span>{{ file.started.label }}</span>
								<span class="stmLogs__muted">{{ file.started.relative }}</span>
							</template>
							<template v-else>
								<span>{{ file.modified.label }}</span>
								<span class="stmLogs__muted">
									{{
										t(
											'plugins.generic.scheduledTaskManager.logs.startedUnknown',
										)
									}}
								</span>
							</template>
						</PkpTableCell>
						<PkpTableCell>
							<span>{{ formatDuration(file.duration) }}</span>
							<span v-if="file.lines" class="stmLogs__muted">
								{{
									t('plugins.generic.scheduledTaskManager.logs.entries', {
										count: file.lines,
									})
								}}
							</span>
						</PkpTableCell>
						<PkpTableCell>
							<PkpButton element="a" :href="file.downloadUrl">
								{{ t('plugins.generic.scheduledTaskManager.logs.download') }}
							</PkpButton>
						</PkpTableCell>
					</PkpTableRow>
				</PkpTableBody>
			</PkpTable>

			<div v-if="total" class="stmLogs__pager">
				<label class="stmLogs__field">
					<span>
						{{ t('plugins.generic.scheduledTaskManager.logs.perPage') }}
					</span>
					<select
						class="stmLogs__select"
						:value="perPage"
						:disabled="isLoading"
						@change="changePerPage"
					>
						<option v-for="size in PER_PAGE_OPTIONS" :key="size" :value="size">
							{{ size }}
						</option>
					</select>
				</label>

				<div class="stmLogs__pagerButtons">
					<PkpButton :is-disabled="!hasPrevious || isLoading" @click="previous">
						{{ t('plugins.generic.scheduledTaskManager.logs.previous') }}
					</PkpButton>
					<PkpButton :is-disabled="!hasNext || isLoading" @click="next">
						{{ t('plugins.generic.scheduledTaskManager.logs.next') }}
					</PkpButton>
				</div>
			</div>
		</div>
	</PkpSideModalBody>
</template>

<style scoped>
.stmLogs {
	padding: 0 2rem 2rem;
}

.stmLogs__summary {
	margin: 0 0 0.25rem;
}

.stmLogs__directory {
	margin: 0 0 1rem;
	font-size: 0.75rem;
	opacity: 0.7;
	overflow-wrap: anywhere;
}

.stmLogs__file {
	font-family: monospace;
	overflow-wrap: anywhere;
}

.stmLogs__muted {
	display: block;
	font-size: 0.75rem;
	opacity: 0.7;
}

.stmLogs__loading {
	display: inline-flex;
	align-items: center;
	gap: 0.5rem;
}

.stmLogs__pager {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 1rem;
	margin-top: 1rem;
}

.stmLogs__select {
	padding: 0.25rem 0.5rem;
	border: 1px solid #bbb;
	border-radius: 0.25rem;
	background: #fff;
	font-size: 0.875rem;
}

.stmLogs__pagerButtons {
	display: flex;
	gap: 0.5rem;
}

/* Summary on the left, cleanup on the right, so the destructive control has a fixed home. */
.stmLogs__head {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 0.75rem;
}

.stmLogs__cleanup,
.stmLogs__filters {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 0.75rem;
}

.stmLogs__filters {
	margin-bottom: 1rem;
	padding: 0.75rem 1rem;
	border: 1px solid #ddd;
	border-radius: 0.25rem;
	background: rgba(0, 0, 0, 0.02);
}

.stmLogs__field {
	display: inline-flex;
	align-items: center;
	gap: 0.5rem;
	font-size: 0.875rem;
}

.stmLogs__input {
	padding: 0.25rem 0.5rem;
	border: 1px solid #bbb;
	border-radius: 0.25rem;
	background: #fff;
	font-size: 0.875rem;
}

.stmLogs__input--number {
	width: 6rem;
}

/* Says what the answer was drawn from when the scan stopped short of the whole set. */
.stmLogs__note {
	margin: 0 0 1rem;
	font-size: 0.8125rem;
	opacity: 0.8;
}
</style>
