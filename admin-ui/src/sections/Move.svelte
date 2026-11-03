<script lang="ts">
	import Button from '../components/Button.svelte';
	import Card from '../components/Card.svelte';
	import Notice from '../components/Notice.svelte';
	import Segmented from '../components/Segmented.svelte';
	import { api } from '../lib/api';
	import { date, t } from '../lib/i18n';
	import type { BackupStatus } from '../lib/types';

	interface Props {
		status: BackupStatus;
		running: boolean;
		onupdate: (next: BackupStatus) => void;
	}

	let { status, running, onupdate }: Props = $props();

	type Mode = 'receive' | 'send' | 'empty';
	let mode = $state<Mode>('send');
	let code = $state('');
	let final = $state(true);
	let invite = $state<{ code: string; short: string; expires: number } | null>(null);
	let destination = $state('');
	let folder = $state('public_html');
	let url = $state('');
	let busy = $state('');
	let error = $state('');
	let copied = $state(false);

	const notice = $derived(status.job && status.job.kind === 'move' && status.job.status === 'done' && typeof status.job.notice === 'object' ? status.job.notice : null);

	async function act(id: string, task: () => Promise<BackupStatus>) {
		busy = id;
		error = '';
		try {
			const next = await task();
			if (next.code) invite = next.code;
			onupdate(next);
		} catch (e) {
			error = e instanceof Error ? e.message : t('error_generic');
		}
		busy = '';
	}

	async function copy(text: string) {
		try {
			await navigator.clipboard.writeText(text);
			copied = true;
			setTimeout(() => (copied = false), 2000);
		} catch {
			copied = false;
		}
	}
</script>

<Card title={t('move_title')} description={t('move_help')}>
	<Segmented
		name="rafoo-move-mode"
		label={t('move_title')}
		value={mode}
		onchange={(value: Mode) => ((mode = value), (error = ''))}
		options={[
			{ value: 'send', label: t('move_send') },
			{ value: 'receive', label: t('move_receive') },
			{ value: 'empty', label: t('move_empty') }
		]}
	/>

	{#if error}<Notice tone="danger" live>{error}</Notice>{/if}

	{#if mode === 'receive'}
		<p class="rf-small">{t('move_receive_help')}</p>
		{#if invite}
			<div class="rf-code">
				{#if invite.short}<p><span class="rf-subtle rf-small">{t('move_short_code')}</span> <strong class="rf-mono rf-big">{invite.short}</strong></p>{/if}
				<label for="rafoo-invite-code" class="rf-small">{t('move_long_code')}</label>
				<textarea id="rafoo-invite-code" class="rf-input rf-mono" rows="3" readonly value={invite.code}></textarea>
				<div class="rf-row">
					<Button size="sm" onclick={() => copy(invite?.code ?? '')}>{copied ? t('move_copied') : t('move_copy')}</Button>
					<span class="rf-subtle rf-small">{t('move_expires', date(invite.expires))}</span>
				</div>
				<Notice tone="warning">{t('move_receive_warning')}</Notice>
			</div>
		{/if}
		<div class="rf-row">
			<Button variant="primary" busy={busy === 'invite'} disabled={busy !== '' || running} onclick={() => act('invite', () => api<BackupStatus>('transfer/invite', 'POST'))}>{invite || status.invite ? t('move_new_code') : t('move_make_code')}</Button>
			{#if invite || status.invite}
				<Button variant="ghost" busy={busy === 'cancel'} onclick={() => act('cancel', async () => { const next = await api<BackupStatus>('transfer/invite/cancel', 'POST'); invite = null; return next; })}>{t('move_stop_receiving')}</Button>
			{/if}
		</div>
	{:else if mode === 'send'}
		<p class="rf-small">{t('move_send_help')}</p>
		<div class="rf-field">
			<label for="rafoo-send-code">{t('move_code')}</label>
			<textarea id="rafoo-send-code" class="rf-input rf-mono" rows="2" spellcheck="false" bind:value={code}></textarea>
		</div>
		<label class="rf-check"><input type="checkbox" bind:checked={final} /> {t('move_final')}</label>
		<div class="rf-row">
			<Button variant="primary" busy={busy === 'send'} disabled={busy !== '' || running || code.trim() === ''} onclick={() => act('send', () => api<BackupStatus>('transfer/send', 'POST', { code: code.trim(), final }))}>{t('move_start')}</Button>
		</div>
	{:else}
		<p class="rf-small">{t('move_empty_help')}</p>
		{#if status.destinations.length === 0}
			<Notice tone="info">{t('move_empty_need')}</Notice>
		{:else}
			<div class="rf-grid">
				<div class="rf-field">
					<label for="rafoo-move-dest">{t('move_empty_server')}</label>
					<select id="rafoo-move-dest" class="rf-input" bind:value={destination}>
						<option value="">—</option>
						{#each status.destinations as item (item.id)}<option value={item.id}>{item.label}</option>{/each}
					</select>
				</div>
				<div class="rf-field">
					<label for="rafoo-move-folder">{t('move_empty_folder')}</label>
					<input id="rafoo-move-folder" class="rf-input rf-mono" spellcheck="false" bind:value={folder} />
				</div>
				<div class="rf-field">
					<label for="rafoo-move-url">{t('move_empty_url')}</label>
					<input id="rafoo-move-url" class="rf-input rf-mono" type="url" placeholder="https://example.ir" spellcheck="false" bind:value={url} />
				</div>
			</div>
			<div class="rf-row">
				<Button variant="primary" busy={busy === 'move'} disabled={busy !== '' || running || destination === '' || url.trim() === ''} onclick={() => act('move', () => api<BackupStatus>('backup/move', 'POST', { destination, folder, url: url.trim() }))}>{t('move_empty_start')}</Button>
			</div>
		{/if}
		{#if notice}
			<div class="rf-code">
				<Notice tone="success">{t('move_empty_ready')}</Notice>
				<p class="rf-small">{t('move_empty_open')} <a class="rf-mono" href={notice.url} target="_blank" rel="noopener">{notice.url}</a></p>
				<label for="rafoo-move-key" class="rf-small">{t('move_empty_key')}</label>
				<input id="rafoo-move-key" class="rf-input rf-mono" readonly value={notice.key} />
				<div class="rf-row"><Button size="sm" onclick={() => copy(notice?.key ?? '')}>{copied ? t('move_copied') : t('move_copy')}</Button></div>
			</div>
		{/if}
	{/if}
</Card>

<style>
	.rf-code {
		display: flex;
		flex-direction: column;
		gap: 8px;
		padding: 12px;
		border: 1px solid var(--rf-l2);
		border-radius: var(--rf-r-control);
		background: var(--rf-l1);
	}
	.rf-big {
		font-size: var(--rf-fs-lg);
		letter-spacing: 0.08em;
	}
	.rf-grid {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
		gap: 16px;
	}
	.rf-check {
		display: flex;
		align-items: center;
		gap: 8px;
		font-size: var(--rf-fs-sm);
	}
</style>
