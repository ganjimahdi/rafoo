<script lang="ts">
	import { onDestroy, onMount } from 'svelte';
	import Badge from '../components/Badge.svelte';
	import Button from '../components/Button.svelte';
	import Card from '../components/Card.svelte';
	import Notice from '../components/Notice.svelte';
	import Toggle from '../components/Toggle.svelte';
	import { api } from '../lib/api';
	import { ago, bytes, date, help, hour as clock, list, num, percent as pct, t } from '../lib/i18n';
	import { store } from '../lib/store.svelte';
	import type { BackupEntry, BackupStatus, Settings } from '../lib/types';
	import Destinations from '../sections/Destinations.svelte';
	import Move from '../sections/Move.svelte';

	let status = $state.raw<BackupStatus | null>(null);
	let busy = $state('');
	let error = $state('');
	let message = $state('');
	let confirm = $state('');
	let restorePassword = $state('');
	let password = $state('');
	let timer: ReturnType<typeof setTimeout> | null = null;
	let incremental = $state(store.settings.backup_mode === 'incremental');

	const schedule = $state({
		backup: store.settings.backup,
		backup_hours: store.settings.backup_hours,
		backup_hour: store.settings.backup_hour,
		backup_mode: store.settings.backup_mode,
		backup_local: store.settings.backup_local
	});

	const job = $derived(status?.job ?? null);
	const running = $derived(job?.status === 'running');
	const names = $derived(Object.fromEntries((status?.destinations ?? []).map((d) => [d.id, d.label])));

	function update(next: BackupStatus) {
		const wasRunning = running;
		status = next;
		if (next.job?.status === 'running') poll();
		else if (wasRunning) store.refresh().catch(() => {});
	}

	function poll() {
		if (timer) return;
		timer = setTimeout(async () => {
			timer = null;
			try {
				update(await api<BackupStatus>('backup/step', 'POST'));
			} catch {
				poll();
			}
		}, 1200);
	}

	async function act(id: string, task: () => Promise<BackupStatus | void>, done = '') {
		busy = id;
		error = '';
		message = '';
		try {
			const result = await task();
			if (result) update(result);
			message = done;
		} catch (e) {
			error = e instanceof Error ? e.message : t('error_generic');
		}
		busy = '';
	}

	onMount(() => {
		act('load', () => api<BackupStatus>('backup'));
	});

	onDestroy(() => {
		if (timer) clearTimeout(timer);
	});

	function stepLabel(step: string): string {
		const key = 'backup_step_' + step.replace(/^remote-/, '');
		const label = t(key);
		return (step.startsWith('remote-') ? t('backup_step_remote') + ' ' : '') + (label === key ? step : label);
	}

	function errorLabel(code: string): string {
		const key = 'backup_error_' + code.replace(/^remote: /, '');
		const label = t(key);
		return label === key ? code : label;
	}

	function kindLabel(entry: BackupEntry): string {
		return entry.kind === 'full' ? t('backup_full') : t('backup_incremental');
	}

	function places(entry: BackupEntry): string[] {
		return [...(entry.local ? [t('backup_here')] : []), ...entry.places.map((id) => names[id] ?? id)];
	}

	async function saveSchedule() {
		await act(
			'schedule',
			async () => {
				await store.saveSettings({ ...schedule, backup_mode: incremental ? 'incremental' : 'full' } as Partial<Settings>);
				return api<BackupStatus>('backup');
			},
			t('settings_saved')
		);
	}

	function percent(): number {
		if (!job || !job.progress.total) return 0;
		return Math.min(100, Math.round((job.progress.done / job.progress.total) * 100));
	}
</script>

<div class="rf-stack">
	{#if status && !status.support.zip}<Notice tone="danger">{t('backup_no_zip')}</Notice>{/if}
	{#if status && !status.support.writable}<Notice tone="danger">{t('backup_not_writable')}</Notice>{/if}
	{#if status?.frozen}<Notice tone="warning">{t('backup_frozen')}</Notice>{/if}

	<Card title={t('backup_title')} help={help('card_backup')} description={t('backup_intro')}>
		{#snippet actions()}
			<Button variant="primary" onclick={() => act('run', () => api<BackupStatus>('backup/run', 'POST', {}))} busy={busy === 'run'} disabled={busy !== '' || running || !status?.support.zip}>
				{t('backup_now')}
			</Button>
		{/snippet}

		{#if message}<Notice tone="success" live>{message}</Notice>{/if}
		{#if error}<Notice tone="danger" live>{error}</Notice>{/if}

		{#if status === null}
			<p class="rf-subtle">{t('loading')}</p>
		{:else}
			<div class="rf-facts">
				<div><span class="rf-subtle rf-small">{t('backup_last')}</span><strong>{status.last ? ago(status.last) : t('never')}</strong></div>
				<div><span class="rf-subtle rf-small">{t('backup_next')}</span><strong>{status.next ? date(status.next) : t('backup_off')}</strong></div>
				<div><span class="rf-subtle rf-small">{t('backup_free')}</span><strong>{status.support.free >= 0 ? bytes(status.support.free) : '—'}</strong></div>
			</div>

			{#if job && job.status === 'running'}
				<div class="rf-job" aria-live="polite">
					<div class="rf-spread">
						<span>{t('backup_job_' + job.kind)} · {stepLabel(job.step)}</span>
						<Button size="sm" variant="ghost" onclick={() => act('cancel', () => api<BackupStatus>('backup/cancel', 'POST'))} busy={busy === 'cancel'}>{t('backup_cancel')}</Button>
					</div>
					<progress max="100" value={percent()} aria-label={t('backup_progress')}></progress>
					{#if job.progress.total}<span class="rf-subtle rf-small">{pct(percent())}</span>{/if}
				</div>
			{:else if job && job.status === 'failed' && job.error !== 'cancelled'}
				<Notice tone="danger">{t('backup_failed', errorLabel(job.error))}</Notice>
			{:else if job && job.status === 'stalled'}
				<Notice tone="warning">{t('backup_stalled')}</Notice>
			{:else if job && job.status === 'done' && job.notice === 'login_again'}
				<Notice tone="success">{t('backup_restored')}</Notice>
			{/if}
		{/if}
	</Card>

	{#if status}
		<Card title={t('backup_list_title')} help={help('card_backup_list')}>
			{#if status.backups.length === 0}
				<p class="rf-subtle">{t('backup_empty')}</p>
			{:else}
				<div class="rf-table-wrap">
					<table class="rf-table rf-backups">
						<caption class="rf-sr">{t('backup_list_title')}</caption>
						<thead>
							<tr>
								<th scope="col">{t('col_date')}</th>
								<th scope="col">{t('col_kind')}</th>
								<th scope="col">{t('col_size')}</th>
								<th scope="col">{t('col_where')}</th>
								<th scope="col"><span class="rf-sr">{t('col_actions')}</span></th>
							</tr>
						</thead>
						<tbody>
							{#each status.backups as entry (entry.id)}
								<tr>
									<td>
										<div class="rf-cell">
											<span>{date(entry.created)}</span>
											<span class="rf-subtle rf-small">{t('backup_counts', num(entry.files), num(entry.tables))}</span>
										</div>
									</td>
									<td>
										<div class="rf-row">
											<span>{kindLabel(entry)}</span>
											{#if entry.encrypted}<Badge tone="info">{t('backup_encrypted')}</Badge>{/if}
											{#if !entry.complete}<Badge tone="warning">{t('backup_incomplete')}</Badge>{/if}
										</div>
									</td>
									<td class="rf-small">{bytes(entry.size)}</td>
									<td class="rf-small">{list(places(entry)) || '—'}</td>
									<td>
										<div class="rf-actions">
											{#if entry.local}
												<a class="rf-link-button" href={status.download + '&id=' + encodeURIComponent(entry.id)}>{t('backup_download')}</a>
											{/if}
											<Button size="sm" variant="ghost" disabled={busy !== '' || running || !entry.complete} onclick={() => ((confirm = confirm === entry.id ? '' : entry.id), (restorePassword = ''))}>
												{t('backup_restore')}
											</Button>
											<Button size="sm" variant="ghost" disabled={busy !== '' || running} busy={busy === 'delete' + entry.id} onclick={() => act('delete' + entry.id, () => api<BackupStatus>('backup/delete', 'POST', { id: entry.id }))}>
												{t('delete')}
											</Button>
										</div>
									</td>
								</tr>
								{#if confirm === entry.id}
									<tr>
										<td colspan="5">
											<div class="rf-confirm">
												<Notice tone="warning">{t('backup_restore_warning', date(entry.created))}</Notice>
												{#if entry.encrypted && !status.password}
													<div class="rf-field">
														<label for="rafoo-restore-pass">{t('backup_password')}</label>
														<input id="rafoo-restore-pass" class="rf-input" type="password" autocomplete="off" bind:value={restorePassword} />
													</div>
												{/if}
												<div class="rf-row">
													<Button
														variant="danger"
														busy={busy === 'restore'}
														onclick={() => act('restore', async () => {
															const result = await api<BackupStatus>('backup/restore', 'POST', { id: entry.id, password: restorePassword });
															confirm = '';
															return result;
														})}
													>
														{t('backup_restore_confirm')}
													</Button>
													<Button variant="ghost" onclick={() => (confirm = '')}>{t('setup_back')}</Button>
												</div>
											</div>
										</td>
									</tr>
								{/if}
							{/each}
						</tbody>
					</table>
				</div>
			{/if}
		</Card>

		<Card title={t('backup_schedule_title')} help={help('card_backup_schedule')} description={t('backup_schedule_help')}>
			<Toggle label={t('backup_label')} help={help('backup')} description={t('backup_help')} bind:checked={schedule.backup} />
			<div class="rf-grid">
				<div class="rf-field">
					<label for="rafoo-backup-hours">{t('backup_every')}</label>
					<select id="rafoo-backup-hours" class="rf-input" bind:value={schedule.backup_hours}>
						{#each [6, 12, 24, 48, 168] as hours (hours)}
							<option value={hours}>{t('backup_every_' + hours)}</option>
						{/each}
					</select>
				</div>
				<div class="rf-field">
					<label for="rafoo-backup-hour">{t('backup_at')}</label>
					<select id="rafoo-backup-hour" class="rf-input" bind:value={schedule.backup_hour} disabled={schedule.backup_hours < 24}>
						{#each Array.from({ length: 24 }, (_, i) => i) as hour (hour)}
							<option value={hour}>{clock(hour)}</option>
						{/each}
					</select>
				</div>
			</div>
			<Toggle label={t('backup_mode_label')} help={help('backup_mode')} description={t('backup_mode_help')} bind:checked={incremental} />
			<Toggle label={t('backup_local_label')} help={help('backup_local')} description={t('backup_local_help')} bind:checked={schedule.backup_local} />
			<div class="rf-save">
				<Button variant="primary" onclick={saveSchedule} busy={busy === 'schedule'}>{t('save')}</Button>
			</div>
		</Card>

		<Destinations {status} onupdate={update} />

		<Card title={t('backup_password_title')} help={help('card_backup_password')} description={t('backup_password_help')}>
			{#if !status.support.crypt}
				<Notice tone="warning">{t('backup_no_crypt')}</Notice>
			{:else}
				<p class="rf-small">{status.password ? t('backup_password_on') : t('backup_password_off')}</p>
				<div class="rf-row">
					<input class="rf-input rf-narrow" type="password" autocomplete="new-password" aria-label={t('backup_password')} placeholder={t('backup_password')} bind:value={password} />
					<Button onclick={() => act('password', async () => { const r = await api<BackupStatus>('backup/password', 'POST', { password }); password = ''; return r; }, t('backup_password_saved'))} busy={busy === 'password'} disabled={password.length < 8}>
						{t('save')}
					</Button>
					{#if status.password}
						<Button variant="ghost" onclick={() => act('nopass', () => api<BackupStatus>('backup/password', 'POST', { password: '' }), t('backup_password_removed'))} busy={busy === 'nopass'}>{t('backup_password_remove')}</Button>
					{/if}
				</div>
			{/if}
		</Card>

		<Move {status} {running} onupdate={update} />
	{/if}
</div>

<style>
	.rf-facts {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
		gap: 12px;
	}
	.rf-facts > div {
		display: flex;
		flex-direction: column;
		gap: 2px;
		padding: 12px;
		border: 1px solid var(--rf-l2);
		border-radius: var(--rf-r-control);
	}
	.rf-job {
		display: flex;
		flex-direction: column;
		gap: 8px;
	}
	.rf-job progress {
		inline-size: 100%;
		block-size: 8px;
		accent-color: var(--rf-l6);
	}
	.rf-cell {
		display: flex;
		flex-direction: column;
		gap: 2px;
	}
	.rf-actions {
		display: flex;
		flex-wrap: wrap;
		justify-content: flex-end;
		gap: 4px;
	}
	.rf-confirm {
		display: flex;
		flex-direction: column;
		gap: 12px;
	}
	.rf-grid {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
		gap: 16px;
		max-inline-size: 640px;
	}
	.rf-narrow {
		max-inline-size: 280px;
	}
	.rf-save {
		display: flex;
		justify-content: flex-end;
	}
	.rf-link-button {
		display: inline-flex;
		align-items: center;
		min-block-size: var(--rf-control-sm);
		padding-inline: 10px;
		border-radius: var(--rf-r-control);
		color: var(--rf-l6);
		font-size: var(--rf-fs-sm);
		text-decoration: none;
	}
	.rf-link-button:hover {
		background: var(--rf-l1);
	}
</style>
