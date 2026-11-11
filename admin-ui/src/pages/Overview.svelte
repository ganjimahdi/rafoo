<script lang="ts">
	import Button from '../components/Button.svelte';
	import Card from '../components/Card.svelte';
	import Help from '../components/Help.svelte';
	import Notice from '../components/Notice.svelte';
	import Segmented from '../components/Segmented.svelte';
	import Stat from '../components/Stat.svelte';
	import { ago, bytes, date, duration, help, num, t } from '../lib/i18n';
	import { store } from '../lib/store.svelte';
	import { api } from '../lib/api';
	import type { Override, ShieldMode } from '../lib/types';

	let checking = $state(false);
	let error = $state('');

	const overview = $derived(store.overview);
	const offline = $derived(overview.mode === 'offline');
	const modes: { value: Override; label: string }[] = [
		{ value: 'auto', label: t('mode_auto') },
		{ value: 'offline', label: t('mode_offline') },
		{ value: 'online', label: t('mode_online') }
	];

	async function run(task: () => Promise<void>) {
		error = '';
		try {
			await task();
		} catch (e) {
			error = e instanceof Error ? e.message : t('error_generic');
		}
	}

	async function check() {
		checking = true;
		await run(() => store.check());
		checking = false;
	}

	let pausing = $state(false);
	let fortifying = $state(false);

	async function fortress(mode: ShieldMode) {
		fortifying = true;
		await run(async () => {
			await api('shield/mode', 'POST', { mode });
			store.settings = { ...store.settings, shield: mode };
			await store.refresh();
		});
		fortifying = false;
	}

	async function togglePause() {
		pausing = true;
		await run(() => store.setPaused(!overview.paused));
		pausing = false;
	}

	const FAILURES = ['backup_failed', 'restore_failed', 'transfer_failed', 'move_failed'];

	function failure(code: string): string {
		const bare = code.replace(/^remote: /, '');
		const key = 'backup_error_' + bare;
		const label = t(key);
		return label === key ? code : (bare === code ? '' : t('backup_step_remote') + ' ') + label;
	}

	function eventLabel(type: string, data: Record<string, unknown>): string {
		const key = 'event_' + type + (type === 'override' && typeof data.mode === 'string' ? '_' + data.mode : '');
		const text = (name: string) => (typeof data[name] === 'string' || typeof data[name] === 'number' ? String(data[name]) : '');
		const name = FAILURES.includes(type) ? failure(text('name')) : typeof data.bytes === 'number' ? bytes(data.bytes) : text('name');
		const label = t(key, name, text('to'), text('from'));
		return label === key ? type : label;
	}
</script>

<div class="rf-stack">
	<Card>
		<div class="rf-status">
			<div class={['rf-signal', offline ? 'rf-signal-off' : 'rf-signal-on']} aria-hidden="true"></div>
			<div class="rf-status-text">
				<h2>{offline ? t('status_offline_title') : t('status_online_title')}</h2>
				<p class="rf-subtle">{offline ? t('status_offline_text') : t('status_online_text')}</p>
				<p class="rf-subtle rf-small">
					{t('last_check', ago(overview.checked_at, overview.now))}
					{#if overview.changed_at > 0}
						· {t(offline ? 'offline_since' : 'online_since', date(overview.changed_at))}
					{/if}
				</p>
			</div>
			<div class="rf-status-actions">
				<Button onclick={check} busy={checking}>{t('check_now')}</Button>
			</div>
		</div>

		<div class="rf-mode">
			<div class="rf-mode-text">
				<span class="rf-mode-label">{t('mode_label')}<Help text={help('mode')} label={t('mode_label')} /></span>
				<span class="rf-subtle rf-small">{t('mode_help')}</span>
			</div>
			<Segmented
				name="rafoo-mode"
				label={t('mode_label')}
				options={modes}
				value={overview.override}
				disabled={!overview.network}
				onchange={(mode) => run(() => store.setMode(mode))}
			/>
		</div>

		{#if overview.override !== 'auto'}
			<Notice tone="info">{t(overview.override === 'offline' ? 'override_offline' : 'override_online')}</Notice>
		{/if}
		{#if overview.local_down}
			<Notice tone="warning">{t('server_network_down')}</Notice>
		{/if}
		{#if overview.cron.disabled && overview.cron.late}
			<Notice tone="warning">{t('cron_disabled')}</Notice>
		{:else if overview.cron.late}
			<Notice tone="warning">{t('cron_late')}</Notice>
		{/if}
		{#if error}<Notice tone="danger" live>{error}</Notice>{/if}
	</Card>

	<div class="rf-stats">
		<Stat label={t('stat_ready')} value={num(overview.assets.ready)} hint={overview.assets.unverified ? t('stat_unverified', overview.assets.unverified) : undefined} />
		<Stat label={t('stat_pending')} value={num(overview.assets.pending)} />
		<Stat label={t('stat_failed')} value={num(overview.assets.failed)} />
		<Stat label={t('stat_size')} value={bytes(overview.assets.bytes)} />
		<Stat label={t('stat_outage_total')} value={overview.outage_total > 0 ? duration(overview.outage_total) : '—'} />
	</div>

	{#if overview.shield}
		<Card title={t('shield_card_title')} description={t('shield_status_' + overview.shield.mode)} help={help('card_shield')}>
			{#snippet actions()}
				{#if overview.shield.mode !== 'off' && overview.shield.setting !== 'fortress'}
					<Button busy={fortifying} onclick={() => fortress('fortress')}>{t('shield_to_fortress')}</Button>
				{:else if overview.shield.setting === 'fortress'}
					<Button busy={fortifying} onclick={() => fortress('smart')}>{t('shield_to_smart')}</Button>
				{/if}
				<a class="rf-card-link" href="#/shield">{t('shield_open')}</a>
			{/snippet}
			<div class="rf-shield-nums">
				<Stat label={t('shield_stat_today')} value={num(overview.shield.today)} />
				<Stat label={t('shield_stat_week')} value={num(overview.shield.week)} />
			</div>
		</Card>
	{/if}

	<Card title={t('pause_title')} description={overview.paused ? t('pause_on_text') : t('pause_off_text')} help={help('card_pause')}>
		{#snippet actions()}
			<Button variant={overview.paused ? 'primary' : 'secondary'} busy={pausing} disabled={!overview.network} onclick={togglePause}>
				{overview.paused ? t('pause_resume') : t('pause_now')}
			</Button>
		{/snippet}
		<p class="rf-subtle rf-small">{t('pause_keeps')}</p>
	</Card>

	<Card title={t('events_title')} help={help('card_events')}>
		{#if overview.events.length === 0}
			<p class="rf-subtle">{t('events_empty')}</p>
		{:else}
			<ul class="rf-events">
				{#each overview.events as event, index (event.at + ':' + event.type + ':' + index)}
					<li>
						<span>{eventLabel(event.type, event.data)}</span>
						<time class="rf-subtle rf-small" datetime={new Date(event.at * 1000).toISOString()}>{date(event.at)}</time>
					</li>
				{/each}
			</ul>
		{/if}
	</Card>
</div>

<style>
	.rf-status {
		display: flex;
		flex-wrap: wrap;
		align-items: flex-start;
		gap: 16px;
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
	.rf-signal-off {
		background: var(--rf-warning-dot);
	}
	.rf-status-text {
		display: flex;
		flex: 1;
		flex-direction: column;
		gap: 4px;
		min-inline-size: 240px;
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
	.rf-stats {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
		gap: 12px;
	}
	.rf-shield-nums {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
		gap: 12px;
	}
	.rf-card-link {
		display: inline-flex;
		align-items: center;
		min-block-size: var(--rf-control-sm);
		padding-inline: 8px;
		color: var(--rf-l6);
		font-size: var(--rf-fs-sm);
	}
	.rf-events {
		display: flex;
		flex-direction: column;
		margin: 0;
		padding: 0;
		list-style: none;
	}
	.rf-events li {
		display: flex;
		flex-wrap: wrap;
		justify-content: space-between;
		gap: 8px;
		margin: 0;
		padding-block: 10px;
		border-block-end: 1px solid var(--rf-l2);
	}
	.rf-events li:last-child {
		border-block-end: 0;
	}
</style>
