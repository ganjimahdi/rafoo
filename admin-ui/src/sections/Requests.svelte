<script lang="ts">
	import { onMount } from 'svelte';
	import Badge from '../components/Badge.svelte';
	import Button from '../components/Button.svelte';
	import Card from '../components/Card.svelte';
	import Notice from '../components/Notice.svelte';
	import { api } from '../lib/api';
	import { ago, num, t } from '../lib/i18n';
	import type { RequestsReport } from '../lib/types';

	const LIMIT = 20;

	let report = $state.raw<RequestsReport | null>(null);
	let busy = $state(false);
	let error = $state('');
	let all = $state(false);

	const rows = $derived(report ? (all ? report.items : report.items.slice(0, LIMIT)) : []);

	async function run(task: () => Promise<RequestsReport>) {
		busy = true;
		error = '';
		try {
			report = await task();
		} catch (e) {
			error = e instanceof Error ? e.message : t('error_generic');
		}
		busy = false;
	}

	onMount(() => {
		run(() => api<RequestsReport>('requests'));
	});
</script>

<Card title={t('requests_title')} description={t('requests_help')}>
	{#snippet actions()}
		<Button onclick={() => run(() => api<RequestsReport>('requests/clear', 'POST'))} {busy} disabled={!report || report.items.length === 0}>
			{t('requests_clear')}
		</Button>
	{/snippet}

	{#if error}<Notice tone="danger" live>{error}</Notice>{/if}

	{#if report === null}
		<p class="rf-subtle">{t('loading')}</p>
	{:else}
		{#if !report.enabled}<Notice tone="info">{t('requests_off')}</Notice>{/if}
		{#if report.items.length === 0}
			<p class="rf-subtle">{t('requests_empty')}</p>
		{:else}
			<div class="rf-table-wrap">
				<table class="rf-table rf-requests">
					<caption class="rf-sr">{t('requests_title')}</caption>
					<thead>
						<tr>
							<th scope="col">{t('col_source')}</th>
							<th scope="col">{t('col_host')}</th>
							<th scope="col">{t('col_requests')}</th>
							<th scope="col">{t('col_skipped')}</th>
							<th scope="col">{t('col_failed')}</th>
							<th scope="col">{t('col_time')}</th>
							<th scope="col">{t('col_last')}</th>
						</tr>
					</thead>
					<tbody>
						{#each rows as row (row.origin + '|' + row.host)}
							<tr>
								<td>{row.name}</td>
								<td>
									<div class="rf-host">
										<span class="rf-mono">{row.host}</span>
										{#if row.foreign}<Badge>{t('host_foreign')}</Badge>{/if}
									</div>
								</td>
								<td>{num(row.count)}</td>
								<td>{row.refused ? num(row.refused) : '—'}</td>
								<td>
									{#if row.failed}<Badge tone="danger">{num(row.failed)}</Badge>{:else}—{/if}
								</td>
								<td class="rf-small">{row.average ? t('milliseconds', row.average) : '—'}</td>
								<td class="rf-small rf-subtle">{ago(row.last)}</td>
							</tr>
						{/each}
					</tbody>
				</table>
			</div>
			{#if !all && report.items.length > LIMIT}
				<div>
					<Button size="sm" variant="ghost" onclick={() => (all = true)}>{t('requests_show_all', num(report.items.length))}</Button>
				</div>
			{/if}
		{/if}
	{/if}
</Card>

<style>
	.rf-host {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 6px;
	}
	.rf-requests td:nth-child(n + 3) {
		white-space: nowrap;
	}
</style>
