<script lang="ts">
	import Badge from '../components/Badge.svelte';
	import Button from '../components/Button.svelte';
	import Card from '../components/Card.svelte';
	import Choices from '../components/Choices.svelte';
	import Help from '../components/Help.svelte';
	import Notice from '../components/Notice.svelte';
	import Segmented from '../components/Segmented.svelte';
	import Stat from '../components/Stat.svelte';
	import Toggle from '../components/Toggle.svelte';
	import { api } from '../lib/api';
	import { ago, date, help, num, t } from '../lib/i18n';
	import { store } from '../lib/store.svelte';
	import type { ShieldEvent, ShieldMode, ShieldView } from '../lib/types';

	let view = $state<ShieldView | null>(null);
	let error = $state('');
	let busy = $state('');
	let allow = $state('');
	let deny = $state('');
	let proxies = $state('');
	let provider = $state<'arvan' | 'cloudflare'>('arvan');
	let domain = $state('');
	let secret = $state('');
	let reuse = $state(false);

	const modes: { value: ShieldMode; label: string }[] = [
		{ value: 'off', label: t('shield_mode_off') },
		{ value: 'smart', label: t('shield_mode_smart') },
		{ value: 'strict', label: t('shield_mode_strict') },
		{ value: 'fortress', label: t('shield_mode_fortress') }
	];

	function fill(next: ShieldView) {
		view = next;
		allow = next.allow.join('\n');
		deny = next.deny.join('\n');
		proxies = next.proxies.join('\n');
		if (!domain) domain = next.edge.domain || next.edge.host;
		store.overview = { ...store.overview, shield: next.card };
	}

	async function run(name: string, task: () => Promise<ShieldView | void>) {
		busy = name;
		error = '';
		try {
			const next = await task();
			if (next) fill(next);
		} catch (e) {
			error = e instanceof Error ? e.message : t('error_generic');
		}
		busy = '';
	}

	run('load', () => api<ShieldView>('shield'));

	const lines = (text: string) =>
		text
			.split(/[\s,]+/)
			.map((line) => line.trim())
			.filter(Boolean);

	const setMode = (mode: ShieldMode) =>
		run('mode', async () => {
			const next = await api<ShieldView>('shield/mode', 'POST', { mode });
			store.settings = { ...store.settings, shield: mode };
			return next;
		});

	const saveLists = () => run('lists', () => api<ShieldView>('shield/lists', 'POST', { allow: lines(allow), deny: lines(deny), proxies: lines(proxies) }));
	const prepend = (enabled: boolean) => run('prepend', () => api<ShieldView>('shield/prepend', 'POST', { enabled }));
	const ipAction = (action: 'allow' | 'block' | 'release', ip: string) => run(action + ip, () => api<ShieldView>('shield/' + action, 'POST', { ip }));
	const sync = () => run('sync', () => api<ShieldView>('shield/network/sync', 'POST'));
	const connect = () => run('edge', () => api<ShieldView>('shield/edge', 'POST', { provider, domain, secret, reuse }));
	const disconnect = () => run('edge-off', () => api<ShieldView>('shield/edge/remove', 'POST'));
	const push = () => run('edge-push', () => api<ShieldView>('shield/edge/push', 'POST'));

	async function community(enabled: boolean) {
		await run('network', async () => {
			await store.saveSettings({ shield_network: enabled });
			return api<ShieldView>('shield');
		});
	}

	const action = (e: ShieldEvent) => t('shield_action_' + e.a);
	const group = (name: string) => {
		const key = 'shield_group_' + name;
		const label = t(key);
		return label === key ? name : label;
	};
	const tone = (e: ShieldEvent) => (e.a === 'challenge' ? 'info' : e.a === 'limit' || e.a === 'lock' || e.a === 'locked' ? 'warning' : 'danger');
	const protection = $derived(view ? (view.prepend.status === 'active' ? 'early' : view.first ? 'first' : 'plugin') : 'plugin');
	const allowed = (ip: string) => !!view && view.allow.includes(ip);
	const denied = (ip: string) => !!view && view.deny.includes(ip);
</script>

<div class="rf-stack">
	{#if view === null}
		{#if error}<Notice tone="danger" live>{error}</Notice>{:else}<p class="rf-subtle">{t('loading')}</p>{/if}
	{:else}
		<Card>
			<div class="rf-shield-head">
				<div class={['rf-signal', view.card.mode === 'off' ? 'rf-signal-off' : view.card.mode === 'fortress' ? 'rf-signal-warn' : 'rf-signal-on']} aria-hidden="true"></div>
				<div class="rf-shield-text">
					<h2>{t('shield_status_' + view.card.mode)}</h2>
					<p class="rf-subtle">{t('shield_mode_' + view.card.mode + '_help')}</p>
					{#if view.card.mode !== 'off'}
						<p class="rf-subtle rf-small">{t('shield_where_' + protection)}</p>
					{/if}
				</div>
			</div>
			<div class="rf-mode">
				<div class="rf-mode-text">
					<span class="rf-mode-label">{t('shield_mode_label')}<Help text={help('shield_mode')} label={t('shield_mode_label')} /></span>
					<span class="rf-subtle rf-small">{t('shield_mode_hint')}</span>
				</div>
				<Segmented name="rafoo-shield-mode" label={t('shield_mode_label')} options={modes} value={view.card.setting} disabled={busy === 'mode' || store.overview.paused} onchange={setMode} />
			</div>
			{#if store.overview.paused}<Notice tone="warning">{t('shield_paused')}</Notice>{/if}
			{#if error}<Notice tone="danger" live>{error}</Notice>{/if}
		</Card>

		<div class="rf-stats">
			<Stat label={t('shield_stat_today')} value={num(view.stats.stopped_today)} />
			<Stat label={t('shield_stat_week')} value={num(view.stats.stopped_week)} />
			<Stat label={t('shield_stat_challenges')} value={num(view.stats.week.challenge ?? 0)} />
			<Stat label={t('shield_stat_logins')} value={num((view.stats.week.lock ?? 0) + (view.stats.week.locked ?? 0))} />
		</div>

		<Card title={t('shield_early_title')} description={t('shield_early_text')} help={help('shield_early')}>
			{#snippet actions()}
				{#if view && (view.prepend.status === 'off' || view.prepend.status === 'pending' || view.prepend.status === 'broken' || view.prepend.enabled)}
					<Button variant={view.prepend.enabled ? 'secondary' : 'primary'} busy={busy === 'prepend'} onclick={() => prepend(!view!.prepend.enabled)}>
						{view.prepend.enabled ? t('shield_early_disable') : t('shield_early_enable')}
					</Button>
				{/if}
			{/snippet}
			<p class="rf-small"><Badge tone={view.prepend.status === 'active' ? 'success' : view.prepend.status === 'broken' || view.prepend.status === 'conflict' ? 'warning' : 'neutral'}>{t('shield_early_' + view.prepend.status)}</Badge></p>
			{#if view.prepend.status === 'pending'}<p class="rf-subtle rf-small">{t('shield_early_pending_help', Math.max(1, Math.round(view.prepend.cache / 60)))}</p>{/if}
			{#if view.prepend.status === 'conflict'}<p class="rf-subtle rf-small">{t('shield_early_conflict_help', view.prepend.other)}</p>{/if}
			{#if view.prepend.status === 'unsupported'}<p class="rf-subtle rf-small">{t('shield_early_unsupported_help')}</p>{/if}
			<p class="rf-subtle rf-small">{view.apcu ? t('shield_store_apcu') : t('shield_store_files')}</p>
		</Card>

		<div class="rf-cols">
			<Card title={t('shield_groups_title')} help={help('shield_groups')}>
				{#if view.stats.groups.length === 0}
					<p class="rf-subtle">{t('shield_none_week')}</p>
				{:else}
					<ul class="rf-rows">
						{#each view.stats.groups as row (row.name)}<li><span>{group(row.name)}</span><span>{num(row.count)}</span></li>{/each}
					</ul>
				{/if}
			</Card>
			<Card title={t('shield_ips_title')} help={help('shield_ips')}>
				{#if view.stats.ips.length === 0}
					<p class="rf-subtle">{t('shield_none_week')}</p>
				{:else}
					<ul class="rf-rows">
						{#each view.stats.ips as row (row.name)}<li><bdi class="rf-mono">{row.name}</bdi><span>{num(row.count)}</span></li>{/each}
					</ul>
				{/if}
			</Card>
			<Card title={t('shield_paths_title')} help={help('shield_paths')}>
				{#if view.stats.paths.length === 0}
					<p class="rf-subtle">{t('shield_none_week')}</p>
				{:else}
					<ul class="rf-rows">
						{#each view.stats.paths as row (row.name)}<li><bdi class="rf-mono rf-cut">{row.name}</bdi><span>{num(row.count)}</span></li>{/each}
					</ul>
				{/if}
			</Card>
		</div>

		<Card title={t('shield_events_title')} description={t('shield_events_text')} help={help('shield_events')}>
			{#if view.events.length === 0}
				<p class="rf-subtle">{t('shield_events_empty')}</p>
			{:else}
				<ul class="rf-events">
					{#each view.events as e, index (e.t + e.r + index)}
						<li>
							<div class="rf-event-main">
								<Badge tone={tone(e)}>{action(e)}</Badge>
								<span>{group(e.g)}</span>
								<bdi class="rf-mono">{e.i}</bdi>
								<span class="rf-subtle rf-small"><bdi class="rf-mono rf-cut">{e.m} {e.p}</bdi></span>
							</div>
							<div class="rf-event-side">
								<time class="rf-subtle rf-small" datetime={new Date(e.t * 1000).toISOString()} title={date(e.t)}>{ago(e.t, view.now)}</time>
								{#if e.r}<span class="rf-subtle rf-small">{t('shield_ref')} <bdi class="rf-mono">{e.r}</bdi></span>{/if}
								{#if e.i && e.i !== view.you}
									{#if allowed(e.i)}
										<Badge tone="success">{t('shield_allowed')}</Badge>
									{:else}
										<Button size="sm" variant="ghost" busy={busy === 'allow' + e.i} onclick={() => ipAction('allow', e.i)}>{t('shield_allow')}</Button>
									{/if}
									{#if denied(e.i)}
										<Button size="sm" variant="ghost" busy={busy === 'release' + e.i} onclick={() => ipAction('release', e.i)}>{t('shield_release')}</Button>
									{:else}
										<Button size="sm" variant="ghost" busy={busy === 'block' + e.i} onclick={() => ipAction('block', e.i)}>{t('shield_block')}</Button>
									{/if}
								{/if}
							</div>
						</li>
					{/each}
				</ul>
			{/if}
		</Card>

		<Card title={t('shield_lists_title')} description={t('shield_lists_text', view.you || '—')} help={help('shield_lists')}>
			<div class="rf-cols">
				<div class="rf-field">
					<span class="rf-field-label"><label for="rafoo-shield-allow">{t('shield_allow_label')}</label><Help text={help('shield_allow')} label={t('shield_allow_label')} /></span>
					<textarea id="rafoo-shield-allow" class="rf-input rf-mono" rows="5" bind:value={allow} spellcheck="false" dir="ltr"></textarea>
				</div>
				<div class="rf-field">
					<span class="rf-field-label"><label for="rafoo-shield-deny">{t('shield_deny_label')}</label><Help text={help('shield_deny')} label={t('shield_deny_label')} /></span>
					<textarea id="rafoo-shield-deny" class="rf-input rf-mono" rows="5" bind:value={deny} spellcheck="false" dir="ltr"></textarea>
				</div>
				<div class="rf-field">
					<span class="rf-field-label"><label for="rafoo-shield-proxies">{t('shield_proxies_label')}</label><Help text={help('shield_proxies')} label={t('shield_proxies_label')} /></span>
					<textarea id="rafoo-shield-proxies" class="rf-input rf-mono" rows="5" bind:value={proxies} spellcheck="false" dir="ltr"></textarea>
				</div>
			</div>
			<div class="rf-end"><Button variant="primary" busy={busy === 'lists'} onclick={saveLists}>{t('save')}</Button></div>
		</Card>

		<Card title={t('shield_network_title')} description={t('shield_network_text')} help={help('shield_network')}>
			<Toggle label={t('shield_network_label')} description={t('shield_network_help')} help={help('shield_network_toggle')} checked={view.network.enabled} disabled={busy === 'network'} onchange={community} />
			{#if view.network.enabled}
				{#if view.network.joined}
					<p class="rf-small">
						<Badge tone="success">{t('shield_network_joined')}</Badge>
						<span class="rf-subtle">{t('shield_network_stats', view.network.count, view.network.sent)} · {t('last_check', ago(view.network.checked, view.now))}</span>
					</p>
					{#if view.network.patches.length}
						<div class="rf-field">
							<span class="rf-field-label">{t('shield_patches_active')}</span>
							<ul class="rf-rows">
								{#each view.network.patches as p (p.id)}<li><span>{p.title}</span><bdi class="rf-mono rf-subtle">{p.plugin}</bdi></li>{/each}
							</ul>
						</div>
					{:else}
						<p class="rf-subtle rf-small">{t('shield_patches_none')}</p>
					{/if}
				{:else}
					<Notice tone="warning">{view.network.error || t('shield_network_waiting')}</Notice>
				{/if}
				<div class="rf-end"><Button busy={busy === 'sync'} onclick={sync}>{t('shield_network_sync')}</Button></div>
			{/if}
		</Card>

		<Card title={t('shield_edge_title')} description={t('shield_edge_text')} help={help('shield_edge')}>
			{#if view.edge.provider}
				<p class="rf-small">
					<Badge tone={view.edge.error ? 'warning' : 'success'}>{t('shield_edge_' + view.edge.provider)}</Badge>
					<bdi class="rf-mono">{view.edge.domain}</bdi>
					<span class="rf-subtle">· {t('shield_edge_count', view.edge.count)}{#if view.edge.at} · {ago(view.edge.at, view.now)}{/if}</span>
				</p>
				{#if view.edge.error}<Notice tone="warning">{view.edge.error}</Notice>{/if}
				<div class="rf-end">
					<Button busy={busy === 'edge-push'} onclick={push}>{t('shield_edge_push')}</Button>
					<Button variant="ghost" busy={busy === 'edge-off'} onclick={disconnect}>{t('shield_edge_disconnect')}</Button>
				</div>
			{:else}
				<Choices
					name="rafoo-shield-edge"
					legend={t('shield_edge_provider')}
					bind:value={provider}
					options={[
						{ value: 'arvan', label: t('shield_edge_arvan') },
						{ value: 'cloudflare', label: t('shield_edge_cloudflare') }
					]}
				/>
				<div class="rf-field">
					<label class="rf-field-label" for="rafoo-shield-domain">{t('shield_edge_domain')}</label>
					<input id="rafoo-shield-domain" class="rf-input rf-mono" bind:value={domain} spellcheck="false" dir="ltr" autocomplete="off" />
				</div>
				{#if view.edge.geo[provider]}
					<Toggle label={t('shield_edge_reuse')} description={t('shield_edge_reuse_help')} bind:checked={reuse} />
				{/if}
				{#if !reuse || !view.edge.geo[provider]}
					<div class="rf-field">
						<label class="rf-field-label" for="rafoo-shield-secret">{provider === 'arvan' ? t('shield_edge_key_arvan') : t('shield_edge_key_cloudflare')}</label>
						<p id="rafoo-shield-secret-help" class="rf-subtle rf-small">{provider === 'arvan' ? t('shield_edge_key_arvan_help') : t('shield_edge_key_cloudflare_help')}</p>
						<input id="rafoo-shield-secret" class="rf-input rf-mono" type="password" aria-describedby="rafoo-shield-secret-help" bind:value={secret} spellcheck="false" dir="ltr" autocomplete="off" />
					</div>
				{/if}
				<div class="rf-end"><Button variant="primary" busy={busy === 'edge'} onclick={connect}>{t('shield_edge_connect')}</Button></div>
			{/if}
		</Card>

		<Notice tone="info">{t('shield_limit_note')}</Notice>
	{/if}
</div>

<style>
	.rf-shield-head {
		display: flex;
		align-items: flex-start;
		gap: 16px;
	}
	.rf-shield-text {
		display: flex;
		flex: 1;
		flex-direction: column;
		gap: 4px;
	}
	.rf-signal {
		flex: none;
		inline-size: 12px;
		block-size: 12px;
		margin-block-start: 6px;
		border-radius: 999px;
	}
	.rf-signal-on {
		background: var(--rf-success-dot);
	}
	.rf-signal-warn {
		background: var(--rf-warning-dot);
	}
	.rf-signal-off {
		background: var(--rf-danger-dot);
	}
	.rf-mode {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
		padding-block-start: 16px;
		border-block-start: 1px solid var(--rf-l2);
	}
	.rf-mode-text {
		display: flex;
		flex-direction: column;
		gap: 2px;
	}
	.rf-mode-label {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		font-weight: 700;
	}
	.rf-stats,
	.rf-cols {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
		gap: 12px;
	}
	.rf-rows,
	.rf-events {
		display: flex;
		flex-direction: column;
		margin: 0;
		padding: 0;
		list-style: none;
	}
	.rf-rows li,
	.rf-events li {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		justify-content: space-between;
		gap: 8px;
		margin: 0;
		padding-block: 8px;
		border-block-end: 1px solid var(--rf-l2);
	}
	.rf-rows li:last-child,
	.rf-events li:last-child {
		border-block-end: 0;
	}
	.rf-event-main,
	.rf-event-side {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 8px;
		min-inline-size: 0;
	}
	.rf-mono {
		font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
		font-size: var(--rf-fs-sm);
	}
	.rf-cut {
		display: inline-block;
		max-inline-size: 28ch;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
		vertical-align: bottom;
	}
	.rf-end {
		display: flex;
		flex-wrap: wrap;
		justify-content: flex-end;
		gap: 8px;
	}
</style>
