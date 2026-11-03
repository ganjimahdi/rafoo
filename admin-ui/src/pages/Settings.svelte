<script lang="ts">
	import Button from '../components/Button.svelte';
	import Card from '../components/Card.svelte';
	import Choices from '../components/Choices.svelte';
	import Notice from '../components/Notice.svelte';
	import Toggle from '../components/Toggle.svelte';
	import { boot } from '../lib/boot';
	import { t } from '../lib/i18n';
	import { store } from '../lib/store.svelte';
	import type { Settings } from '../lib/types';

	const form = $state<Settings>({ ...store.settings });
	let allowHosts = $state(store.settings.allow_hosts.join('\n'));
	let saving = $state(false);
	let error = $state('');
	let saved = $state(false);

	const three = (key: string) => [
		{ value: 'outage' as const, label: t('choice_outage'), description: t(key + '_outage_help') },
		{ value: 'always' as const, label: t('choice_always'), description: t(key + '_always_help') },
		{ value: 'off' as const, label: t('choice_off') }
	];

	async function save(event: SubmitEvent) {
		event.preventDefault();
		saving = true;
		error = '';
		saved = false;
		try {
			const { setup: _setup, preset: _preset, consent_at: _consent, ...changes } = form;
			await store.saveSettings({
				...changes,
				allow_hosts: allowHosts
					.split(/[\s,]+/)
					.map((host) => host.trim())
					.filter(Boolean)
			});
			allowHosts = store.settings.allow_hosts.join('\n');
			saved = true;
		} catch (e) {
			error = e instanceof Error ? e.message : t('error_generic');
		}
		saving = false;
	}
</script>

<form class="rf-stack" onsubmit={save} novalidate>
	<div class="rf-setup-again">
		<span class="rf-subtle rf-small">{store.settings.preset && store.settings.preset !== 'custom' ? t('preset_active', t('preset_' + store.settings.preset)) : t('preset_custom_active')}</span>
		<a class="rf-link-button" href="#/setup">{t('setup_again')}</a>
	</div>

	<Card title={t('settings_files_title')}>
		<Choices
			name="rafoo-replace"
			legend={t('replace_label')}
			bind:value={form.replace}
			options={[
				{ value: 'always', label: t('replace_always'), description: t('replace_always_help') },
				{ value: 'outage', label: t('replace_outage'), description: t('replace_outage_help') },
				{ value: 'off', label: t('replace_off'), description: t('replace_off_help') }
			]}
		/>
		<Toggle label={t('html_scan_label')} description={t('html_scan_help')} bind:checked={form.html_scan} />
		<Toggle label={t('fonts_label')} description={t('fonts_help')} bind:checked={form.fonts} />
		<Toggle label={t('core_assets_label')} description={t('core_assets_help')} bind:checked={form.core_assets} />
	</Card>

	<Card title={t('settings_services_title')}>
		<Choices name="rafoo-avatars" legend={t('avatars_label')} bind:value={form.avatars} options={three('avatars')} />
		<Choices name="rafoo-captcha" legend={t('captcha_label')} description={t('captcha_help')} bind:value={form.captcha} options={three('captcha')} />
		<Choices name="rafoo-analytics" legend={t('analytics_label')} description={t('analytics_help')} bind:value={form.analytics} options={three('analytics')} />
		<Toggle label={t('embeds_label')} description={t('embeds_help')} bind:checked={form.embeds} />
		<Toggle label={t('social_login_label')} description={t('social_login_help')} bind:checked={form.social_login} />
		<Toggle label={t('mail_queue_label')} description={t('mail_queue_help')} bind:checked={form.mail_queue} />
	</Card>

	<Card title={t('settings_updates_safety_title')}>
		{#if boot.edition === 'complete'}
			<Choices name="rafoo-updates" legend={t('updates_label')} bind:value={form.updates} options={three('updates')} />
			<Toggle label={t('translations_label')} description={t('translations_help')} bind:checked={form.translations} />
		{/if}
		<Toggle label={t('safe_updates_label')} description={t('safe_updates_help')} bind:checked={form.safe_updates} />
		<Toggle label={t('backup_label')} description={t('backup_help')} bind:checked={form.backup} />
	</Card>

	<Card title={t('settings_requests_title')}>
		<Toggle label={t('request_log_label')} description={t('request_log_help')} bind:checked={form.request_log} />
		<div class="rf-field">
			<label for="rafoo-allow">{t('allow_hosts_label')}</label>
			<p id="rafoo-allow-help" class="rf-subtle rf-small">{t('allow_hosts_help')}</p>
			<textarea id="rafoo-allow" class="rf-input rf-mono" rows="4" aria-describedby="rafoo-allow-help" bind:value={allowHosts} spellcheck="false"></textarea>
		</div>
	</Card>

	<Card title={t('settings_privacy_title')}>
		<Toggle label={t('telemetry_label')} description={t('telemetry_help')} bind:checked={form.telemetry} />
		{#if boot.privacy_url}
			<p class="rf-small"><a href={boot.privacy_url}>{t('privacy_link')}</a></p>
		{/if}
	</Card>

	<div class="rf-save">
		{#if saved}<Notice tone="success" live>{t('settings_saved')}</Notice>{/if}
		{#if error}<Notice tone="danger" live>{error}</Notice>{/if}
		<Button type="submit" variant="primary" busy={saving}>{t('save')}</Button>
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
