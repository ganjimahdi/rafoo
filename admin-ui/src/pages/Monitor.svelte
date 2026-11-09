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
	import type { PeerSlot, PeerStatus } from '../lib/types';

	const IRAN = 3;
	const ABROAD = 2;
	const FAST = 3000;
	const FAST_FOR = 45000;
	const SLOW = 60000;

	let status = $state.raw<PeerStatus | null>(null);
	let busy = $state('');
	let error = $state('');
	let interval = $state<'60' | '300'>('300');
	let fastUntil = $state(0);
	let checking = $state<Record<string, number>>({});
	let now = $state(Date.now());
	let timer: ReturnType<typeof setTimeout> | undefined;
	let alive = true;

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

	function fast(ms = FAST_FOR) {
		fastUntil = Math.max(fastUntil, Date.now() + ms);
		poll(FAST);
	}

	function poll(delay?: number) {
		clearTimeout(timer);
		if (!alive) return;
		const wait = delay ?? (Date.now() < fastUntil ? FAST : SLOW);
		timer = setTimeout(async () => {
			now = Date.now();
			if (document.visibilityState === 'visible' && !busy && status?.member) {
				try {
					status = await api<PeerStatus>(Date.now() < fastUntil ? 'peers?fresh=1' : 'peers');
				} catch {
					error = '';
				}
			}
			poll();
		}, wait);
	}

	onMount(() => {
		(async () => {
			await run('peers');
			if (status?.member) {
				await run('peers/wake', 'POST');
				fast();
			} else {
				poll();
			}
		})();
		return () => {
			alive = false;
			clearTimeout(timer);
		};
	});

	async function join() {
		await run('peers/join', 'POST', { interval: Number(interval) });
		if (status?.member) fast(60000);
	}

	async function recheck(slot: PeerSlot) {
		checking = { ...checking, [slot.ref]: Date.now() };
		try {
			status = await api<PeerStatus>('peers/recheck', 'POST', { ref: slot.ref });
			error = '';
		} catch (e) {
			error = e instanceof Error ? e.message : t('error_generic');
		}
		fast(35000);
		setTimeout(() => {
			const next = { ...checking };
			delete next[slot.ref];
			checking = next;
		}, 35000);
	}

	function leave() {
		if (window.confirm(t('peers_leave_confirm'))) run('peers/leave', 'POST');
	}

	const remote = $derived(status?.remote ?? null);
	const outage = $derived(remote?.intl === 'down');

	function legacy(): PeerSlot[] {
		const list = remote?.watchers ?? [];
		const pick = (scope: 'ir' | 'intl', need: number, from: number): PeerSlot[] => {
			const rows = list.filter((w) => w.scope === scope).slice(0, need);
			return Array.from({ length: need }, (_, i) => {
				const w = rows[i];
				return {
					slot: from + i,
					scope,
					ref: '',
					state: w ? (w.ok ? 'ok' : 'down') : 'waiting',
					at: w ? Date.parse(w.at) / 1000 : 0,
					ms: w?.ms ?? 0,
					code: 0,
					checks_today: w?.checks_today ?? 0,
					ok_today: w?.ok_today ?? 0
				};
			});
		};
		return [...pick('ir', IRAN, 1), ...pick('intl', ABROAD, IRAN + 1)];
	}

	const slots = $derived<PeerSlot[]>(remote?.slots?.length ? remote.slots : legacy());
	const inside = $derived(slots.filter((s) => s.scope === 'ir'));
	const outside = $derived(slots.filter((s) => s.scope === 'intl'));
	const insideOk = $derived(inside.filter((s) => s.state === 'ok').length);
	const outsideOk = $derived(outside.filter((s) => s.state === 'ok').length);
	const filtered = $derived(inside.some((s) => s.state === 'filtered'));
	const known = $derived(slots.some((s) => s.state !== 'waiting'));
	function enough(list: PeerSlot[]): boolean | null {
		const seen = list.filter((s) => s.state !== 'waiting').length;
		if (seen === 0) return null;
		return list.filter((s) => s.state === 'ok').length >= Math.min(2, seen);
	}

	const verdict = $derived.by(() => {
		if (!known) return 'waiting';
		const iran = enough(inside);
		const abroad = enough(outside);
		if (filtered && !iran) return 'filtered';
		if (iran === null) return 'waiting';
		if (!iran) return 'iran';
		return abroad === false && !outage ? 'abroad' : 'ok';
	});

	function rate(s: PeerSlot): string {
		return s.checks_today ? percent(Math.round((1000 * s.ok_today) / s.checks_today) / 10) : '—';
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


</script>

{#snippet points(title: string, list: PeerSlot[], scope: 'ir' | 'intl')}
	{@const reach = list.filter((s) => s.state === 'ok').length}
	<Card {title} help={help(scope === 'ir' ? 'card_peers_inside' : 'card_peers_outside')} description={list.some((s) => s.state !== 'waiting') ? t(scope === 'ir' ? 'peers_inside_sum' : 'peers_outside_sum', reach, list.length) : t('peers_waiting_help')}>
		<ul class="rf-points">
			{#each list as slot, i (slot.slot)}
				{@const busyCheck = !!checking[slot.ref]}
				<li>
					<div class="rf-point-place">
						<strong>{t('peers_slot_' + (i + 1))}</strong>
						{#if slot.at > 0}
							<span class="rf-small rf-subtle">{ago(slot.at, now / 1000)} · {t('peers_today', rate(slot))}</span>
						{:else}
							<span class="rf-small rf-subtle">{slot.ref ? t('peers_first_soon') : t('peers_waiting')}</span>
						{/if}
					</div>
					<div class="rf-point-state">
						{#if busyCheck}
							<Badge tone="info">{t('peers_rechecking')}</Badge>
						{:else if slot.state === 'ok'}
							<Badge tone="success">{t('peers_reachable')}</Badge>
						{:else if slot.state === 'filtered'}
							<Badge tone="danger">{t('peers_filtered')}</Badge>
						{:else if slot.state === 'down' && scope === 'intl' && outage}
							<Badge tone="warning">{t('peers_paused')}</Badge>
						{:else if slot.state === 'down'}
							<Badge tone="danger">{t('peers_unreachable')}</Badge>
						{:else}
							<Badge>{t('peers_pending_check')}</Badge>
						{/if}
						{#if slot.state === 'ok' && !busyCheck}
							<span class="rf-small rf-subtle">{t('milliseconds', num(slot.ms))}</span>
						{:else if (slot.state === 'down' || slot.state === 'filtered') && slot.ref && !busyCheck}
							<Button size="sm" variant="ghost" busy={busy === 'recheck'} onclick={() => recheck(slot)}>{t('peers_recheck')}</Button>
						{/if}
					</div>
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
				<Button variant="primary" busy={busy === 'peers/join'} disabled={!status.ready} onclick={join}>
					{t('peers_join')}
				</Button>
			</div>
		</Card>
	{:else}
		<Card title={t('peers_title')} help={help('card_peers')} description={status.joined_at ? t('peers_joined', date(status.joined_at)) : ''}>
			<div class="rf-verdict" aria-live="polite">
				<Badge tone={verdict === 'ok' ? 'success' : verdict === 'waiting' ? 'info' : verdict === 'abroad' ? 'warning' : 'danger'}>{t('peers_verdict_' + verdict)}</Badge>
				<p>{t('peers_verdict_' + verdict + '_desc')}</p>
			</div>
			<p class="rf-small rf-subtle">{t('peers_note')}</p>
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
							<tr><th scope="col">{t('col_date')}</th><th scope="col">{t('peers_day_inside')}</th><th scope="col">{t('peers_day_outside')}</th></tr>
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
				{#snippet actions()}
					<Button size="sm" busy={busy === 'peers/check'} onclick={() => run('peers/check', 'POST')}>{t('peers_check_now')}</Button>
				{/snippet}
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
	.rf-verdict {
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
