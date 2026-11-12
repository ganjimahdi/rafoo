<script lang="ts">
	import Badge from '../components/Badge.svelte';
	import Button from '../components/Button.svelte';
	import Card from '../components/Card.svelte';
	import Notice from '../components/Notice.svelte';
	import Toggle from '../components/Toggle.svelte';
	import { api } from '../lib/api';
	import { ago, bytes, help, t } from '../lib/i18n';
	import { store } from '../lib/store.svelte';
	import type { BackupStatus } from '../lib/types';

	interface Props {
		status: BackupStatus;
		onupdate: (next: BackupStatus) => void;
	}

	let { status, onupdate }: Props = $props();

	const SIGNUP = 'https://my.files.ir/register';
	const TERMS = 'https://my.files.ir/pages/terms-of-service';

	let local = $state(store.settings.backup_local);
	let remote = $state(store.settings.backup_remote ?? true);
	let wanted = $state(false);
	let key = $state('');
	let busy = $state('');
	let error = $state('');
	let message = $state('');

	const files = $derived(status.files);
	const filesOn = $derived(files ? files.enabled : wanted);
	const none = $derived(!local && !(remote && status.destinations.length > 0) && !files?.enabled);

	async function run(id: string, task: () => Promise<BackupStatus | void>, done = '') {
		busy = id;
		error = '';
		message = '';
		try {
			const next = await task();
			if (next) onupdate(next);
			message = done;
		} catch (e) {
			error = e instanceof Error ? e.message : t('error_generic');
		}
		busy = '';
	}

	function setting(name: 'backup_local' | 'backup_remote', value: boolean) {
		return run(name, async () => {
			await store.saveSettings({ [name]: value });
			local = store.settings.backup_local;
			remote = store.settings.backup_remote;
		});
	}

	function switchFiles(on: boolean) {
		if (!files) {
			wanted = on;
			error = '';
			return;
		}
		return run('files-enable', () => api<BackupStatus>('backup/files/enable', 'POST', { enabled: on }));
	}

	function connect(event: SubmitEvent) {
		event.preventDefault();
		return run('files-connect', async () => {
			const next = await api<BackupStatus>('backup/files/connect', 'POST', { key: key.trim() });
			key = '';
			wanted = false;
			return next;
		}).then(() => {
			if (status.files && !error) message = t('filesir_connected', bytes(status.files.speed));
		});
	}
</script>

<Card title={t('storage_title')} help={help('card_storage')} description={t('storage_help')}>
	{#if message}<Notice tone="success" live>{message}</Notice>{/if}
	{#if error}<Notice tone="danger" live>{error}</Notice>{/if}

	<Toggle label={t('backup_local_label')} help={help('backup_local')} description={t('storage_local_help')} bind:checked={local} disabled={busy !== ''} onchange={(on) => setting('backup_local', on)} />
	<Toggle label={t('storage_remote_label')} help={help('backup_remote')} description={t('storage_remote_help')} bind:checked={remote} disabled={busy !== ''} onchange={(on) => setting('backup_remote', on)} />

	<section class="rf-files" aria-labelledby="rafoo-files-title">
		<div class="rf-row">
			<h3 id="rafoo-files-title">{t('filesir_title')}</h3>
			<Badge tone="success">{t('filesir_free')}</Badge>
		</div>
		<p class="rf-subtle rf-small">{t('filesir_note')}</p>
		<Toggle label={t('filesir_label')} help={help('filesir')} description={t('filesir_help')} checked={filesOn} disabled={busy !== ''} onchange={switchFiles} />

		{#if files}
			<div class="rf-files-info">
				<div class="rf-row">
					<Badge tone={files.last_error ? 'danger' : files.enabled ? 'success' : 'neutral'}>
						{files.last_error ? t('destination_error') : files.last_ok ? t('destination_ok', ago(files.last_ok)) : t('filesir_account', files.hint)}
					</Badge>
					{#if files.last_ok || files.last_error}<span class="rf-subtle rf-small">{t('filesir_account', files.hint)}</span>{/if}
				</div>
				<span class="rf-small">{t('filesir_folder', files.path)}</span>
				{#if files.space}<span class="rf-small">{t('filesir_workspace', files.space)}</span>{/if}
				{#if files.used >= 0 && files.free >= 0}<span class="rf-subtle rf-small">{t('filesir_space', bytes(files.used), bytes(files.free))}</span>{/if}
				{#if files.speed}<span class="rf-subtle rf-small">{t('destination_speed', bytes(files.speed))}</span>{/if}
				{#if files.last_error}<span class="rf-small rf-mono">{files.last_error}</span>{/if}
			</div>
			{#if !status.support.stream}<Notice tone="warning">{t('filesir_stream_missing')}</Notice>{/if}
			<div class="rf-row rf-end">
				<Button size="sm" variant="ghost" busy={busy === 'files-test'} disabled={busy !== ''} onclick={() => run('files-test', () => api<BackupStatus>('backup/destinations/test', 'POST', { id: files.id }), t('destination_tested'))}>{t('destination_test')}</Button>
				<Button size="sm" variant="ghost" busy={busy === 'files-remove'} disabled={busy !== ''} onclick={() => run('files-remove', () => api<BackupStatus>('backup/destinations/delete', 'POST', { id: files.id }))}>{t('filesir_disconnect')}</Button>
			</div>
		{:else if wanted}
			<form class="rf-stack" onsubmit={connect} novalidate>
				<p class="rf-small">
					{t('filesir_signup')}
					<a href={SIGNUP} target="_blank" rel="noopener noreferrer">{t('filesir_signup_link')}</a> ·
					<a href={TERMS} target="_blank" rel="noopener noreferrer">{t('filesir_terms')}</a>
				</p>
				<div class="rf-field">
					<label for="rafoo-files-key">{t('filesir_key')}</label>
					<p id="rafoo-files-key-help" class="rf-subtle rf-small">{t('filesir_key_help')}</p>
					<input id="rafoo-files-key" class="rf-input rf-mono" type="password" autocomplete="off" spellcheck="false" dir="ltr" aria-describedby="rafoo-files-key-help" bind:value={key} />
				</div>
				{#if !status.support.stream}<Notice tone="warning">{t('filesir_stream_missing')}</Notice>{/if}
				<div class="rf-row rf-end">
					<Button type="submit" variant="primary" busy={busy === 'files-connect'} disabled={busy !== '' || key.trim().length < 16}>{busy === 'files-connect' ? t('filesir_connecting') : t('filesir_connect')}</Button>
				</div>
			</form>
		{/if}
	</section>

	{#if none}<Notice tone="info">{t('storage_none')}</Notice>{/if}
</Card>

<style>
	.rf-files {
		display: flex;
		flex-direction: column;
		gap: 12px;
		padding-block-start: 16px;
		border-block-start: 1px solid var(--rf-l2);
	}
	.rf-files h3 {
		margin: 0;
	}
	.rf-files-info {
		display: flex;
		flex-direction: column;
		gap: 2px;
	}
	.rf-end {
		justify-content: flex-end;
	}
</style>
