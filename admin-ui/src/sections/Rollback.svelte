<script lang="ts">
	import { onMount } from 'svelte';
	import Badge from '../components/Badge.svelte';
	import Button from '../components/Button.svelte';
	import Card from '../components/Card.svelte';
	import Notice from '../components/Notice.svelte';
	import { api } from '../lib/api';
	import { bytes, date, help, t } from '../lib/i18n';
	import { store } from '../lib/store.svelte';
	import type { HealthReport, RollbackReport } from '../lib/types';

	let report = $state.raw<RollbackReport | null>(null);
	let busy = $state('');
	let error = $state('');
	let message = $state('');
	let health = $state.raw<HealthReport | null>(null);

	async function act(id: string, task: () => Promise<void>) {
		busy = id;
		error = '';
		message = '';
		try {
			await task();
		} catch (e) {
			error = e instanceof Error ? e.message : t('error_generic');
		}
		busy = '';
	}

	onMount(() => {
		act('load', async () => {
			report = await api<RollbackReport>('rollback');
		});
	});

	function restore(type: string, slug: string, version: string, name: string) {
		return act('restore' + type + slug + version, async () => {
			const result = await api<RollbackReport & HealthReport>('rollback/restore', 'POST', { type, slug, version });
			report = result;
			message = result.healthy ? t('rollback_done', name, version) : t('rollback_done_broken', name, version, result.reason);
			await store.refresh();
		});
	}

	function check() {
		return act('health', async () => {
			health = await api<HealthReport>('health', 'POST');
		});
	}
</script>

<Card title={t('rollback_title')} help={help('card_rollback')} description={t('rollback_help')}>
	{#snippet actions()}
		<Button onclick={check} busy={busy === 'health'} disabled={busy !== ''}>{t('health_check')}</Button>
	{/snippet}

	{#if health}
		<Notice tone={health.healthy ? 'success' : 'danger'} live>{health.healthy ? t('health_ok') : t('health_broken', health.reason)}</Notice>
	{/if}
	{#if message}<Notice tone="success" live>{message}</Notice>{/if}
	{#if error}<Notice tone="danger" live>{error}</Notice>{/if}

	{#if report === null}
		<p class="rf-subtle">{t('loading')}</p>
	{:else}
		{#if !report.available}
			<Notice tone="warning">{t('rollback_no_zip')}</Notice>
		{:else if !report.enabled}
			<Notice tone="info">{t('rollback_off')}</Notice>
		{/if}
		{#if report.items.length === 0}
			<p class="rf-subtle">{t('rollback_empty')}</p>
		{:else}
			<div class="rf-table-wrap">
				<table class="rf-table rf-rollback">
					<caption class="rf-sr">{t('rollback_title')}</caption>
					<thead>
						<tr>
							<th scope="col">{t('col_name')}</th>
							<th scope="col">{t('col_installed')}</th>
							<th scope="col">{t('col_previous')}</th>
						</tr>
					</thead>
					<tbody>
						{#each report.items as item (item.type + item.slug)}
							<tr>
								<td>
									<span>{item.name}</span>
									<span class="rf-subtle rf-small"> · {t('kind_' + item.type)}</span>
								</td>
								<td class="rf-mono">{item.installed || '—'}</td>
								<td>
									<ul class="rf-versions">
										{#each item.versions as version (version.version)}
											<li>
												<span class="rf-mono">{version.version}</span>
												{#if version.version === item.installed}<Badge tone="success">{t('rollback_current')}</Badge>{/if}
												<span class="rf-subtle rf-small">{date(version.at)} · {bytes(version.size)}</span>
												{#if version.version !== item.installed}
													<Button
														size="sm"
														variant="ghost"
														disabled={busy !== ''}
														busy={busy === 'restore' + item.type + item.slug + version.version}
														onclick={() => restore(item.type, item.slug, version.version, item.name)}
													>
														{t('rollback_use', version.version)}
													</Button>
												{/if}
											</li>
										{/each}
									</ul>
								</td>
							</tr>
						{/each}
					</tbody>
				</table>
			</div>
		{/if}
	{/if}
</Card>

<style>
	.rf-versions {
		display: flex;
		flex-direction: column;
		gap: 6px;
		margin: 0;
		padding: 0;
		list-style: none;
	}
	.rf-versions li {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 8px;
		margin: 0;
	}
</style>
