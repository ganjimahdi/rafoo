<script lang="ts">
	import type { Component } from 'svelte';
	import Badge from './components/Badge.svelte';
	import Backup from './pages/Backup.svelte';
	import { boot } from './lib/boot';
	import { t } from './lib/i18n';
	import { store } from './lib/store.svelte';
	import Connection from './pages/Connection.svelte';
	import Feedback from './pages/Feedback.svelte';
	import Monitor from './pages/Monitor.svelte';
	import Files from './pages/Files.svelte';
	import Overview from './pages/Overview.svelte';
	import Settings from './pages/Settings.svelte';
	import Setup from './pages/Setup.svelte';
	import Updates from './pages/Updates.svelte';

	interface Tab {
		id: string;
		label: string;
		page: Component;
	}

	const network = boot.context === 'network';
	const managed = boot.context === 'site' && boot.managed.enforced;

	const all: Tab[] = [
		{ id: 'overview', label: t('tab_overview'), page: Overview },
		{ id: 'files', label: t('tab_files'), page: Files },
		{ id: 'connection', label: t('tab_connection'), page: Connection },
		{ id: 'monitor', label: t('tab_monitor'), page: Monitor },
		{ id: 'updates', label: t('tab_updates'), page: Updates },
		{ id: 'backup', label: t('tab_backup'), page: Backup },
		{ id: 'settings', label: t('tab_settings'), page: Settings },
		{ id: 'feedback', label: t('tab_feedback'), page: Feedback }
	];
	const tabs = network ? all.filter((tab) => tab.id === 'settings') : all;

	function fromHash(): string {
		const id = location.hash.replace(/^#\/?/, '');
		if (id === 'setup' && !network && !managed) return id;
		return tabs.some((tab) => tab.id === id) ? id : tabs[0].id;
	}

	let current = $state(fromHash());
	const active = $derived(tabs.find((tab) => tab.id === current) ?? tabs[0]);
	const offline = $derived(store.overview.mode === 'offline');

	function select(id: string, focus = false) {
		current = id;
		history.replaceState(null, '', '#/' + id);
		if (focus) document.getElementById('rf-tab-' + id)?.focus();
	}

	function keydown(event: KeyboardEvent, index: number) {
		const forward = boot.rtl ? 'ArrowLeft' : 'ArrowRight';
		const back = boot.rtl ? 'ArrowRight' : 'ArrowLeft';
		let next = -1;
		if (event.key === forward) next = (index + 1) % tabs.length;
		else if (event.key === back) next = (index - 1 + tabs.length) % tabs.length;
		else if (event.key === 'Home') next = 0;
		else if (event.key === 'End') next = tabs.length - 1;
		if (next >= 0) {
			event.preventDefault();
			select(tabs[next].id, true);
		}
	}
</script>

<svelte:window onhashchange={() => (current = fromHash())} />

<div class="rafoo" dir={boot.rtl ? 'rtl' : 'ltr'}>
	<div class="rf-head">
		<div class="rf-brand">
			<h1>{t('app_title')}</h1>
			<span class="rf-subtle rf-small">{boot.edition === 'complete' ? t('edition_complete') : t('edition_standard')} · {boot.version}{network ? ' · ' + t('network_admin') : ''}</span>
		</div>
		{#if store.overview.paused && !network}
			<Badge>{t('badge_paused')}</Badge>
		{:else}
			<Badge tone={offline ? 'warning' : 'success'}>{offline ? t('badge_offline') : t('badge_online')}</Badge>
		{/if}
	</div>
	{#if store.overview.paused && current !== 'setup' && !network}
		<div class="rf-setup-hint rf-paused-hint" role="status">
			<span>{t('paused_hint')}</span>
			<a href="#/overview" onclick={() => select('overview')}>{t('pause_resume')}</a>
		</div>
	{/if}

	{#if current === 'setup'}
		<Setup onfinish={() => { select('overview'); store.refresh().catch(() => {}); }} />
	{:else}
	{#if store.settings.setup === '' && !network && !managed}
		<div class="rf-setup-hint">
			<span>{t('setup_pending')}</span>
			<a href="#/setup">{t('setup_start')}</a>
		</div>
	{/if}
	{#if network}
		<p class="rf-subtle">{t('network_intro')}</p>
	{/if}
	<div class="rf-tabs" role="tablist" aria-label={t('app_title')}>
		{#each tabs as tab, index (tab.id)}
			<button
				type="button"
				role="tab"
				id={'rf-tab-' + tab.id}
				class={['rf-tab', tab.id === current && 'rf-on']}
				aria-selected={tab.id === current}
				aria-controls="rf-panel"
				tabindex={tab.id === current ? 0 : -1}
				onclick={() => select(tab.id)}
				onkeydown={(event) => keydown(event, index)}
			>
				{tab.label}
			</button>
		{/each}
	</div>

	<div id="rf-panel" class="rf-panel" role="tabpanel" aria-labelledby={'rf-tab-' + active.id} tabindex="-1">
		<active.page />
	</div>
	{/if}
</div>

<style>
	.rf-head {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
		margin-block-end: 16px;
	}
	.rf-brand {
		display: flex;
		flex-wrap: wrap;
		align-items: baseline;
		gap: 12px;
	}
	.rf-tabs {
		display: flex;
		gap: 4px;
		margin-block-end: 16px;
		overflow-x: auto;
		overflow-y: hidden;
		scrollbar-width: thin;
		background: linear-gradient(var(--rf-l2), var(--rf-l2)) bottom / 100% 1px no-repeat;
	}
	.rf-tab {
		position: relative;
		margin: 0;
		padding-block: 10px;
		padding-inline: 12px;
		border: 0;
		border-block-end: 2px solid transparent;
		background: none;
		color: var(--rf-l4);
		font: inherit;
		white-space: nowrap;
		cursor: pointer;
		transition: color var(--rf-dur-1) var(--rf-ease-out);
	}
	.rf-tab:hover {
		color: var(--rf-l6);
	}
	.rf-tab.rf-on {
		border-block-end-color: var(--rf-l5);
		color: var(--rf-l6);
		font-weight: 700;
	}
	.rf-tab:focus-visible {
		outline-offset: -2px;
	}
	.rf-setup-hint {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		justify-content: space-between;
		gap: 8px;
		margin-block-end: 16px;
		padding-block: 10px;
		padding-inline: 14px;
		border: 1px solid var(--rf-info-dot);
		border-radius: var(--rf-r-control);
		background: var(--rf-info-bg);
		color: var(--rf-info-fg);
		font-size: var(--rf-fs-sm);
	}
	.rf-setup-hint a {
		color: inherit;
		font-weight: 700;
	}
	.rf-paused-hint {
		border-color: var(--rf-warning-dot);
		background: var(--rf-warning-bg);
		color: var(--rf-warning-fg);
	}
	.rf-panel:focus {
		outline: none;
	}
</style>
