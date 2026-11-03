<script lang="ts">
	import { onMount } from 'svelte';
	import Badge from '../components/Badge.svelte';
	import Button from '../components/Button.svelte';
	import Card from '../components/Card.svelte';
	import Notice from '../components/Notice.svelte';
	import { bytes, num, t } from '../lib/i18n';
	import { store } from '../lib/store.svelte';
	import type { Asset } from '../lib/types';

	let busy = $state('');
	let error = $state('');
	let message = $state('');

	const assets = $derived(store.assets);

	onMount(() => {
		store.loadAssets().catch((e: unknown) => (error = e instanceof Error ? e.message : t('error_generic')));
	});

	async function act(id: string, task: () => Promise<void>, done = '') {
		busy = id;
		error = '';
		message = '';
		try {
			await task();
			message = done;
		} catch (e) {
			error = e instanceof Error ? e.message : t('error_generic');
		}
		busy = '';
	}

	function tone(asset: Asset): 'success' | 'warning' | 'danger' | 'info' | 'neutral' {
		if (asset.status === 'ready') return asset.verified ? 'success' : 'warning';
		if (asset.status === 'failed') return 'danger';
		if (asset.status === 'excluded') return 'neutral';
		return 'info';
	}

	function status(asset: Asset): string {
		if (asset.status === 'ready') return asset.verified ? t('file_ready') : t('file_unverified');
		return t('file_' + asset.status);
	}

	function verification(asset: Asset): string {
		return asset.verified ? t('verified_' + asset.verified) : '—';
	}

	function reason(asset: Asset): string {
		const key = 'reason_' + asset.error;
		const label = t(key);
		return label === key ? t('reason_other') : label;
	}

	function title(asset: Asset): string {
		if (asset.kind === 'font') return t('google_fonts');
		return asset.package ? asset.package + '@' + asset.version : asset.cdn;
	}

	function file(asset: Asset): string {
		const path = asset.origin.replace(/^https:\/\/[^/]+\//, '');
		if (asset.kind === 'font') {
			const families = new URLSearchParams(path.replace(/^[^?]*\?/, '')).getAll('family');
			return families.map((family) => family.split(':')[0]).join(', ') || path;
		}
		return path.split('/').pop() || path;
	}
</script>

<div class="rf-stack">
	<Card title={t('files_title')} description={t('files_help')}>
		{#snippet actions()}
			<Button onclick={() => act('scan', () => store.assetAction('scan'), t('scan_done'))} busy={busy === 'scan'} disabled={busy !== ''}>
				{t('scan_site')}
			</Button>
			<Button variant="primary" onclick={() => act('sync', () => store.assetAction('sync'), t('sync_done'))} busy={busy === 'sync'} disabled={busy !== ''}>
				{t('download_now')}
			</Button>
		{/snippet}

		{#if message}<Notice tone="success" live>{message}</Notice>{/if}
		{#if error}<Notice tone="danger" live>{error}</Notice>{/if}

		{#if assets === null}
			<p class="rf-subtle">{t('loading')}</p>
		{:else if assets.length === 0}
			<p class="rf-subtle">{t('files_empty')}</p>
		{:else}
			<div class="rf-table-wrap">
				<table class="rf-table">
					<caption class="rf-sr">{t('files_title')}</caption>
					<thead>
						<tr>
							<th scope="col">{t('col_file')}</th>
							<th scope="col">{t('col_status')}</th>
							<th scope="col">{t('col_verification')}</th>
							<th scope="col">{t('col_size')}</th>
							<th scope="col"><span class="rf-sr">{t('col_actions')}</span></th>
						</tr>
					</thead>
					<tbody>
						{#each assets as asset (asset.key)}
							<tr>
								<td>
									<div class="rf-file">
										<span class="rf-mono">{title(asset)}</span>
										<span class="rf-mono rf-subtle rf-small" title={asset.origin}>{file(asset)}</span>
									</div>
								</td>
								<td>
									<div class="rf-file">
										<Badge tone={tone(asset)}>{status(asset)}</Badge>
										{#if asset.status === 'failed' || (asset.error && asset.status !== 'ready')}
											<span class="rf-subtle rf-small">{reason(asset)}</span>
										{/if}
									</div>
								</td>
								<td class="rf-small">
									{verification(asset)}
									{#if asset.source === 'mirror'}<span class="rf-subtle"> · {t('from_mirror')}</span>{/if}
								</td>
								<td class="rf-small">
									{asset.size ? bytes(asset.size) : '—'}
									{#if asset.files > 1}<span class="rf-subtle"> · {t('files_count', num(asset.files))}</span>{/if}
								</td>
								<td>
									<div class="rf-actions">
										{#if asset.status === 'failed' || asset.status === 'pending'}
											<Button size="sm" variant="ghost" disabled={busy !== ''} busy={busy === 'retry' + asset.key} onclick={() => act('retry' + asset.key, () => store.assetAction('retry', { key: asset.key }))}>
												{t('retry')}
											</Button>
										{/if}
										<Button
											size="sm"
											variant="ghost"
											disabled={busy !== ''}
											busy={busy === 'exclude' + asset.key}
											aria-pressed={asset.status === 'excluded'}
											onclick={() => act('exclude' + asset.key, () => store.assetAction('exclude', { key: asset.key, excluded: asset.status !== 'excluded' }))}
										>
											{asset.status === 'excluded' ? t('include') : t('exclude')}
										</Button>
										<Button size="sm" variant="ghost" disabled={busy !== ''} busy={busy === 'remove' + asset.key} onclick={() => act('remove' + asset.key, () => store.assetAction('remove', { key: asset.key }))}>
											{t('remove')}
										</Button>
									</div>
								</td>
							</tr>
						{/each}
					</tbody>
				</table>
			</div>
		{/if}
	</Card>
</div>

<style>
	.rf-file {
		display: flex;
		flex-direction: column;
		align-items: flex-start;
		gap: 4px;
		min-inline-size: 0;
	}
	.rf-file .rf-mono {
		max-inline-size: 420px;
	}
	.rf-actions {
		display: flex;
		flex-wrap: wrap;
		justify-content: flex-end;
		gap: 4px;
	}
</style>
