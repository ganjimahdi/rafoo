<script lang="ts">
	import { onDestroy, onMount } from 'svelte';
	import Badge from '../components/Badge.svelte';
	import Button from '../components/Button.svelte';
	import Card from '../components/Card.svelte';
	import Help from '../components/Help.svelte';
	import Notice from '../components/Notice.svelte';
	import Toggle from '../components/Toggle.svelte';
	import { api } from '../lib/api';
	import { ago, bytes, date, duration, help, hour as clock, list, num, percent as pct, t } from '../lib/i18n';
	import { store } from '../lib/store.svelte';
	import type { BackupEntry, BackupJob, BackupStatus, BackupWarning, Settings } from '../lib/types';
	import Destinations from '../sections/Destinations.svelte';
	import Move from '../sections/Move.svelte';

	const PARTS = [128, 256, 512, 1024, 2048] as const;

	let status = $state.raw<BackupStatus | null>(null);
	let busy = $state('');
	let error = $state('');
	let message = $state('');
	let open = $state('');
	let secret = $state('');
	let password = $state('');
	let exclude = $state((store.settings.backup_exclude ?? []).join('\n'));
	let showExcluded = $state(false);
	let logOpen = $state(false);
	let log = $state<string | null>(null);
	let copied = $state(false);
	let eta = $state(0);
	let timer: ReturnType<typeof setTimeout> | null = null;
	let samples: { at: number; done: number }[] = [];
	let sampled = '';
	let incremental = $state(store.settings.backup_mode === 'incremental');

	const schedule = $state({
		backup: store.settings.backup,
		backup_hours: store.settings.backup_hours,
		backup_hour: store.settings.backup_hour,
		backup_mode: store.settings.backup_mode,
		backup_local: store.settings.backup_local,
		backup_part: store.settings.backup_part ?? 256
	});

	const job = $derived(status?.job ?? null);
	const running = $derived(job?.status === 'running');
	const names = $derived(Object.fromEntries((status?.destinations ?? []).map((d) => [d.id, d.label])));
	const oldZip = $derived(!!status && !status.support.zip && status.backups.some((entry) => entry.format < 2));

	function update(next: BackupStatus) {
		const wasRunning = running;
		status = next;
		measure(next.job);
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

	function where(current: BackupJob): string {
		if (current.step === 'backup' && current.phase) return current.phase;
		if (current.step === 'wait' && current.phase.startsWith('remote-')) return current.phase.slice(7);
		return current.step;
	}

	function measure(current: BackupJob | null) {
		if (!current || current.status !== 'running' || !current.progress.total || where(current) === 'upload') {
			samples = [];
			sampled = '';
			eta = 0;
			return;
		}
		const key = [current.id, current.step, current.phase, current.progress.total].join(':');
		const now = Date.now() / 1000;
		if (key !== sampled) {
			sampled = key;
			samples = [];
		}
		samples = [...samples, { at: now, done: current.progress.done }].filter((sample) => now - sample.at <= 120);
		const span = now - samples[0].at;
		const moved = current.progress.done - samples[0].done;
		eta = span >= 15 && moved > 0 ? ((current.progress.total - current.progress.done) * span) / moved : 0;
	}

	function stepLabel(step: string): string {
		const key = 'backup_step_' + step.replace(/^remote-/, '');
		const label = t(key);
		return (step.startsWith('remote-') ? t('backup_step_remote') + ' ' : '') + (label === key ? step : label);
	}

	function phaseLabel(current: BackupJob): string {
		const nested = (current.step === 'backup' && current.phase !== '') || (current.step === 'wait' && current.phase.startsWith('remote-'));
		return stepLabel(current.step) + (nested ? ' · ' + stepLabel(current.phase) : '');
	}

	function infoLine(current: BackupJob): string {
		const info = current.info;
		const n = (key: string) => Number(info[key] ?? 0);
		const text = (key: string) => String(info[key] ?? '');
		switch (where(current)) {
			case 'db':
				return text('table') ? t('backup_info_db', text('table'), n('index'), n('tables')) : '';
			case 'scan':
				return t('backup_info_scan', n('files'), bytes(n('bytes')));
			case 'pack':
				return t('backup_info_pack', n('files') + n('unchanged'), n('total'), n('part')) + (n('unchanged') ? ' · ' + t('backup_info_unchanged', n('unchanged')) : '');
			case 'upload':
				return text('server') ? t('backup_info_upload', text('server'), n('index'), n('parts')) : '';
			case 'part':
				if (text('server')) return t('backup_info_fetch', text('server'), n('part'), n('parts'));
				return n('part') ? t('backup_info_part', n('part'), n('parts')) : '';
			case 'unpack':
				return t('backup_info_part', n('part'), n('parts')) + (current.kind !== 'check' && n('restored') ? ' · ' + t('backup_info_restored', n('restored')) : '');
		}
		return '';
	}

	function bare(code: string): string {
		return code.replace(/^remote: /, '');
	}

	function reason(code: string): string {
		const key = 'backup_error_' + bare(code);
		const label = t(key);
		return (code.startsWith('remote: ') ? t('backup_step_remote') + ' ' : '') + (label === key ? bare(code) : label);
	}

	function fix(current: BackupJob): string {
		const code = bare(current.error);
		const key = 'backup_fix_' + code + (code === 'disk_full' && current.detail.stream === true ? '_stream' : '');
		const text = t(key);
		return text === key ? '' : text;
	}

	function facts(current: BackupJob): string[] {
		const detail = current.detail;
		const code = bare(current.error);
		const out: string[] = [];
		if (typeof detail.free === 'number' && detail.free >= 0) out.push(t('backup_detail_free', bytes(detail.free)));
		if (typeof detail.needed === 'number') out.push(t('backup_detail_needed', bytes(detail.needed)));
		if (code === 'php_memory' && detail.limit) out.push(t('backup_detail_memory', String(detail.limit)));
		if (code === 'php_time' && detail.limit) out.push(t('backup_detail_time', Number(detail.limit)));
		if (code === 'php_killed' && detail.seconds) out.push(t('backup_detail_step', Number(detail.seconds)));
		for (const key of ['server', 'table', 'path', 'part']) {
			if (typeof detail[key] === 'string' && detail[key] !== '') out.push(t('backup_detail_' + key, String(detail[key])));
		}
		return out;
	}

	function technical(current: BackupJob): string {
		for (const key of ['error', 'message', 'reason']) {
			const value = current.detail[key];
			if (typeof value === 'string' && value !== '') return value;
		}
		return '';
	}

	function warningLabel(warning: BackupWarning): string {
		const key = 'backup_warn_' + warning.code;
		const label = t(key, warning.count);
		return label === key ? warning.code + ': ' + num(warning.count) : label;
	}

	function kindLabel(entry: BackupEntry): string {
		return entry.kind === 'full' ? t('backup_full') : t('backup_incremental');
	}

	function places(entry: BackupEntry): string[] {
		return [...(entry.local ? [t('backup_here')] : []), ...entry.places.map((id) => names[id] ?? id)];
	}

	function link(entry: BackupEntry, part: number): string {
		return (status?.download ?? '') + '&id=' + encodeURIComponent(entry.id) + '&part=' + part;
	}

	function toggle(name: string) {
		open = open === name ? '' : name;
		secret = '';
	}

	function needsSecret(entry: BackupEntry): boolean {
		return entry.encrypted && !status?.password;
	}

	function check(entry: BackupEntry) {
		if (needsSecret(entry) && open !== 'check' + entry.id) {
			toggle('check' + entry.id);
			return;
		}
		act('check' + entry.id, async () => {
			const result = await api<BackupStatus>('backup/check', 'POST', { id: entry.id, password: secret });
			open = '';
			return result;
		});
	}

	async function toggleLog() {
		logOpen = !logOpen;
		if (!logOpen) return;
		log = null;
		try {
			log = (await api<{ log: string }>('backup/log')).log;
		} catch {
			log = '';
		}
	}

	async function copyLog() {
		try {
			await navigator.clipboard.writeText(log ?? '');
			copied = true;
			setTimeout(() => (copied = false), 2000);
		} catch {
			copied = false;
		}
	}

	async function saveSchedule() {
		await act(
			'schedule',
			async () => {
				await store.saveSettings({
					...schedule,
					backup_mode: incremental ? 'incremental' : 'full',
					backup_exclude: exclude
						.split(/\r?\n/)
						.map((line) => line.trim())
						.filter(Boolean)
				} as Partial<Settings>);
				exclude = store.settings.backup_exclude.join('\n');
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

{#snippet notes(warnings: BackupWarning[])}
	<ul class="rf-notes">
		{#each warnings as warning (warning.code)}
			<li>
				<span>{warningLabel(warning)}</span>
				{#if warning.samples.length}
					<ul class="rf-samples rf-mono rf-small">
						{#each warning.samples as sample, index (index)}<li>{sample}</li>{/each}
					</ul>
				{/if}
			</li>
		{/each}
	</ul>
{/snippet}

<div class="rf-stack">
	{#if oldZip}<Notice tone="warning">{t('backup_old_zip')}</Notice>{/if}
	{#if status && !status.support.writable}<Notice tone="danger">{t('backup_not_writable')}</Notice>{/if}
	{#if status?.frozen}<Notice tone="warning">{t('backup_frozen')}</Notice>{/if}

	<Card title={t('backup_title')} help={help('card_backup')} description={t('backup_intro')}>
		{#snippet actions()}
			<Button variant="primary" onclick={() => act('run', () => api<BackupStatus>('backup/run', 'POST', {}))} busy={busy === 'run'} disabled={busy !== '' || running || !status?.support.writable}>
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
						<span>{t('backup_job_' + job.kind)} · {phaseLabel(job)}</span>
						<Button size="sm" variant="ghost" onclick={() => act('cancel', () => api<BackupStatus>('backup/cancel', 'POST'))} busy={busy === 'cancel'}>{t('backup_cancel')}</Button>
					</div>
					{#if job.progress.total}
						<progress max="100" value={percent()} aria-label={t('backup_progress')}></progress>
					{:else}
						<progress aria-label={t('backup_progress')}></progress>
					{/if}
					<div class="rf-spread rf-subtle rf-small">
						<span>{[job.progress.total ? pct(percent()) : '', infoLine(job)].filter(Boolean).join(' · ')}</span>
						{#if eta >= 30}<span>{t('backup_eta', duration(eta))}</span>{/if}
					</div>
					{#if job.retried}<p class="rf-subtle rf-small">{t('backup_retried')}</p>{/if}
				</div>
			{:else if job && job.status === 'failed' && job.error !== 'cancelled'}
				<Notice tone="danger">
					<div class="rf-problem">
						<strong>{t('backup_failed', reason(job.error))}</strong>
						{#if facts(job).length}<span>{facts(job).join(' · ')}</span>{/if}
						{#if technical(job)}<span class="rf-mono">{technical(job)}</span>{/if}
						{#if fix(job)}<span>{fix(job)}</span>{/if}
					</div>
				</Notice>
			{:else if job && job.status === 'stalled'}
				<Notice tone="warning">{t('backup_stalled')}</Notice>
			{:else if job && job.status === 'done' && job.notice === 'login_again'}
				<Notice tone="success">{t('backup_restored')}</Notice>
			{:else if job && job.status === 'done' && job.notice === 'checked'}
				<Notice tone="success">{t('backup_checked')}</Notice>
			{/if}

			{#if job && !running && job.warnings.length}
				<Notice tone="warning">
					<div class="rf-problem">
						<strong>{t('backup_notes_title')}</strong>
						{@render notes(job.warnings)}
					</div>
				</Notice>
			{/if}

			{#if job && !running}
				<button type="button" class="rf-more" aria-expanded={logOpen} aria-controls="rafoo-backup-log" onclick={toggleLog}>{t('backup_log_show')}</button>
				{#if logOpen}
					<div id="rafoo-backup-log" class="rf-log">
						{#if log === null}
							<p class="rf-subtle rf-small">{t('loading')}</p>
						{:else if log === ''}
							<p class="rf-subtle rf-small">{t('backup_log_empty')}</p>
						{:else}
							<textarea class="rf-input rf-mono rf-log-text" rows="12" readonly spellcheck="false" dir="ltr" aria-label={t('backup_log_show')} value={log}></textarea>
							<div class="rf-row">
								<Button size="sm" onclick={copyLog}>{copied ? t('move_copied') : t('move_copy')}</Button>
							</div>
						{/if}
					</div>
				{/if}
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
											{#if entry.warnings.length}
												<button type="button" class="rf-more" aria-expanded={open === 'notes' + entry.id} onclick={() => toggle('notes' + entry.id)}>{t('backup_notes_show')}</button>
											{/if}
										</div>
									</td>
									<td>
										<div class="rf-row">
											<span>{kindLabel(entry)}</span>
											{#if entry.encrypted}<Badge tone="info">{t('backup_encrypted')}</Badge>{/if}
											{#if !entry.complete}<Badge tone="warning">{t('backup_incomplete')}</Badge>{/if}
											{#if entry.format < 2}<Badge>{t('backup_old_format')}</Badge>{/if}
										</div>
									</td>
									<td>
										<div class="rf-cell rf-small">
											<span>{bytes(entry.size)}</span>
											{#if entry.parts > 1}<span class="rf-subtle">{t('backup_parts', entry.parts)}</span>{/if}
										</div>
									</td>
									<td class="rf-small">{list(places(entry)) || '—'}</td>
									<td>
										<div class="rf-actions">
											{#if entry.local && entry.parts > 1}
												<Button size="sm" variant="ghost" aria-expanded={open === 'parts' + entry.id} onclick={() => toggle('parts' + entry.id)}>{t('backup_download')}</Button>
											{:else if entry.local}
												<a class="rf-link-button" href={link(entry, 0)}>{t('backup_download')}</a>
											{/if}
											{#if entry.format >= 2}
												<Button size="sm" variant="ghost" disabled={busy !== '' || running || !entry.complete} busy={busy === 'check' + entry.id && open !== 'check' + entry.id} onclick={() => check(entry)}>
													{t('backup_check')}
												</Button>
											{/if}
											<Button size="sm" variant="ghost" disabled={busy !== '' || running || !entry.complete} onclick={() => toggle('restore' + entry.id)}>
												{t('backup_restore')}
											</Button>
											<Button size="sm" variant="ghost" disabled={busy !== '' || running} busy={busy === 'delete' + entry.id} onclick={() => act('delete' + entry.id, () => api<BackupStatus>('backup/delete', 'POST', { id: entry.id }))}>
												{t('delete')}
											</Button>
										</div>
									</td>
								</tr>
								{#if open === 'restore' + entry.id}
									<tr>
										<td colspan="5">
											<div class="rf-confirm">
												<Notice tone="warning">{t('backup_restore_warning', date(entry.created))}</Notice>
												{#if needsSecret(entry)}
													<div class="rf-field">
														<label for="rafoo-restore-pass">{t('backup_password')}</label>
														<input id="rafoo-restore-pass" class="rf-input rf-narrow" type="password" autocomplete="off" bind:value={secret} />
													</div>
												{/if}
												<div class="rf-row">
													<Button
														variant="danger"
														busy={busy === 'restore'}
														onclick={() => act('restore', async () => {
															const result = await api<BackupStatus>('backup/restore', 'POST', { id: entry.id, password: secret });
															open = '';
															return result;
														})}
													>
														{t('backup_restore_confirm')}
													</Button>
													<Button variant="ghost" onclick={() => (open = '')}>{t('setup_back')}</Button>
												</div>
											</div>
										</td>
									</tr>
								{:else if open === 'check' + entry.id}
									<tr>
										<td colspan="5">
											<div class="rf-confirm">
												<p class="rf-small">{t('backup_check_help')}</p>
												<div class="rf-field">
													<label for="rafoo-check-pass">{t('backup_password')}</label>
													<input id="rafoo-check-pass" class="rf-input rf-narrow" type="password" autocomplete="off" bind:value={secret} />
												</div>
												<div class="rf-row">
													<Button variant="primary" busy={busy === 'check' + entry.id} disabled={secret === ''} onclick={() => check(entry)}>{t('backup_check')}</Button>
													<Button variant="ghost" onclick={() => (open = '')}>{t('setup_back')}</Button>
												</div>
											</div>
										</td>
									</tr>
								{:else if open === 'parts' + entry.id}
									<tr>
										<td colspan="5">
											<div class="rf-confirm">
												<p class="rf-small">{entry.encrypted ? t('backup_parts_encrypted') : t('backup_parts_help')}</p>
												<div class="rf-row">
													{#each Array.from({ length: entry.parts }, (_, index) => index) as index (index)}
														<a class="rf-link-button rf-bordered" href={link(entry, index)}>{t('backup_part_n', index + 1)}</a>
													{/each}
													<a class="rf-link-button rf-bordered" href={link(entry, entry.parts)}>{t('backup_part_list')}</a>
												</div>
											</div>
										</td>
									</tr>
								{:else if open === 'notes' + entry.id}
									<tr>
										<td colspan="5">
											<div class="rf-confirm rf-small">
												{@render notes(entry.warnings)}
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
			<div class="rf-field">
				<span class="rf-field-label"><label for="rafoo-backup-part">{t('backup_part_label')}</label><Help text={help('backup_part')} label={t('backup_part_label')} /></span>
				<p id="rafoo-backup-part-help" class="rf-subtle rf-small">{t('backup_part_help')}</p>
				<select id="rafoo-backup-part" class="rf-input rf-narrow" aria-describedby="rafoo-backup-part-help" bind:value={schedule.backup_part}>
					{#each PARTS as size (size)}
						<option value={size}>{bytes(size * 1048576)}</option>
					{/each}
				</select>
			</div>
			<div class="rf-field">
				<span class="rf-field-label"><label for="rafoo-backup-exclude">{t('backup_exclude_label')}</label><Help text={help('backup_exclude')} label={t('backup_exclude_label')} /></span>
				<p id="rafoo-backup-exclude-help" class="rf-subtle rf-small">{t('backup_exclude_help')}</p>
				<textarea id="rafoo-backup-exclude" class="rf-input rf-mono" rows="4" aria-describedby="rafoo-backup-exclude-help" bind:value={exclude} spellcheck="false" dir="ltr"></textarea>
				<button type="button" class="rf-more" aria-expanded={showExcluded} aria-controls="rafoo-backup-auto" onclick={() => (showExcluded = !showExcluded)}>{t('backup_excluded_show')}</button>
				{#if showExcluded}
					<div id="rafoo-backup-auto" class="rf-auto">
						<p class="rf-subtle rf-small">{t('backup_excluded_help')}</p>
						<ul class="rf-paths rf-mono rf-small">
							{#each status.support.excluded as path (path)}<li>{path}</li>{/each}
						</ul>
					</div>
				{/if}
			</div>
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
	.rf-problem {
		display: flex;
		flex-direction: column;
		gap: 4px;
	}
	.rf-notes {
		display: flex;
		flex-direction: column;
		gap: 6px;
		margin: 0;
		padding-inline-start: 18px;
	}
	.rf-notes > li {
		margin: 0;
	}
	.rf-samples {
		margin: 2px 0 0;
		padding: 0;
		list-style: none;
	}
	.rf-samples li {
		margin: 0;
	}
	.rf-log {
		display: flex;
		flex-direction: column;
		gap: 8px;
	}
	.rf-log .rf-log-text {
		max-inline-size: none;
		background: var(--rf-l1);
		color: var(--rf-l5);
		font-size: var(--rf-fs-xs);
		line-height: 1.6;
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
	.rf-auto {
		display: flex;
		flex-direction: column;
		gap: 6px;
	}
	.rf-paths {
		margin: 0;
		padding: 0;
		list-style: none;
		color: var(--rf-l4);
	}
	.rf-paths li {
		margin: 0;
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
		border: 1px solid transparent;
		border-radius: var(--rf-r-control);
		color: var(--rf-l6);
		font-size: var(--rf-fs-sm);
		text-decoration: none;
	}
	.rf-link-button:hover {
		background: var(--rf-l1);
	}
	.rf-bordered {
		border-color: var(--rf-l2);
	}
</style>
