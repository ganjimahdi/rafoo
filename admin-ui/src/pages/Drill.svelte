<script lang="ts">
	import { onMount, tick } from 'svelte';
	import Badge from '../components/Badge.svelte';
	import Button from '../components/Button.svelte';
	import Card from '../components/Card.svelte';
	import Choices from '../components/Choices.svelte';
	import Guide from '../components/Guide.svelte';
	import Help from '../components/Help.svelte';
	import Notice from '../components/Notice.svelte';
	import Stat from '../components/Stat.svelte';
	import Toggle from '../components/Toggle.svelte';
	import { api } from '../lib/api';
	import { date, help, num, t } from '../lib/i18n';
	import { store } from '../lib/store.svelte';
	import type { DrillCandidate, DrillClient, DrillFix, DrillFrame, DrillIssue, DrillKind, DrillPagePlan, DrillReadiness, DrillReport, DrillSession, DrillSeverity, DrillView } from '../lib/types';

	const SETTLE = 2500;
	const TIMEOUT = 30000;
	const SEVERITIES: DrillSeverity[] = ['blocker', 'warning', 'info', 'handled'];
	const GROUPS: { id: string; kinds: DrillKind[] }[] = [
		{ id: 'site', kinds: ['home', 'post', 'page', 'login'] },
		{ id: 'store', kinds: ['shop', 'product', 'cart', 'checkout', 'account'] },
		{ id: 'admin', kinds: ['dashboard', 'editor'] }
	];

	interface Result {
		id: number;
		frame: DrillFrame;
		client: DrillClient | null;
		base: DrillClient | null;
	}

	interface Live {
		id: number;
		state: 'wait' | 'normal' | 'outage' | 'done';
		load: number;
		blocked: number;
		frame: DrillFrame;
	}

	let view = $state.raw<DrillView | null>(null);
	let error = $state('');
	let busy = $state('');
	let mode = $state<'frame' | 'manual'>('frame');
	let kinds = $state<DrillKind[]>([]);
	let custom = $state('');
	let guest = $state(true);
	let compare = $state(true);
	let running = $state(false);
	let phase = $state<'normal' | 'outage' | 'report'>('outage');
	let index = $state(0);
	let live = $state<Live[]>([]);
	let fixes = $state<Record<string, { state: string; error?: string }>>({});
	let showHandled = $state(false);
	let guideOpen = $state(false);
	let frame: HTMLIFrameElement | undefined = $state();
	let cancelled = false;
	let now = $state(Date.now() / 1000);

	const active = $derived(view?.active ?? null);
	const report = $derived(view?.report ?? null);
	const candidates = $derived(view?.candidates ?? []);
	const left = $derived(active ? Math.max(0, Math.round((active.expires - now) / 60)) : 0);
	const interrupted = $derived(!!active && !active.manual && !running);
	const customList = $derived(
		custom
			.split(/\s+/)
			.map((line) => line.trim())
			.filter(Boolean)
	);
	const canStart = $derived(!!view && !view.paused && (mode === 'manual' || kinds.length > 0 || customList.length > 0) && customList.length <= 5);

	function apply(next: DrillView) {
		view = next;
		store.refresh().catch(() => {});
	}

	async function call(key: string, path: string, method: 'GET' | 'POST' = 'GET', body?: Record<string, unknown>): Promise<DrillView | null> {
		busy = key;
		error = '';
		try {
			const next = await api<DrillView>(path, method, body);
			view = next;
			return next;
		} catch (e) {
			error = e instanceof Error ? e.message : t('error_generic');
			return null;
		} finally {
			busy = '';
		}
	}

	onMount(() => {
		call('load', 'drill').then((next) => {
			if (!next) return;
			kinds = next.prefs.kinds.filter((kind) => next.candidates.some((c) => c.kind === kind));
			custom = next.prefs.custom.join('\n');
			guest = next.prefs.guest;
			compare = next.prefs.compare;
		});
		const tick = setInterval(() => (now = Date.now() / 1000), 15000);
		return () => {
			clearInterval(tick);
			cancelled = true;
		};
	});

	function toggleKind(kind: DrillKind, on: boolean) {
		kinds = on ? [...kinds.filter((k) => k !== kind), kind] : kinds.filter((k) => k !== kind);
	}

	function candidate(kind: DrillKind): DrillCandidate | undefined {
		return candidates.find((c) => c.kind === kind);
	}

	async function start() {
		const next = await call('start', 'drill/start', 'POST', { kinds, custom: customList, guest, manual: mode === 'manual', compare });
		if (next?.active && !next.active.manual) await runFrames(next.active);
	}

	async function runFrames(session: DrillSession) {
		running = true;
		cancelled = false;
		live = session.pages.map((page): Live => ({ id: page.id, state: 'wait', load: 0, blocked: 0, frame: 'ok' }));
		await tick();
		const results: Result[] = [];
		for (const [i, page] of session.pages.entries()) {
			if (cancelled) break;
			index = i;
			let base: DrillClient | null = null;
			if (compare) {
				phase = 'normal';
				mark(page.id, { state: 'normal' });
				base = (await load(page.base)).client;
			}
			if (cancelled) break;
			phase = 'outage';
			mark(page.id, { state: 'outage' });
			const outcome = await load(page.url);
			results.push({ id: page.id, frame: outcome.frame, client: outcome.client, base });
			mark(page.id, { state: 'done', load: outcome.client?.l ?? 0, blocked: outcome.client?.x.length ?? 0, frame: outcome.frame });
		}
		if (frame) frame.src = 'about:blank';
		if (cancelled) {
			running = false;
			return;
		}
		phase = 'report';
		const next = await call('finish', 'drill/finish', 'POST', { run: session.run, results });
		running = false;
		fixes = {};
		if (next) apply(next);
	}

	function mark(id: number, change: Partial<Live>) {
		live = live.map((row) => (row.id === id ? { ...row, ...change } : row));
	}

	function load(url: string): Promise<{ frame: DrillFrame; client: DrillClient | null }> {
		return new Promise((resolve) => {
			const el = frame;
			if (!el) {
				resolve({ frame: 'error', client: null });
				return;
			}
			let done = false;
			let settle: ReturnType<typeof setTimeout> | undefined;
			const finish = (state: DrillFrame) => {
				if (done) return;
				done = true;
				clearTimeout(timer);
				clearTimeout(settle);
				el.onload = null;
				let client: DrillClient | null = null;
				let result = state;
				try {
					const win = el.contentWindow as (Window & { rafooGuard?: { collect?: () => DrillClient } }) | null;
					const guard = win?.rafooGuard;
					if (guard && typeof guard.collect === 'function') client = guard.collect();
					else if (win && win.document) client = fallback(win);
				} catch {
					result = 'blocked';
				}
				resolve({ frame: result, client });
			};
			const timer = setTimeout(() => finish('timeout'), TIMEOUT);
			el.onload = () => {
				clearTimeout(settle);
				settle = setTimeout(() => finish('ok'), SETTLE);
			};
			el.src = url;
		});
	}

	function fallback(win: Window): DrillClient | null {
		const nav = win.performance?.getEntriesByType('navigation')[0] as PerformanceNavigationTiming | undefined;
		if (!nav) return null;
		return { u: win.location.href, s: nav.responseStatus ?? 0, l: Math.round((nav.loadEventEnd || win.performance.now()) - nav.startTime), r: [], x: [], e: [], g: [] };
	}

	async function cancel() {
		cancelled = true;
		running = false;
		if (frame) frame.src = 'about:blank';
		await call('cancel', 'drill/cancel', 'POST');
	}

	async function finishManual() {
		if (!active) return;
		const next = await call('finish', 'drill/finish', 'POST', { run: active.run, results: [] });
		fixes = {};
		if (next) apply(next);
	}

	async function fix(issue: string, item: DrillFix) {
		const key = issue + ':' + item.a + ':' + item.t;
		const next = await call(key, 'drill/fix', 'POST', { action: item.a, target: item.t, issue });
		if (next) {
			fixes = { ...fixes, [key]: { state: next.done?.state ?? 'done', error: next.done?.error } };
			store.refresh().catch(() => {});
		} else {
			fixes = { ...fixes, [key]: { state: 'failed', error } };
		}
	}

	function readinessFix(item: DrillReadiness): DrillFix {
		if (item.fix === 'resume' || item.fix === 'sync' || item.fix === 'scan') return { a: item.fix, t: '' };
		return { a: 'setting', t: item.fix };
	}

	function pageLabel(page: { kind: DrillKind; url: string; title?: string }): string {
		if (page.kind === 'custom' || page.kind === 'visit') return path(page.url);
		const base = t('drill_kind_' + page.kind);
		return page.title ? base + ' · ' + page.title : base;
	}

	function path(url: string): string {
		try {
			const u = new URL(url, location.href);
			u.searchParams.delete('rafoo-drill');
			u.searchParams.delete('rafoo-drill-base');
			return decodeURI(u.pathname + u.search) || '/';
		} catch {
			return url;
		}
	}

	function seconds(ms: number): string {
		return ms > 0 ? t('drill_seconds', Math.round(ms / 100) / 10) : '—';
	}

	function origin(id: string): string {
		const name = report?.names[id] ?? id.replace(/^[a-z]+:/, '');
		if (id === 'core') return t('drill_from_core');
		if (id.startsWith('theme:')) return t('drill_from_theme', name);
		return t('drill_from_plugin', name);
	}

	function families(url: string): string {
		try {
			const u = new URL(url);
			const names = u.searchParams.getAll('family').map((f) => f.split(':')[0].replace(/\+/g, ' '));
			return names.length ? names.join('، ') : u.hostname;
		} catch {
			return url;
		}
	}

	function base(name: string): string {
		return name.split('?')[0].split('/').pop() || path(name);
	}

	function subject(issue: DrillIssue): string {
		const m = issue.meta;
		switch (issue.cat) {
			case 'library':
				return [m.package && m.version ? m.package + '@' + m.version : '', m.file].filter(Boolean).join(' · ');
			case 'fonts':
				return typeof m.url === 'string' ? families(m.url) : issue.subject;
			case 'js_error':
			case 'php_error':
				return String(m.message ?? '');
			case 'http_error':
				return t('drill_code', Number(m.code));
			case 'slow_page':
				return seconds(Number(m.ms));
			case 'cached':
			case 'frame':
				return '';
			default:
				return issue.subject;
		}
	}

	function detail(issue: DrillIssue): string {
		if (issue.cat === 'js_error' && issue.meta.normal) return t('drill_why_js_error_normal');
		if (issue.sev === 'handled' && !['server', 'embed', 'fonts'].includes(issue.cat)) return t('drill_why_handled');
		if (issue.cat === 'http_error') return t('drill_why_http_error', Number(issue.meta.code));
		if (issue.cat === 'slow_page') return t('drill_why_slow_page', seconds(Number(issue.meta.ms)));
		if (issue.cat === 'server_unknown') return t('drill_why_server_unknown', seconds(Number(issue.meta.ms)));
		if (issue.cat === 'frame') return t('drill_why_frame_' + String(issue.meta.reason ?? 'error'));
		const key = 'drill_why_' + issue.cat + (issue.cat === 'fonts' && issue.sev === 'handled' ? '_handled' : '');
		return t(key);
	}

	function where(issue: DrillIssue): string {
		const m = issue.meta;
		if ((issue.cat === 'js_error' || issue.cat === 'php_error') && m.file) return base(String(m.file)) + (m.line ? ':' + m.line : '');
		return '';
	}

	function fixLabel(item: DrillFix): string {
		switch (item.a) {
			case 'setting':
				return t('drill_fix_setting', t(item.t + '_label'));
			case 'block':
				return t('drill_fix_block', item.t);
			case 'allow':
				return t('drill_fix_allow');
			default:
				return t('drill_fix_' + item.a);
		}
	}

	function fixState(issue: string, item: DrillFix): { state: string; error?: string } | undefined {
		return fixes[issue + ':' + item.a + ':' + item.t];
	}

	function tone(sev: DrillSeverity): 'danger' | 'warning' | 'info' | 'success' {
		return sev === 'blocker' ? 'danger' : sev === 'warning' ? 'warning' : sev === 'info' ? 'info' : 'success';
	}

	function gradeTone(grade: DrillReport['grade']): 'success' | 'info' | 'warning' | 'danger' {
		return grade === 'ready' ? 'success' : grade === 'good' ? 'info' : grade === 'weak' ? 'warning' : 'danger';
	}

	function statusTone(status: string): 'success' | 'warning' | 'danger' | 'neutral' {
		return status === 'ok' ? 'success' : status === 'warning' ? 'warning' : status === 'broken' ? 'danger' : 'neutral';
	}

	function pagesOf(issue: DrillIssue): string {
		if (!report) return '';
		return issue.pages
			.map((id) => report.pages.find((p) => p.id === id))
			.filter((p) => !!p)
			.map((p) => pageLabel(p))
			.join(' · ');
	}

	const grouped = $derived.by(() => {
		const map: Record<DrillSeverity, DrillIssue[]> = { blocker: [], warning: [], info: [], handled: [] };
		for (const issue of report?.issues ?? []) map[issue.sev].push(issue);
		return map;
	});

	const counts = $derived({
		blocker: grouped.blocker.length,
		warning: grouped.warning.length,
		info: grouped.info.length,
		handled: grouped.handled.length
	});

	const slower = $derived.by(() => {
		const rows = (report?.pages ?? []).filter((p) => p.normal > 0 && p.load > 0);
		if (!rows.length) return null;
		const normal = rows.reduce((sum, p) => sum + p.normal, 0) / rows.length;
		const outage = rows.reduce((sum, p) => sum + p.load, 0) / rows.length;
		return { normal, outage };
	});

	const current = $derived<DrillPagePlan | null>(active && running ? (active.pages[index] ?? null) : null);
	const readiness = $derived(view?.readiness ?? []);
	const notReady = $derived(readiness.filter((item) => !item.ok));
</script>

{#snippet issueRow(issue: DrillIssue)}
	{@const fixed = report?.fixed.includes(issue.id)}
	<li class="rf-issue">
		<div class="rf-issue-main">
			<div class="rf-issue-title">
				<strong>{t('drill_cat_' + issue.cat)}</strong>
				{#if issue.meta.blocking && issue.sev === 'blocker'}<Badge tone="danger">{t('drill_blocking')}</Badge>{/if}
				{#if fixed}<Badge tone="success">{t('drill_fixed')}</Badge>{/if}
			</div>
			{#if subject(issue)}<span class="rf-mono rf-small rf-issue-subject">{subject(issue)}</span>{/if}
			<p class="rf-small rf-subtle">{detail(issue)}</p>
			<div class="rf-issue-meta rf-small rf-subtle">
				{#if issue.pages.length}<span>{t('drill_on_pages', pagesOf(issue))}</span>{/if}
				{#if where(issue)}<span class="rf-mono">{where(issue)}</span>{/if}
				{#if issue.origins.length}<span>{issue.origins.map(origin).join('، ')}</span>{/if}
				{#if issue.cat === 'library' && issue.meta.error}<span>{t('drill_last_error', String(issue.meta.error))}</span>{/if}
			</div>
		</div>
		{#if issue.fix.length}
			<div class="rf-issue-fix">
				{#each issue.fix as item (item.a + item.t)}
					{@const state = fixState(issue.id, item)}
					{#if state && state.state !== 'failed'}
						<Badge tone={state.state === 'ready' || state.state === 'done' ? 'success' : 'info'}>{t('drill_fix_state_' + state.state)}</Badge>
					{:else}
						<Button size="sm" variant={item.a === 'download' || item.a === 'setting' || item.a === 'include' ? 'primary' : 'secondary'} busy={busy === issue.id + ':' + item.a + ':' + item.t} disabled={busy !== '' || running} onclick={() => fix(issue.id, item)}>{fixLabel(item)}</Button>
					{/if}
					{#if state?.error}<span class="rf-small rf-danger">{state.error}</span>{/if}
				{/each}
			</div>
		{/if}
	</li>
{/snippet}

<div class="rf-stack">
	{#if error}<Notice tone="danger" live>{error}</Notice>{/if}

	<Card title={t('drill_title')} help={help('card_drill')} description={t('drill_intro')}>
		<ul class="rf-promise">
			<li>{t('drill_point_1')}</li>
			<li>{t('drill_point_2')}</li>
			<li>{t('drill_point_3')}</li>
		</ul>
		{#if view?.offline}<Notice tone="info">{t('drill_real_outage')}</Notice>{/if}
	</Card>

	{#if view === null}
		<p class="rf-subtle">{t('loading')}</p>
	{:else if running && active}
		<Card title={t('drill_running')} help={help('card_drill_progress')} description={current ? t('drill_progress', index + 1, active.pages.length, pageLabel(current)) : t('drill_building')}>
			{#snippet actions()}
				<Button size="sm" variant="danger" busy={busy === 'cancel'} onclick={cancel}>{t('drill_cancel')}</Button>
			{/snippet}
			<div class="rf-bar" role="progressbar" aria-label={t('drill_running')} aria-valuemin={0} aria-valuemax={active.pages.length} aria-valuenow={phase === 'report' ? active.pages.length : index}>
				<span style:inline-size={(100 * (phase === 'report' ? active.pages.length : index + (phase === 'outage' ? 0.5 : 0))) / active.pages.length + '%'}></span>
			</div>
			<p class="rf-small rf-subtle" aria-live="polite">{phase === 'report' ? t('drill_phase_report') : phase === 'normal' ? t('drill_phase_normal') : t('drill_phase_outage')}</p>
			<div class="rf-run">
				<ol class="rf-run-list">
					{#each live as row (row.id)}
						{@const page = active.pages.find((p) => p.id === row.id)}
						<li class={['rf-run-row', row.state !== 'wait' && row.state !== 'done' && 'rf-now']}>
							<bdi class="rf-run-name">{page ? pageLabel(page) : ''}</bdi>
							<span class="rf-small rf-subtle">
								{#if row.state === 'done'}
									{row.frame === 'ok' ? seconds(row.load) : t('drill_frame_' + row.frame)}{row.blocked ? ' · ' + t('drill_live_blocked', row.blocked) : ''}
								{:else if row.state === 'wait'}
									{t('drill_wait')}
								{:else}
									{t('drill_state_' + row.state)}
								{/if}
							</span>
						</li>
					{/each}
				</ol>
				<div class="rf-preview">
					<span class="rf-small rf-subtle">{t('drill_preview')}</span>
					<iframe bind:this={frame} title={t('drill_preview')} sandbox="allow-scripts allow-same-origin allow-forms allow-popups" tabindex="-1" src="about:blank"></iframe>
				</div>
			</div>
		</Card>
	{:else if active?.manual}
		<Card title={t('drill_manual_title')} help={help('card_drill_manual')} description={t('drill_manual_left', left)}>
			<ol class="rf-promise">
				<li>{t('drill_manual_1')}</li>
				<li>{t('drill_manual_2')}</li>
				<li>{t('drill_manual_3')}</li>
			</ol>
			<div class="rf-row">
				<a class="rf-link-btn" href={active.home} target="_blank" rel="noopener">{t('drill_manual_open')}</a>
				<Button variant="primary" busy={busy === 'finish'} onclick={finishManual}>{t('drill_finish')}</Button>
				<Button variant="ghost" busy={busy === 'cancel'} onclick={cancel}>{t('drill_cancel')}</Button>
			</div>
		</Card>
	{:else if interrupted && active}
		<Card title={t('drill_interrupted')} help={help('card_drill_progress')} description={t('drill_interrupted_desc')}>
			<div class="rf-row">
				<Button variant="primary" busy={busy === 'finish'} onclick={() => runFrames(active)}>{t('drill_restart')}</Button>
				<Button variant="ghost" busy={busy === 'cancel'} onclick={cancel}>{t('drill_cancel')}</Button>
			</div>
		</Card>
	{:else}
		<Card title={t('drill_options')} help={help('card_drill_options')}>
			{#if view.paused}
				<Notice tone="warning">
					<span>{t('drill_paused')}</span>
					{#if view.network}<Button size="sm" busy={busy.includes(':resume:')} onclick={() => fix('paused', { a: 'resume', t: '' })}>{t('drill_fix_resume')}</Button>{/if}
				</Notice>
			{/if}
			<Choices
				name="rafoo-drill-mode"
				legend={t('drill_mode')}
				help={help('drill_mode')}
				options={[
					{ value: 'frame', label: t('drill_mode_frame'), description: t('drill_mode_frame_desc') },
					{ value: 'manual', label: t('drill_mode_manual'), description: t('drill_mode_manual_desc') }
				]}
				bind:value={mode}
			/>
			{#if mode === 'frame'}
				<fieldset class="rf-pages">
					<legend><span class="rf-field-label">{t('drill_pages')}<Help text={help('drill_pages')} label={t('drill_pages')} /></span></legend>
					{#each GROUPS as group (group.id)}
						{@const items = group.kinds.map(candidate).filter((c) => !!c)}
						{#if items.length}
							<div class="rf-page-group">
								<span class="rf-small rf-subtle">{t('drill_group_' + group.id)}</span>
								<div class="rf-checks">
									{#each items as item (item.kind)}
										<label class="rf-check">
											<input type="checkbox" checked={kinds.includes(item.kind)} onchange={(e) => toggleKind(item.kind, e.currentTarget.checked)} />
											<span>{t('drill_kind_' + item.kind)}</span>
										</label>
									{/each}
								</div>
							</div>
						{/if}
					{/each}
				</fieldset>
				<div class="rf-field">
					<span class="rf-field-label"><label for="rf-drill-custom">{t('drill_custom')}</label><Help text={help('drill_custom')} label={t('drill_custom')} /></span>
					<textarea id="rf-drill-custom" class="rf-input rf-mono" rows="3" bind:value={custom} placeholder={t('drill_custom_placeholder')} aria-invalid={customList.length > 5} aria-describedby="rf-drill-custom-desc"></textarea>
					<span id="rf-drill-custom-desc" class="rf-small rf-subtle">{t('drill_custom_desc')}</span>
				</div>
				<Toggle label={t('drill_compare')} description={t('drill_compare_desc')} help={help('drill_compare')} bind:checked={compare} />
			{/if}
			<Toggle label={t('drill_guest')} description={t('drill_guest_desc')} help={help('drill_guest')} bind:checked={guest} />
			<div class="rf-row">
				<Button variant="primary" busy={busy === 'start'} disabled={!canStart || busy !== ''} onclick={start}>{mode === 'manual' ? t('drill_start_manual') : t('drill_start')}</Button>
				<span class="rf-small rf-subtle">{t('drill_safe')}</span>
			</div>
		</Card>
	{/if}

	{#if report && !running}
		<Card title={t('drill_result')} help={help('card_drill_score')} description={t('drill_result_desc', date(report.at), report.pages.length)}>
			<div class="rf-score">
				<div class="rf-score-num">
					<span class="rf-score-value">{num(report.score)}</span>
					<span class="rf-subtle">{t('drill_of_100')}</span>
				</div>
				<div class="rf-score-text">
					<Badge tone={gradeTone(report.grade)}>{t('drill_grade_' + report.grade)}</Badge>
					<p>{t('drill_grade_' + report.grade + '_desc')}</p>
					{#if slower}<p class="rf-small rf-subtle">{t('drill_compare_result', seconds(slower.normal), seconds(slower.outage))}</p>{/if}
					{#if report.mode === 'manual'}<p class="rf-small rf-subtle">{t('drill_result_manual')}</p>{/if}
				</div>
			</div>
			<div class="rf-stats">
				<Stat label={t('drill_stat_blocker')} value={num(counts.blocker)} />
				<Stat label={t('drill_stat_warning')} value={num(counts.warning)} />
				<Stat label={t('drill_stat_handled')} value={num(counts.handled + report.stats.refused)} hint={t('drill_stat_handled_hint')} />
				<Stat label={t('drill_stat_local')} value={num(report.stats.local)} hint={t('drill_stat_local_hint')} />
			</div>
		</Card>

		{#each SEVERITIES as sev (sev)}
			{#if grouped[sev].length && (sev !== 'handled' || showHandled)}
				<Card title={t('drill_sev_' + sev, grouped[sev].length)} help={help('drill_sev_' + sev)}>
					<ul class="rf-issues">
						{#each grouped[sev] as issue (issue.id)}
							{@render issueRow(issue)}
						{/each}
					</ul>
				</Card>
			{/if}
		{/each}
		{#if grouped.handled.length && !showHandled}
			<button type="button" class="rf-more" onclick={() => (showHandled = true)}>{t('drill_show_handled', grouped.handled.length)}</button>
		{/if}
		{#if !report.issues.length}
			<Notice tone="success">{t('drill_no_issues')}</Notice>
		{/if}

		<Card title={t('drill_pages_result')} help={help('card_drill_pages')}>
			<div class="rf-table-wrap">
				<table class="rf-table">
					<thead>
						<tr>
							<th scope="col">{t('drill_col_page')}</th>
							<th scope="col">{t('drill_col_state')}</th>
							<th scope="col">{t('drill_col_normal')}</th>
							<th scope="col">{t('drill_col_outage')}</th>
							<th scope="col">{t('drill_col_server')}</th>
						</tr>
					</thead>
					<tbody>
						{#each report.pages as page (page.id)}
							<tr>
								<td><bdi class="rf-page-name">{pageLabel(page)}</bdi></td>
								<td><Badge tone={statusTone(page.status)}>{t('drill_status_' + page.status)}</Badge></td>
								<td class="rf-small">{seconds(page.normal)}</td>
								<td class="rf-small">{page.frame === 'ok' || page.frame === 'manual' ? seconds(page.load) : t('drill_frame_' + page.frame)}</td>
								<td class="rf-small">{seconds(page.server)}{page.code ? ' · ' + num(page.code) : ''}</td>
							</tr>
						{/each}
					</tbody>
				</table>
			</div>
		</Card>
	{/if}

	{#if view && !running}
		<Card title={t('drill_ready')} help={help('card_drill_ready')} description={notReady.length ? t('drill_ready_some', notReady.length) : t('drill_ready_all')}>
			<ul class="rf-list-rows">
				{#each readiness as item (item.key)}
					{@const action = readinessFix(item)}
					{@const state = fixState('ready_' + item.key, action)}
					<li>
						<div class="rf-ready-text">
							<span>{t('drill_ready_' + item.key)}</span>
							{#if !item.ok}<span class="rf-small rf-subtle">{t('drill_ready_' + item.key + '_bad', Number(item.meta.pending ?? 0), Number(item.meta.failed ?? 0))}</span>{/if}
						</div>
						<div class="rf-row">
							{#if item.ok || state?.state === 'done' || state?.state === 'ready'}
								<Badge tone="success">{t('drill_ok')}</Badge>
							{:else}
								<Badge tone={tone(item.sev)}>{t('drill_sev_short_' + item.sev)}</Badge>
								{#if item.fix && (item.fix !== 'resume' || view.network)}
									<Button size="sm" busy={busy === 'ready_' + item.key + ':' + action.a + ':' + action.t} disabled={busy !== ''} onclick={() => fix('ready_' + item.key, action)}>{t('drill_ready_fix_' + item.fix)}</Button>
								{/if}
							{/if}
						</div>
					</li>
				{/each}
			</ul>
		</Card>
	{/if}

	{#if view?.history.length && !running}
		<Card title={t('drill_history')} help={help('card_drill_history')}>
			<div class="rf-table-wrap">
				<table class="rf-table">
					<thead>
						<tr>
							<th scope="col">{t('drill_col_date')}</th>
							<th scope="col">{t('drill_col_score')}</th>
							<th scope="col">{t('drill_col_pages')}</th>
							<th scope="col">{t('drill_col_found')}</th>
						</tr>
					</thead>
					<tbody>
						{#each view.history as row (row.run)}
							<tr>
								<td class="rf-small">{date(row.at)}</td>
								<td><Badge tone={gradeTone(row.grade)}>{num(row.score)}</Badge></td>
								<td class="rf-small">{row.mode === 'manual' ? t('drill_mode_manual') : num(row.pages)}</td>
								<td class="rf-small">{t('drill_history_counts', row.counts.blocker, row.counts.warning, row.counts.handled)}</td>
							</tr>
						{/each}
					</tbody>
				</table>
			</div>
		</Card>
	{/if}

	<Card title={t('drill_guide_title')} help={help('card_drill_guide')}>
		<Guide label={t('drill_guide_open')} bind:open={guideOpen} steps={[t('drill_guide_1'), t('drill_guide_2'), t('drill_guide_3'), t('drill_guide_4'), t('drill_guide_5'), t('drill_guide_6')]} note={t('drill_guide_note')} />
	</Card>
</div>

<style>
	.rf-promise {
		display: flex;
		flex-direction: column;
		gap: 8px;
		margin: 0;
		padding-inline-start: 18px;
		color: var(--rf-l4);
		line-height: 1.8;
	}
	.rf-pages {
		display: flex;
		flex-direction: column;
		gap: 10px;
		margin: 0;
		padding: 0;
		border: 0;
	}
	.rf-pages legend {
		margin-block-end: 4px;
		padding: 0;
		font-weight: 700;
	}
	.rf-page-group {
		display: flex;
		flex-direction: column;
		gap: 6px;
	}
	.rf-checks {
		display: flex;
		flex-wrap: wrap;
		gap: 8px;
	}
	.rf-check {
		display: inline-flex;
		align-items: center;
		gap: 8px;
		min-block-size: var(--rf-control-sm);
		padding-inline: 10px;
		border: 1px solid var(--rf-l2);
		border-radius: var(--rf-r-control);
		background: var(--rf-l0);
		cursor: pointer;
		transition: border-color var(--rf-dur-1) var(--rf-ease-out);
	}
	.rf-check:hover {
		border-color: var(--rf-l3);
	}
	.rf-check:has(input:checked) {
		border-color: var(--rf-l5);
	}
	.rf-check input {
		margin: 0;
		box-shadow: none;
		accent-color: var(--rf-l5);
	}
	.rf-check:has(input:focus-visible) {
		outline: 2px solid var(--rf-focus);
		outline-offset: 2px;
	}
	.rf-check input:focus-visible {
		outline: none;
	}
	.rf-bar {
		overflow: hidden;
		block-size: 6px;
		border-radius: var(--rf-r-pill);
		background: var(--rf-l2);
	}
	.rf-bar span {
		display: block;
		block-size: 100%;
		background: var(--rf-l5);
		transition: inline-size var(--rf-dur-2) var(--rf-ease-out);
	}
	.rf-run {
		display: grid;
		grid-template-columns: minmax(0, 1fr) minmax(0, 2fr);
		gap: 16px;
		align-items: start;
	}
	.rf-run-list {
		display: flex;
		flex-direction: column;
		margin: 0;
		padding: 0;
		list-style: none;
	}
	.rf-run-row {
		display: flex;
		flex-direction: column;
		padding-block: 8px;
		padding-inline: 10px;
		border-inline-start: 2px solid transparent;
	}
	.rf-run-row + .rf-run-row {
		border-block-start: 1px solid var(--rf-l2);
	}
	.rf-run-row.rf-now {
		border-inline-start-color: var(--rf-l5);
		background: var(--rf-l1);
	}
	.rf-issue-subject {
		align-self: flex-start;
	}
	.rf-run-name,
	.rf-page-name {
		overflow-wrap: anywhere;
	}
	.rf-preview {
		display: flex;
		flex-direction: column;
		gap: 6px;
	}
	.rf-preview iframe {
		inline-size: 100%;
		block-size: 440px;
		border: 1px solid var(--rf-l2);
		border-radius: var(--rf-r-control);
		background: var(--rf-l0);
		pointer-events: none;
	}
	.rf-link-btn {
		display: inline-flex;
		align-items: center;
		min-block-size: var(--rf-control);
		padding-inline: 14px;
		border: 1px solid var(--rf-l2);
		border-radius: var(--rf-r-control);
		background: var(--rf-l0);
		color: var(--rf-l6);
		text-decoration: none;
	}
	.rf-link-btn:hover {
		background: var(--rf-l1);
	}
	.rf-score {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 20px;
	}
	.rf-score-num {
		display: flex;
		align-items: baseline;
		gap: 6px;
	}
	.rf-score-value {
		font-size: 40px;
		font-weight: 700;
		line-height: 1.1;
	}
	.rf-score-text {
		display: flex;
		flex-direction: column;
		align-items: flex-start;
		gap: 6px;
		min-inline-size: 0;
		flex: 1;
	}
	.rf-stats {
		display: grid;
		grid-template-columns: repeat(4, minmax(0, 1fr));
		gap: 12px;
	}
	.rf-issues {
		display: flex;
		flex-direction: column;
		margin: 0;
		padding: 0;
		list-style: none;
	}
	.rf-issue {
		display: flex;
		flex-wrap: wrap;
		align-items: flex-start;
		justify-content: space-between;
		gap: 12px;
		padding-block: 12px;
	}
	.rf-issue + .rf-issue {
		border-block-start: 1px solid var(--rf-l2);
	}
	.rf-issue-main {
		display: flex;
		flex-direction: column;
		gap: 4px;
		min-inline-size: 0;
		flex: 1 1 320px;
	}
	.rf-issue-title {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 8px;
	}
	.rf-issue-meta {
		display: flex;
		flex-wrap: wrap;
		gap: 4px 12px;
	}
	.rf-issue-fix {
		display: flex;
		flex-direction: column;
		align-items: flex-end;
		gap: 6px;
		flex: none;
	}
	.rf-danger {
		color: var(--rf-danger-fg);
	}
	.rf-ready-text {
		display: flex;
		flex-direction: column;
		min-inline-size: 0;
		flex: 1 1 260px;
	}
	@media (max-width: 960px) {
		.rf-run {
			grid-template-columns: 1fr;
		}
		.rf-stats {
			grid-template-columns: repeat(2, minmax(0, 1fr));
		}
		.rf-issue-fix {
			align-items: flex-start;
		}
	}
</style>
