<script lang="ts">
	import Badge from '../components/Badge.svelte';
	import Button from '../components/Button.svelte';
	import Card from '../components/Card.svelte';
	import Notice from '../components/Notice.svelte';
	import { api } from '../lib/api';
	import { ago, bytes, help, t } from '../lib/i18n';
	import type { BackupStatus } from '../lib/types';

	interface Props {
		status: BackupStatus;
		onupdate: (next: BackupStatus) => void;
	}

	let { status, onupdate }: Props = $props();

	const form = $state({ host: '', user: '', pass: '', key: '', path: '', label: '' });
	let more = $state(false);
	let busy = $state('');
	let error = $state('');
	let message = $state('');

	const types: Record<string, string> = { sftp: 'SFTP', ftps: 'FTPS', 'ftps-implicit': 'FTPS', ftp: 'FTP' };

	async function act(id: string, task: () => Promise<BackupStatus>, done: (next: BackupStatus) => string = () => '') {
		busy = id;
		error = '';
		message = '';
		try {
			const next = await task();
			onupdate(next);
			message = done(next);
		} catch (e) {
			error = e instanceof Error ? e.message : t('error_generic');
		}
		busy = '';
	}

	function connect(event: SubmitEvent) {
		event.preventDefault();
		return act(
			'add',
			() => api<BackupStatus>('backup/destinations/add', 'POST', { ...form }),
			(next) => {
				const found = next.added;
				form.host = form.user = form.pass = form.key = form.path = form.label = '';
				return found ? t('destination_added', types[found.type] ?? found.type, found.path, bytes(found.speed)) : '';
			}
		);
	}
</script>

<Card title={t('destinations_title')} help={help('card_destinations')} description={t('destinations_help')}>
	{#if message}<Notice tone="success" live>{message}</Notice>{/if}
	{#if error}<Notice tone="danger" live>{error}</Notice>{/if}

	{#if status.destinations.length > 0}
		<ul class="rf-list-rows">
			{#each status.destinations as item (item.id)}
				<li>
					<div class="rf-dest">
						<div class="rf-row">
							<strong>{item.label}</strong>
							<Badge tone={item.plain ? 'warning' : 'neutral'}>{types[item.type] ?? item.type}</Badge>
							{#if item.last_error}<Badge tone="danger">{t('destination_error')}</Badge>{:else if item.last_ok}<Badge tone="success">{t('destination_ok', ago(item.last_ok))}</Badge>{/if}
						</div>
						<span class="rf-mono rf-subtle rf-small">{item.user}@{item.host}:{item.port}{item.path}</span>
						{#if item.plain}<span class="rf-small">{t('destination_plain')}</span>{/if}
						{#if item.speed}<span class="rf-subtle rf-small">{t('destination_speed', bytes(item.speed))}{item.free > 0 ? ' · ' + t('destination_free', bytes(item.free)) : ''}</span>{/if}
					</div>
					<div class="rf-row">
						<Button size="sm" variant="ghost" busy={busy === 'test' + item.id} disabled={busy !== ''} onclick={() => act('test' + item.id, () => api<BackupStatus>('backup/destinations/test', 'POST', { id: item.id }), () => t('destination_tested'))}>{t('destination_test')}</Button>
						<Button size="sm" variant="ghost" busy={busy === 'remove' + item.id} disabled={busy !== ''} onclick={() => act('remove' + item.id, () => api<BackupStatus>('backup/destinations/delete', 'POST', { id: item.id }))}>{t('delete')}</Button>
					</div>
				</li>
			{/each}
		</ul>
	{/if}

	<form class="rf-stack rf-add" onsubmit={connect} novalidate>
		<h3>{t('destination_add')}</h3>
		<div class="rf-grid">
			<div class="rf-field">
				<label for="rafoo-dest-host">{t('destination_host')}</label>
				<input id="rafoo-dest-host" class="rf-input rf-mono" placeholder="backup.example.ir" autocomplete="off" spellcheck="false" bind:value={form.host} />
			</div>
			<div class="rf-field">
				<label for="rafoo-dest-user">{t('relay_user')}</label>
				<input id="rafoo-dest-user" class="rf-input rf-mono" autocomplete="off" spellcheck="false" bind:value={form.user} />
			</div>
			<div class="rf-field">
				<label for="rafoo-dest-pass">{t('relay_pass')}</label>
				<input id="rafoo-dest-pass" class="rf-input rf-mono" type="password" autocomplete="new-password" bind:value={form.pass} />
			</div>
		</div>
		<button type="button" class="rf-more" aria-expanded={more} onclick={() => (more = !more)}>{t('destination_more')}</button>
		{#if more}
			<div class="rf-grid">
				<div class="rf-field">
					<label for="rafoo-dest-path">{t('destination_path')}</label>
					<input id="rafoo-dest-path" class="rf-input rf-mono" placeholder="rafoo-backups" spellcheck="false" bind:value={form.path} />
				</div>
				<div class="rf-field">
					<label for="rafoo-dest-label">{t('destination_label')}</label>
					<input id="rafoo-dest-label" class="rf-input" bind:value={form.label} />
				</div>
			</div>
			<div class="rf-field">
				<label for="rafoo-dest-key">{t('destination_key')}</label>
				<textarea id="rafoo-dest-key" class="rf-input rf-mono" rows="3" spellcheck="false" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----" bind:value={form.key}></textarea>
			</div>
		{/if}
		<p class="rf-subtle rf-small">{t('destination_detect_help')}</p>
		<div class="rf-row rf-end">
			<Button type="submit" variant="primary" busy={busy === 'add'} disabled={busy !== '' || form.host.trim() === ''}>{busy === 'add' ? t('destination_detecting') : t('destination_connect')}</Button>
		</div>
	</form>
</Card>

<style>
	.rf-dest {
		display: flex;
		flex-direction: column;
		gap: 2px;
		min-inline-size: 0;
	}
	.rf-add {
		padding-block-start: 8px;
	}
	.rf-grid {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
		gap: 16px;
	}
	.rf-end {
		justify-content: flex-end;
	}
</style>
