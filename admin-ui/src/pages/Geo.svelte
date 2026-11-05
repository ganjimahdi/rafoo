<script lang="ts">
	import { onDestroy, onMount } from 'svelte';
	import Badge from '../components/Badge.svelte';
	import Button from '../components/Button.svelte';
	import Card from '../components/Card.svelte';
	import Notice from '../components/Notice.svelte';
	import { api } from '../lib/api';
	import { geo, hostOf, message } from '../lib/geo';
	import { ago, duration, help, t } from '../lib/i18n';
	import type { GeoStatus } from '../lib/types';
	import GeoHosts from '../sections/GeoHosts.svelte';
	import GeoJob from '../sections/GeoJob.svelte';
	import GeoRouting from '../sections/GeoRouting.svelte';
	import GeoSeo from '../sections/GeoSeo.svelte';
	import GeoStart from '../sections/GeoStart.svelte';

	let status = $state.raw<GeoStatus | null>(null);
	let error = $state('');
	let busy = $state('');
	let leaving = $state(false);
	let timer: ReturnType<typeof setTimeout> | null = null;
	let alive = true;

	const working = $derived(status?.job?.status === 'running' && (status.job.kind === 'send' || status.job.kind === 'receive'));
	const self = $derived(status?.nodes.find((node) => node.self) ?? null);
	const primary = $derived(status?.nodes.find((node) => node.primary) ?? null);

	function update(next: GeoStatus) {
		status = next;
		schedule();
	}

	function schedule() {
		if (timer) clearTimeout(timer);
		timer = null;
		if (!alive || !status || status.role === '') return;
		timer = setTimeout(tick, working ? 1500 : 20000);
	}

	async function tick() {
		timer = null;
		try {
			if (working) await api('backup/step', 'POST');
			update(await api<GeoStatus>('geo'));
		} catch {
			if (alive) timer = setTimeout(tick, 10000);
		}
	}

	async function load() {
		error = '';
		try {
			update(await api<GeoStatus>('geo'));
		} catch (e) {
			error = message(e);
		}
	}

	async function act(id: string, task: () => Promise<GeoStatus>) {
		busy = id;
		error = '';
		try {
			update(await task());
			leaving = false;
		} catch (e) {
			error = message(e);
		}
		busy = '';
	}

	onMount(load);

	onDestroy(() => {
		alive = false;
		if (timer) clearTimeout(timer);
	});
</script>

<div class="rf-stack">
	<Card title={t('geo_title')} help={help('card_geo')} description={t('geo_intro')}>
		{#snippet actions()}<Badge tone="info">{t('geo_beta')}</Badge>{/snippet}
		<p class="rf-subtle rf-small">{t('geo_beta_note')}</p>
		{#if error}<Notice tone="danger" live>{error}</Notice>{/if}
		{#if status === null}
			{#if !error}<p class="rf-subtle">{t('loading')}</p>{/if}
		{:else if status.multisite}
			<Notice tone="warning">{t('geo_multisite')}</Notice>
		{:else if status.role === ''}
			<ul class="rf-points">
				<li>{t('geo_point_1')}</li>
				<li>{t('geo_point_2')}</li>
				<li>{t('geo_point_3')}</li>
				<li>{t('geo_point_4')}</li>
			</ul>
		{/if}
	</Card>

	{#if status && !status.multisite}
		{#if status.role === '' || status.role === 'joining'}
			<GeoStart {status} onupdate={update} />
		{:else if status.role === 'copy'}
			<Card title={t('geo_copy_title')} help={help('card_geo_copy')} description={t('geo_copy_desc', status.host)}>
				{#if status.job && status.job.kind === 'receive'}
					<GeoJob job={status.job} title={t('geo_receiving')} />
				{/if}
				{#if self?.status === 'seeding' && !working}
					<p class="rf-small">{t('geo_copy_waiting')}</p>
				{/if}
				{#if status.sync}
					<dl class="rf-facts">
						<div><dt>{t('geo_copy_main')}</dt><dd class="rf-mono">{primary ? hostOf(primary.url) : '—'}</dd></div>
						<div><dt>{t('geo_copy_contact')}</dt><dd>{ago(status.sync.contact)}</dd></div>
						<div><dt>{t('geo_copy_synced')}</dt><dd>{ago(status.sync.ok_at)}</dd></div>
						{#if status.sync.lag > 0}<div><dt>{t('geo_copy_took')}</dt><dd>{status.sync.lag < 60 ? t('geo_seconds', status.sync.lag) : duration(status.sync.lag)}</dd></div>{/if}
					</dl>
					{#if status.sync.busy}<p class="rf-subtle rf-small">{t('geo_sync_busy')}{status.sync.table ? ' · ' + status.sync.table : ''}</p>{/if}
					{#if status.sync.error}<Notice tone="danger">{t('geo_sync_error', status.sync.error)}</Notice>{/if}
				{/if}
				<Notice tone="info">{t('geo_copy_manage')}</Notice>
				<div class="rf-row">
					<Button size="sm" busy={busy === 'sync'} disabled={busy !== '' || working} onclick={() => act('sync', () => geo('sync'))}>{t('geo_sync_now')}</Button>
				</div>
			</Card>
		{:else}
			<GeoHosts {status} onupdate={update} />
			<GeoRouting {status} onupdate={update} />
			<GeoSeo {status} onupdate={update} />
		{/if}

		{#if status.role === 'primary' || status.role === 'copy'}
			<Card title={t('geo_leave_title')} help={help('card_geo_leave')} description={status.role === 'primary' ? t('geo_leave_primary') : t('geo_leave_copy')}>
				{#if leaving}
					<Notice tone="warning">{status.role === 'primary' ? t('geo_leave_primary_warning') : t('geo_leave_copy_warning')}</Notice>
					<div class="rf-row">
						<Button variant="danger" busy={busy === 'leave'} disabled={busy !== ''} onclick={() => act('leave', () => geo('leave'))}>{t('geo_leave_confirm')}</Button>
						<Button variant="ghost" onclick={() => (leaving = false)}>{t('setup_back')}</Button>
					</div>
				{:else}
					<div class="rf-row">
						<Button variant="ghost" disabled={working} onclick={() => (leaving = true)}>{status.role === 'primary' ? t('geo_leave_primary_button') : t('geo_leave_copy_button')}</Button>
					</div>
				{/if}
			</Card>
		{/if}
	{/if}
</div>

<style>
	.rf-points {
		display: flex;
		flex-direction: column;
		gap: 6px;
		margin: 0;
		padding-inline-start: 20px;
	}
	.rf-points li {
		margin: 0;
	}
	.rf-facts {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
		gap: 8px 16px;
		margin: 0;
	}
	.rf-facts div {
		display: flex;
		flex-direction: column;
	}
	.rf-facts dt {
		color: var(--rf-l4);
		font-size: var(--rf-fs-xs);
	}
	.rf-facts dd {
		margin: 0;
		font-size: var(--rf-fs-sm);
	}
</style>
