<!--
  @file resources/js/Components/StmTaskFailureModal.vue

  Copyright (c) 2026 Touhidur Rahman
  Distributed under the GNU GPL v3. For full terms see the file LICENSE.
-->

<script setup>
import {computed, onMounted} from 'vue';
import {formatDuration} from '../formatDuration.js';
import {describeOrigin} from '../describeOrigin.js';

const {useLocalize} = pkp.modules.useLocalize;
const {useFetch} = pkp.modules.useFetch;

const props = defineProps({
	taskName: {type: String, required: true},
	taskLabel: {type: String, required: true},
	failureApiUrl: {type: String, required: true},
});

const {t} = useLocalize();

const {data, isLoading, fetch} = useFetch(props.failureApiUrl, {
	query: {task: props.taskName},
});

const failure = computed(() => data.value?.failure ?? null);

const origin = computed(() =>
	failure.value ? describeOrigin(t, failure.value.origin) : null,
);

const entries = computed(() => failure.value?.log?.entries ?? []);

onMounted(fetch);
</script>

<template>
	<PkpSideModalBody>
		<template #title>
			{{ t('plugins.generic.scheduledTaskManager.failure.title') }}
		</template>
		<template #description>
			{{ taskLabel }}
		</template>

		<div class="stmFailure">
			<p v-if="isLoading || !data" class="stmFailure__loading">
				<PkpSpinner />
				{{ t('common.loading') }}
			</p>

			<p v-else-if="!failure">
				{{ t('plugins.generic.scheduledTaskManager.failure.none') }}
			</p>

			<template v-else>
				<dl class="stmFailure__facts">
					<dt>
						{{ t('plugins.generic.scheduledTaskManager.failure.meta.when') }}
					</dt>
					<dd>
						{{ failure.at.label }}
						<span class="stmFailure__muted">({{ failure.at.relative }})</span>
					</dd>
					<template v-if="origin">
						<dt>
							{{
								t('plugins.generic.scheduledTaskManager.failure.meta.origin')
							}}
						</dt>
						<dd :title="origin.title">{{ origin.text }}</dd>
					</template>
					<template v-if="failure.runtime !== null">
						<dt>
							{{
								t('plugins.generic.scheduledTaskManager.failure.meta.runtime')
							}}
						</dt>
						<dd>{{ formatDuration(failure.runtime) }}</dd>
					</template>
				</dl>

				<template v-if="failure.kind === 'exception' && failure.exception">
					<p class="stmFailure__lead">
						{{ t('plugins.generic.scheduledTaskManager.failure.exception') }}
					</p>
					<p class="stmFailure__message">
						<code>{{ failure.exception.class }}</code>
						{{ failure.exception.message }}
					</p>
					<p class="stmFailure__muted">
						{{
							t('plugins.generic.scheduledTaskManager.failure.where', {
								file: failure.exception.file,
								line: failure.exception.line,
							})
						}}
					</p>
					<p
						v-for="(cause, index) in failure.exception.previous"
						:key="index"
						class="stmFailure__cause"
					>
						<span class="stmFailure__label">
							{{
								`${t('plugins.generic.scheduledTaskManager.failure.cause')} `
							}}
						</span>
						<code>{{ cause.class }}</code>
						{{ cause.message }}
						<span class="stmFailure__muted">
							{{
								t('plugins.generic.scheduledTaskManager.failure.where', {
									file: cause.file,
									line: cause.line,
								})
							}}
						</span>
					</p>

					<h3 class="stmFailure__heading">
						{{ t('plugins.generic.scheduledTaskManager.failure.trace') }}
					</h3>
					<pre class="stmFailure__pre">{{ failure.exception.trace }}</pre>
					<p v-if="failure.exception.traceTruncated" class="stmFailure__muted">
						{{
							t('plugins.generic.scheduledTaskManager.failure.traceTruncated')
						}}
					</p>
				</template>

				<template v-else>
					<p class="stmFailure__lead">
						{{ t('plugins.generic.scheduledTaskManager.failure.reported') }}
					</p>
					<p v-if="!failure.log">
						{{ t('plugins.generic.scheduledTaskManager.failure.noLog') }}
					</p>
					<p v-else-if="!entries.length">
						{{ t('plugins.generic.scheduledTaskManager.failure.noEntries') }}
					</p>
				</template>

				<template v-if="entries.length">
					<h3 class="stmFailure__heading">
						{{ t('plugins.generic.scheduledTaskManager.failure.entries') }}
					</h3>
					<p v-if="failure.log.truncated" class="stmFailure__muted">
						{{
							t('plugins.generic.scheduledTaskManager.failure.entriesTruncated')
						}}
					</p>
					<pre class="stmFailure__pre">{{ entries.join('\n') }}</pre>
				</template>

				<p v-if="failure.log">
					<a v-if="failure.log.downloadUrl" :href="failure.log.downloadUrl">
						{{
							t('plugins.generic.scheduledTaskManager.failure.download', {
								file: failure.log.file,
							})
						}}
					</a>
					<span v-else class="stmFailure__muted">
						{{
							t('plugins.generic.scheduledTaskManager.failure.logGone', {
								file: failure.log.file,
							})
						}}
					</span>
				</p>
			</template>
		</div>
	</PkpSideModalBody>
</template>

<style scoped>
/* The same gutter as the logs modal, so the two read as one family. */
.stmFailure {
	padding: 0 2rem 2rem;
	font-size: 0.875rem;
	line-height: 1.5;
}

.stmFailure__loading {
	display: flex;
	align-items: center;
	gap: 0.5rem;
}

.stmFailure__facts {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 0.25rem 1rem;
	margin: 0 0 1rem;
}

.stmFailure__facts dt {
	font-weight: 700;
}

.stmFailure__facts dd {
	margin: 0;
}

.stmFailure__lead {
	font-weight: 700;
}

.stmFailure__message {
	color: #a3161a;
	overflow-wrap: anywhere;
}

.stmFailure__cause {
	overflow-wrap: anywhere;
}

/* The space after it is part of the text: the template's line break before the class name is
   condensed away, and a margin would space it for the eye but not for a screen reader. */
.stmFailure__label {
	font-weight: 700;
}

.stmFailure__muted {
	font-size: 0.8125rem;
	opacity: 0.75;
	overflow-wrap: anywhere;
}

.stmFailure__heading {
	margin: 1.25rem 0 0.5rem;
	font-size: 0.875rem;
	font-weight: 700;
}

/* Scrolls rather than wrapping: a trace frame reads as one line or not at all. */
.stmFailure__pre {
	max-height: 24rem;
	margin: 0 0 0.5rem;
	padding: 0.75rem;
	border: 1px solid #d6d6d6;
	border-radius: 0.25rem;
	background: #f7f7f7;
	font-family: monospace;
	font-size: 0.75rem;
	overflow: auto;
	white-space: pre;
}
</style>
