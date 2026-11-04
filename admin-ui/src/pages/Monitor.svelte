<script lang="ts">
	import { onMount } from 'svelte';
	import Badge from '../components/Badge.svelte';
	import Button from '../components/Button.svelte';
	import Card from '../components/Card.svelte';
	import Notice from '../components/Notice.svelte';
	import Segmented from '../components/Segmented.svelte';
	import { api } from '../lib/api';
	import { boot } from '../lib/boot';
	import { ago, date, day, help, num, percent, t } from '../lib/i18n';
	import type { PeerStatus, PeerWatcher } from '../lib/types';

	const SLOTS = 5;

	let status = $state.raw<PeerStatus | null>(null);
	let busy = $state('');
	let error = $state('');
	let interval = $state<'60' | '300'>('300');
	let timer: ReturnType<typeof setTimeout> | undefined;

	const intervals = [
		{ value: '300' as const, label: t('peers_every_5') },
		{ value: '60' as const, label: t('peers_every_1') }
	];

	const regions = (() => {
		try {
			return new Intl.DisplayNames([boot.locale.replace('_', '-')], { type: 'region' });
		} catch {
			return null;
		}
	})();

	function region(cc: string): string {
		try {
			return (cc && regions?.of(cc)) || cc;
		} catch {
			return cc;
		}
	}

	function place(w: PeerWatcher): string {
		const parts = [region(w.country), w.network].filter(Boolean);
		return parts.length ? parts.join(' · ') : t('peers_unknown_place');
	}

	async function run(path: string, method: 'GET' | 'POST' = 'GET', body?: Record<string, unknown>) {
		busy = path;
		error = '';
		try {
			status = await api<PeerStatus>(path, method, body);
			interval = status.interval === 60 ? '60' : '300';
		} catch (e) {
			error = e instanceof Error ? e.message : t('error_generic');
		}
		busy = '';
	}

	function poll() {
		clearTimeout(timer);
		timer = setTimeout(async () => {
			if (document.visibilityState === 'visible' && !busy) {
				try {
					status = await api<PeerStatus>('peers');
				} catch {
					error = '';
				}
			}
			poll();
		}, 60_000);
	}

	onMount(() => {
		run('peers');
		poll();
		return () => clearTimeout(timer);
	});

	function leave() {
		if (window.confirm(t('peers_leave_confirm'))) run('peers/leave', 'POST');
	}

	const remote = $derived(status?.remote ?? null);
	const watchers = $derived(remote?.watchers ?? []);
	const inside = $derived(watchers.filter((w) => w.scope === 'ir').slice(0, SLOTS));
	const outside = $derived(watchers.filter((w) => w.scope === 'intl').slice(0, SLOTS));
	const outage = $derived(remote?.intl === 'down');

	type Row = { w: PeerWatcher | null };
	function slots(list: PeerWatcher[]): Row[] {
		const rows: Row[] = list.map((w) => ({ w }));
		while (rows.length < SLOTS) rows.push({ w: null });
		return rows;
	}

	const days = $derived.by(() => {
		const map = new Map<string, { ir?: number; intl?: number }>();
		for (const d of remote?.days ?? []) {
			const row = map.get(d.day) ?? {};
			row[d.scope] = d.checks ? Math.round((1000 * d.ok) / d.checks) / 10 : undefined;
			map.set(d.day, row);
		}
		return [...map.entries()].map(([day, v]) => ({ day, ...v }));
	});

	function rate(w: PeerWatcher): string {
		return w.checks_today ? percent(Math.round((1000 * w.ok_today) / w.checks_today) / 10) : '—';
	}
</script>

{#snippet points(title: string, list: PeerWatcher[], scope: 'ir' | 'intl')}
	{@const reach = list.filter((w) => w.ok).length}
	<Card {title} help={help('card_peers_points')} description={list.length ? t(scope === 'ir' ? 'peers_inside_sum' : 'peers_outside_sum', reach, list.length) : t('peers_waiting_help')}>
		<ul class="rf-points">
			{#each slots(list) as row, i (i)}
				<li>
					{#if row.w}
						{@const w = row.w}
						<div class="rf-point-place">
							<strong>{place(w)}</strong>
							<span class="rf-small rf-subtle">{ago(Date.parse(w.at) / 1000)} · {t('peers_today', rate(w))}</span>
						</div>
						<div class="rf-point-state">
							{#if scope === 'intl' && outage && !w.ok}
								<Badge tone="warning">{t('peers_paused')}</Badge>
							{:else if w.ok}
								<Badge tone="success">{t('peers_reachable')}</Badge>
							{:else}
								<Badge tone="danger">{t('peers_unreachable')}</Badge>
							{/if}
							<span class="rf-small rf-subtle">{w.ok ? t('milliseconds', num(w.ms)) : ''}</span>
						</div>
					{:else}
						<div class="rf-point-place"><span class="rf-subtle">{t('peers_waiting')}</span></div>
						<div class="rf-point-state"><Badge>—</Badge></div>
					{/if}
				</li>
			{/each}
		</ul>
	</Card>
{/snippet}

<div class="rf-stack">
	{#if error}<Notice tone="danger" live>{error}</Notice>{/if}

	{#if status === null}
		<p class="rf-subtle">{t('loading')}</p>
	{:else if !status.member}
		<Card title={t('peers_title')} help={help('card_peers')} description={t('peers_intro')}>
			<ul class="rf-promise">
				<li>{t('peers_point_1')}</li>
				<li>{t('peers_point_2')}</li>
				<li>{t('peers_point_3')}</li>
			</ul>
			{#if !status.ready}<Notice tone="warning">{t('peers_no_sodium')}</Notice>{/if}
			<div class="rf-join">
				<div class="rf-join-interval">
					<span class="rf-small">{t('peers_interval')}</span>
					<Segmented name="rafoo-peer-interval" label={t('peers_interval')} options={intervals} value={interval} onchange={(v) => (interval = v)} />
				</div>
				<Button variant="primary" busy={busy === 'peers/join'} disabled={!status.ready} onclick={() => run('peers/join', 'POST', { interval: Number(interval) })}>
					{t('peers_join')}
				</Button>
			</div>
		</Card>
	{:else}
		<Card title={t('peers_title')} help={help('card_peers')} description={status.joined_at ? t('peers_joined', date(status.joined_at)) : ''}>
			{#snippet actions()}
				<Button size="sm" busy={busy === 'peers/check'} onclick={() => run('peers/check', 'POST')}>{t('peers_check_now')}</Button>
			{/snippet}
			<div class="rf-summary">
				{#if status.country}
					<p class="rf-small">
						{status.network
							? t('peers_location', region(status.country), status.network)
							: region(status.country)}
					</p>
				{/if}
				{#if remote}
					<p class="rf-small rf-subtle">{t('peers_network_size', remote.network_size.members, remote.network_size.iran, remote.network_size.abroad)}</p>
				{/if}
			</div>
			{#if status.error}<Notice tone="warning">{t('peers_error_status')}</Notice>{/if}
			{#if status.offline && status.scope === 'ir'}<Notice tone="info">{t('peers_outage_inside')}</Notice>{/if}
			{#if status.sentinel}<Notice tone="info">{t('peers_sentinel')}</Notice>{/if}
		</Card>

		<div class="rf-grid">
			{@render points(t('peers_inside'), inside, 'ir')}
			{@render points(t('peers_outside'), outside, 'intl')}
		</div>

		{#if days.length}
			<Card title={t('peers_days')} help={help('card_peers_days')}>
				<div class="rf-table-wrap">
					<table class="rf-table">
						<thead>
							<tr><th scope="col"></th><th scope="col">{t('peers_day_inside')}</th><th scope="col">{t('peers_day_outside')}</th></tr>
						</thead>
						<tbody>
							{#each days as d (d.day)}
								<tr>
									<td class="rf-small">{day(d.day)}</td>
									<td>{d.ir === undefined ? '—' : percent(d.ir)}</td>
									<td>{d.intl === undefined ? '—' : percent(d.intl)}</td>
								</tr>
							{/each}
						</tbody>
					</table>
				</div>
			</Card>
		{/if}

		<div class="rf-grid">
			<Card title={t('peers_settings')} help={help('card_peers_settings')}>
				<div class="rf-join-interval">
					<span class="rf-small">{t('peers_interval')}</span>
					<Segmented
						name="rafoo-peer-interval"
						label={t('peers_interval')}
						options={intervals}
						value={interval}
						disabled={busy !== ''}
						onchange={(v) => {
							interval = v;
							run('peers/interval', 'POST', { interval: Number(v) });
						}}
					/>
				</div>
				<div><Button variant="danger" size="sm" busy={busy === 'peers/leave'} onclick={leave}>{t('peers_leave')}</Button></div>
			</Card>
			<Card title={t('peers_contribution')} help={help('card_peers_contribution')}>
				<p>{t('peers_watching', status.watching)}</p>
				{#if status.checked}<p class="rf-small rf-subtle">{t('last_check', ago(status.checked, status.now))}</p>{/if}
				{#if status.pending}<p class="rf-small rf-subtle">{t('peers_pending', status.pending)}</p>{/if}
				{#if !status.detached}<Notice tone="info">{t('peers_no_detach')}</Notice>{/if}
			</Card>
		</div>
	{/if}

	<Card title={t('peers_guide_title')} help={help('card_peers_guide')}>
		<ul class="rf-promise">
			<li>{t('peers_guide_1')}</li>
			<li>{t('peers_guide_2')}</li>
			<li>{t('peers_guide_3')}</li>
			<li>{t('peers_guide_4')}</li>
		</ul>
	</Card>
</div>

<style>
	.rf-grid {
		display: grid;
		grid-template-columns: repeat(2, minmax(0, 1fr));
		gap: 16px;
		align-items: start;
	}
	.rf-promise {
		display: flex;
		flex-direction: column;
		gap: 8px;
		margin: 0;
		padding-inline-start: 18px;
		color: var(--rf-l4);
		line-height: 1.8;
	}
	.rf-join {
		display: flex;
		flex-wrap: wrap;
		align-items: flex-end;
		justify-content: space-between;
		gap: 12px;
	}
	.rf-join-interval {
		display: flex;
		flex-direction: column;
		align-items: flex-start;
		gap: 6px;
	}
	.rf-summary {
		display: flex;
		flex-direction: column;
		gap: 4px;
	}
	.rf-points {
		display: flex;
		flex-direction: column;
		margin: 0;
		padding: 0;
		list-style: none;
	}
	.rf-points li {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
		padding-block: 10px;
	}
	.rf-points li + li {
		border-block-start: 1px solid var(--rf-l2);
	}
	.rf-point-place {
		display: flex;
		flex-direction: column;
		min-inline-size: 0;
	}
	.rf-point-place strong {
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}
	.rf-point-state {
		display: flex;
		flex-direction: column;
		align-items: flex-end;
		gap: 2px;
		flex: none;
	}
	@media (max-width: 960px) {
		.rf-grid {
			grid-template-columns: 1fr;
		}
	}
</style>
