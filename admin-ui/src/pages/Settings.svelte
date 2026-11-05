<script lang="ts">
	import Button from '../components/Button.svelte';
	import Card from '../components/Card.svelte';
	import Choices from '../components/Choices.svelte';
	import Help from '../components/Help.svelte';
	import Notice from '../components/Notice.svelte';
	import Toggle from '../components/Toggle.svelte';
	import { boot } from '../lib/boot';
	import { help, t } from '../lib/i18n';
	import { store } from '../lib/store.svelte';
	import type { NetworkPolicy, Settings } from '../lib/types';

	const network = boot.context === 'network' && store.network ? store.network : null;
	const managed = boot.context === 'site' && boot.managed.enforced ? boot.managed : null;
	const form = $state<Settings>({ ...store.settings, ...(network ? network.settings : {}) });
	let policy = $state<NetworkPolicy>(network ? network.policy : 'defaults');
	let allowHosts = $state(form.allow_hosts.join('\n'));
	let blockHosts = $state(form.block_hosts.join('\n'));
	let saving = $state(false);
	let error = $state('');
	let saved = $state(false);

	const locked = (key: keyof Settings) => !!managed && managed.keys.includes(key);
	const shared = (key: keyof Settings) => !network || network.keys.includes(key);

	const three = (key: string) => [
		{ value: 'outage' as const, label: t('choice_outage'), description: t(key + '_outage_help') },
		{ value: 'always' as const, label: t('choice_always'), description: t(key + '_always_help') },
		{ value: 'off' as const, label: t('choice_off') }
	];

	const lines = (text: string) =>
		text
			.split(/[\s,]+/)
			.map((line) => line.trim())
			.filter(Boolean);

	async function save(event: SubmitEvent) {
		event.preventDefault();
		saving = true;
		error = '';
		saved = false;
		try {
			const { setup: _setup, preset: _preset, consent_at: _consent, paused: _paused, ...rest } = form;
			const all: Partial<Settings> = { ...rest, allow_hosts: lines(allowHosts), block_hosts: lines(blockHosts) };
			const pick = (keep: (key: keyof Settings) => boolean) =>
				Object.fromEntries(Object.entries(all).filter(([key]) => keep(key as keyof Settings))) as Partial<Settings>;
			if (network) {
				await store.saveNetwork(policy, pick(shared));
				const next = store.network?.settings ?? {};
				allowHosts = (next.allow_hosts ?? []).join('\n');
				blockHosts = (next.block_hosts ?? []).join('\n');
			} else {
				await store.saveSettings(pick((key) => !locked(key)));
				allowHosts = store.settings.allow_hosts.join('\n');
				blockHosts = store.settings.block_hosts.join('\n');
			}
			saved = true;
		} catch (e) {
			error = e instanceof Error ? e.message : t('error_generic');
		}
		saving = false;
	}
</script>

<form class="rf-stack" onsubmit={save} novalidate>
	{#if network}
		<Card title={t('network_title')} help={help('card_network')}>
			<p class="rf-subtle rf-small">{t('network_sites', network.sites)}</p>
			<Choices
				name="rafoo-network-policy"
				legend={t('network_policy_label')}
				help={help('network_policy')}
				bind:value={policy}
				options={[
					{ value: 'defaults', label: t('network_policy_defaults'), description: t('network_policy_defaults_help') },
					{ value: 'enforced', label: t('network_policy_enforced'), description: t('network_policy_enforced_help') }
				]}
			/>
		</Card>
	{:else if managed}
		<Notice tone="info">
			{t('network_managed')}
			{#if managed.url}<a href={managed.url}>{t('network_managed_link')}</a>{/if}
		</Notice>
	{:else}
		<div class="rf-setup-again">
			<span class="rf-subtle rf-small">{store.settings.preset && store.settings.preset !== 'custom' ? t('preset_active', t('preset_' + store.settings.preset)) : t('preset_custom_active')}</span>
			<a class="rf-link-button" href="#/setup">{t('setup_again')}</a>
		</div>
	{/if}

	<Card title={t('settings_files_title')} help={help('card_settings_files')}>
		<Choices
			name="rafoo-replace"
			legend={t('replace_label')}
			help={help('replace')}
			bind:value={form.replace}
			disabled={locked('replace')}
			options={[
				{ value: 'always', label: t('replace_always'), description: t('replace_always_help') },
				{ value: 'outage', label: t('replace_outage'), description: t('replace_outage_help') },
				{ value: 'off', label: t('replace_off'), description: t('replace_off_help') }
			]}
		/>
		<Toggle label={t('html_scan_label')} description={t('html_scan_help')} help={help('html_scan')} bind:checked={form.html_scan} disabled={locked('html_scan')} />
		<Toggle label={t('fonts_label')} description={t('fonts_help')} help={help('fonts')} bind:checked={form.fonts} disabled={locked('fonts')} />
		<Toggle label={t('core_assets_label')} description={t('core_assets_help')} help={help('core_assets')} bind:checked={form.core_assets} disabled={locked('core_assets')} />
		<Toggle label={t('scan_as_user_label')} description={t('scan_as_user_help')} help={help('scan_as_user')} bind:checked={form.scan_as_user} disabled={locked('scan_as_user')} />
	</Card>

	<Card title={t('settings_browser_title')} help={help('card_settings_browser')}>
		<Toggle label={t('browser_guard_label')} description={t('browser_guard_help')} help={help('browser_guard')} bind:checked={form.browser_guard} disabled={locked('browser_guard')} />
		<Toggle label={t('dedupe_label')} description={t('dedupe_help')} help={help('dedupe')} bind:checked={form.dedupe} disabled={locked('dedupe')} />
		<Choices
			name="rafoo-heartbeat"
			legend={t('heartbeat_label')}
			help={help('heartbeat')}
			bind:value={form.heartbeat}
			disabled={locked('heartbeat')}
			options={[
				{ value: 'smart', label: t('heartbeat_smart'), description: t('heartbeat_smart_help') },
				{ value: 'default', label: t('heartbeat_default'), description: t('heartbeat_default_help') }
			]}
		/>
		<div class="rf-field">
			<span class="rf-field-label"><label for="rafoo-block">{t('block_hosts_label')}</label><Help text={help('block_hosts')} label={t('block_hosts_label')} /></span>
			<p id="rafoo-block-help" class="rf-subtle rf-small">{t('block_hosts_help')}</p>
			<textarea id="rafoo-block" class="rf-input rf-mono" rows="4" aria-describedby="rafoo-block-help" bind:value={blockHosts} readonly={locked('block_hosts')} spellcheck="false" dir="ltr"></textarea>
		</div>
	</Card>

	<Card title={t('settings_services_title')} help={help('card_settings_services')}>
		<Choices name="rafoo-avatars" legend={t('avatars_label')} help={help('avatars')} bind:value={form.avatars} disabled={locked('avatars')} options={three('avatars')} />
		<Choices name="rafoo-captcha" legend={t('captcha_label')} description={t('captcha_help')} help={help('captcha')} bind:value={form.captcha} disabled={locked('captcha')} options={three('captcha')} />
		<Choices name="rafoo-analytics" legend={t('analytics_label')} description={t('analytics_help')} help={help('analytics')} bind:value={form.analytics} disabled={locked('analytics')} options={three('analytics')} />
		<Toggle label={t('embeds_label')} description={t('embeds_help')} help={help('embeds')} bind:checked={form.embeds} disabled={locked('embeds')} />
		<Toggle label={t('social_login_label')} description={t('social_login_help')} help={help('social_login')} bind:checked={form.social_login} disabled={locked('social_login')} />
		<Toggle label={t('mail_queue_label')} description={t('mail_queue_help')} help={help('mail_queue')} bind:checked={form.mail_queue} disabled={locked('mail_queue')} />
	</Card>

	<Card title={t('settings_updates_safety_title')} help={help('card_settings_updates_safety')}>
		{#if boot.edition === 'complete'}
			<Choices name="rafoo-updates" legend={t('updates_label')} help={help('updates')} bind:value={form.updates} disabled={locked('updates')} options={three('updates')} />
			<Toggle label={t('translations_label')} description={t('translations_help')} help={help('translations')} bind:checked={form.translations} disabled={locked('translations')} />
		{/if}
		<Toggle label={t('safe_updates_label')} description={t('safe_updates_help')} help={help('safe_updates')} bind:checked={form.safe_updates} disabled={locked('safe_updates')} />
		{#if !network}
			<Toggle label={t('backup_label')} description={t('backup_help')} help={help('backup')} bind:checked={form.backup} />
		{/if}
	</Card>

	<Card title={t('settings_requests_title')} help={help('card_settings_requests')}>
		<Toggle label={t('request_log_label')} description={t('request_log_help')} help={help('request_log')} bind:checked={form.request_log} disabled={locked('request_log')} />
		<div class="rf-field">
			<span class="rf-field-label"><label for="rafoo-allow">{t('allow_hosts_label')}</label><Help text={help('allow_hosts')} label={t('allow_hosts_label')} /></span>
			<p id="rafoo-allow-help" class="rf-subtle rf-small">{t('allow_hosts_help')}</p>
			<textarea id="rafoo-allow" class="rf-input rf-mono" rows="4" aria-describedby="rafoo-allow-help" bind:value={allowHosts} readonly={locked('allow_hosts')} spellcheck="false" dir="ltr"></textarea>
		</div>
	</Card>

	{#if !network}
		<Card title={t('settings_privacy_title')} help={help('card_settings_privacy')}>
			<Toggle label={t('telemetry_label')} description={t('telemetry_help')} help={help('telemetry')} bind:checked={form.telemetry} />
			{#if boot.privacy_url}
				<p class="rf-small"><a href={boot.privacy_url}>{t('privacy_link')}</a></p>
			{/if}
		</Card>
	{/if}

	<div class="rf-save">
		{#if saved}<Notice tone="success" live>{t('settings_saved')}</Notice>{/if}
		{#if error}<Notice tone="danger" live>{error}</Notice>{/if}
		<Button type="submit" variant="primary" busy={saving}>{network ? t('network_save') : t('save')}</Button>
	</div>
</form>

<style>
	.rf-setup-again {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		justify-content: space-between;
		gap: 8px;
	}
	.rf-link-button {
		display: inline-flex;
		align-items: center;
		min-block-size: var(--rf-control-sm);
		padding-inline: 12px;
		border: 1px solid var(--rf-l2);
		border-radius: var(--rf-r-control);
		background: var(--rf-l0);
		color: var(--rf-l6);
		font-size: var(--rf-fs-sm);
		text-decoration: none;
	}
	.rf-link-button:hover {
		background: var(--rf-l1);
	}
	.rf-save {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		justify-content: flex-end;
		gap: 12px;
	}
</style>
