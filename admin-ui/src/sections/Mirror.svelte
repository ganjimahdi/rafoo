<script lang="ts">
	import { onMount } from 'svelte';
	import Badge from '../components/Badge.svelte';
	import Button from '../components/Button.svelte';
	import Card from '../components/Card.svelte';
	import Notice from '../components/Notice.svelte';
	import { api } from '../lib/api';
	import { ago, t } from '../lib/i18n';
	import type { UpdatesReport } from '../lib/types';

	let report = $state.raw<UpdatesReport | null>(null);
	let busy = $state(false);
	let error = $state('');

	async function load(refresh = false) {
		busy = true;
		error = '';
		try {
			report = await api<UpdatesReport>(refresh ? 'updates/refresh' : 'updates', refresh ? 'POST' : 'GET');
		} catch (e) {
			error = e instanceof Error ? e.message : t('error_generic');
		}
		busy = false;
	}

	onMount(() => {
		load();
	});
</script>

<Card title={t('updates_title')} description={t('updates_help')}>
	{#snippet actions()}
		<Button onclick={() => load(true)} {busy} disabled={report !== null && !report.available}>{t('updates_refresh')}</Button>
	{/snippet}

	{#if error}<Notice tone="danger" live>{error}</Notice>{/if}

	{#if report === null}
		<p class="rf-subtle">{t('loading')}</p>
	{:else if !report.available}
		<Notice tone="info">{t('updates_index_unavailable')}</Notice>
	{:else}
		{#if report.checked_at}<p class="rf-subtle rf-small">{t('last_check', ago(report.checked_at))}</p>{/if}
		<div class="rf-table-wrap">
			<table class="rf-table">
				<caption class="rf-sr">{t('updates_title')}</caption>
				<thead>
					<tr>
						<th scope="col">{t('col_name')}</th>
						<th scope="col">{t('col_installed')}</th>
						<th scope="col">{t('col_mirror')}</th>
					</tr>
				</thead>
				<tbody>
					{#each report.items as item (item.type + item.slug)}
						<tr>
							<td>
								<span>{item.name}</span>
								<span class="rf-subtle rf-small"> · {t('kind_' + item.type)}</span>
							</td>
							<td class="rf-mono">{item.installed}</td>
							<td>
								{#if !item.in_mirror}
									<Badge>{t('not_in_mirror')}</Badge>
								{:else if item.latest && item.latest !== item.installed}
									<Badge tone="info">{t('update_available', item.latest)}</Badge>
								{:else}
									<Badge tone="success">{t('up_to_date')}</Badge>
								{/if}
							</td>
						</tr>
					{/each}
				</tbody>
			</table>
		</div>
	{/if}
</Card>
