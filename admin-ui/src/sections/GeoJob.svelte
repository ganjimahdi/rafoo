<script lang="ts">
	import Notice from '../components/Notice.svelte';
	import { percent as pct, t } from '../lib/i18n';
	import type { BackupJob } from '../lib/types';

	interface Props {
		job: BackupJob;
		title: string;
	}

	let { job, title }: Props = $props();

	const value = $derived(job.progress.total ? Math.min(100, Math.round((job.progress.done / job.progress.total) * 100)) : 0);

	function label(step: string): string {
		const key = 'backup_step_' + step.replace(/^remote-/, '');
		const text = t(key);
		return text === key ? step : text;
	}

	function reason(code: string): string {
		const bare = code.replace(/^remote: /, '');
		const key = 'backup_error_' + bare;
		const text = t(key);
		return text === key ? bare : text;
	}
</script>

{#if job.status === 'running'}
	<div class="rf-job" aria-live="polite">
		<span>{title} · {label(job.phase || job.step)}</span>
		{#if job.progress.total}
			<progress max="100" {value} aria-label={title}></progress>
			<span class="rf-subtle rf-small">{pct(value)}</span>
		{:else}
			<progress aria-label={title}></progress>
		{/if}
	</div>
{:else if job.status === 'failed' && job.error !== 'cancelled'}
	<Notice tone="danger">{t('geo_seed_failed', reason(job.error))}</Notice>
{:else if job.status === 'stalled'}
	<Notice tone="warning">{t('backup_stalled')}</Notice>
{/if}

<style>
	.rf-job {
		display: flex;
		flex-direction: column;
		gap: 8px;
	}
	.rf-job progress {
		inline-size: 100%;
		block-size: 8px;
		accent-color: var(--rf-l6);
	}
</style>
