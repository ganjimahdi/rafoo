<script lang="ts">
	import { untrack } from 'svelte';
	import Badge from '../components/Badge.svelte';
	import Button from '../components/Button.svelte';
	import Card from '../components/Card.svelte';
	import Choices from '../components/Choices.svelte';
	import Guide from '../components/Guide.svelte';
	import Help from '../components/Help.svelte';
	import Notice from '../components/Notice.svelte';
	import { bareHost, geo, hostOf, message, nodeName, routeText, steps } from '../lib/geo';
	import { ago, date, help, list, t } from '../lib/i18n';
	import type { GeoMethodType, GeoPanel, GeoProvider, GeoStatus, GeoTestResult } from '../lib/types';

	interface Props {
		status: GeoStatus;
		onupdate: (next: GeoStatus) => void;
	}

	let { status, onupdate }: Props = $props();

	const PROVIDERS: GeoProvider[] = ['arvan', 'gcore', 'cloudflare', 'route53', 'bunny'];
	const FIELDS: Record<GeoProvider, string[]> = {
		arvan: ['key'],
		cloudflare: ['token'],
		route53: ['access', 'secret'],
		gcore: ['token'],
		bunny: ['key']
	};
	const PANEL_PORT: Record<GeoPanel, number> = { cpanel: 2083, directadmin: 2222 };
	const TTLS = [60, 120, 300, 600];

	const start = untrack(() => ({ method: status.method }));
	let type = $state<Exclude<GeoMethodType, ''>>(start.method.type || 'provider');
	let provider = $state<GeoProvider>(start.method.provider || 'arvan');
	let panel = $state<GeoPanel>(start.method.panel || 'cpanel');
	let ttl = $state(start.method.ttl || 120);
	let keys = $state<Record<string, string>>({});
	let logins = $state<Record<string, string>>({});
	let busy = $state('');
	let error = $state('');
	let done = $state('');
	let test = $state.raw<Record<string, GeoTestResult> | null>(null);

	const saved = $derived(status.method);
	const changed = $derived(saved.type !== type || (type === 'provider' && saved.provider !== provider) || (type === 'panel' && saved.panel !== panel) || saved.ttl !== ttl);
	const main = $derived(bareHost(status.host));
	const routed = $derived(status.nodes.filter((node) => node.ip && node.routes && routeText(node) !== ''));
	const subs = $derived(status.nodes.filter((node) => node.ip).map((node) => ({ name: bareHost(hostOf(node.url)), ip: node.ip })));
	const names = $derived(Object.fromEntries(status.nodes.map((node) => [node.id, nodeName(node)])));

	async function act(id: string, task: () => Promise<GeoStatus>, success = '') {
		busy = id;
		error = '';
		done = '';
		try {
			const next = await task();
			if (next.test !== undefined) test = next.test ?? null;
			onupdate(next);
			done = success;
		} catch (e) {
			error = message(e);
		}
		busy = '';
	}

	function secrets(): Record<string, unknown> {
		if (type === 'provider') return Object.fromEntries(FIELDS[provider].map((field) => [field, keys[provider + '_' + field] ?? '']));
		if (type === 'panel') return Object.fromEntries(status.nodes.map((node) => [node.id, Object.fromEntries(['url', 'user', 'token'].map((field) => [field, logins[node.id + '_' + field] ?? '']))]));
		return {};
	}

	async function save() {
		await act(
			'save',
			async () => {
				const next = await geo('method', { type, provider: type === 'provider' ? provider : '', panel: type === 'panel' ? panel : '', ttl, secrets: secrets() });
				keys = {};
				logins = {};
				return next;
			},
			t('settings_saved')
		);
	}

	function stored(field: string): boolean {
		return saved.type === 'provider' && !!status.secrets[provider]?.[field];
	}

	function testLine(result: GeoTestResult): string {
		if (!result.ok) return result.error ?? t('error_generic');
		const servers = result.nameservers ?? [];
		return t('geo_test_ok', result.zone ?? '') + (servers.length ? ' · ' + t('geo_test_ns', list(servers)) : '');
	}
</script>

<Card title={t('geo_method_title')} help={help('card_geo_method')} description={t('geo_method_desc')}>
	{#if done}<Notice tone="success" live>{done}</Notice>{/if}
	{#if error}<Notice tone="danger" live>{error}</Notice>{/if}

	<Choices
		name="rafoo-geo-type"
		legend={t('geo_type_legend')}
		bind:value={type}
		options={[
			{ value: 'provider', label: t('geo_type_provider'), description: t('geo_type_provider_desc') },
			{ value: 'panel', label: t('geo_type_panel'), description: t('geo_type_panel_desc') },
			{ value: 'manual', label: t('geo_type_manual'), description: t('geo_type_manual_desc') }
		]}
	/>

	{#if type === 'provider'}
		<Choices name="rafoo-geo-provider" legend={t('geo_provider_legend')} bind:value={provider} options={PROVIDERS.map((value) => ({ value, label: t('geo_p_' + value), description: t('geo_p_' + value + '_desc') }))} />
		<Guide label={t('geo_guide_key_title', t('geo_p_' + provider))} steps={steps('geo_guide_' + provider)} note={t('geo_guide_key_note')} />
		<div class="rf-grid">
			{#each FIELDS[provider] as field (provider + field)}
				<div class="rf-field">
					<label for={'rafoo-geo-key-' + field}>{t('geo_f_' + provider + '_' + field)}</label>
					<input
						id={'rafoo-geo-key-' + field}
						class="rf-input rf-mono"
						type="password"
						autocomplete="off"
						spellcheck="false"
						placeholder={stored(field) ? t('geo_secret_saved') : ''}
						value={keys[provider + '_' + field] ?? ''}
						oninput={(event) => (keys[provider + '_' + field] = event.currentTarget.value)}
					/>
				</div>
			{/each}
		</div>
	{:else if type === 'panel'}
		<Choices
			name="rafoo-geo-panel"
			legend={t('geo_panel_legend')}
			bind:value={panel}
			options={[
				{ value: 'cpanel', label: 'cPanel', description: t('geo_panel_cpanel_desc') },
				{ value: 'directadmin', label: 'DirectAdmin', description: t('geo_panel_da_desc') }
			]}
		/>
		<Notice tone="info">{t('geo_panel_how')}</Notice>
		<Guide label={t('geo_guide_panel_key_title')} steps={steps(panel === 'cpanel' ? 'geo_guide_cpanel' : 'geo_guide_da')} />
		<p class="rf-subtle rf-small">{t('geo_panel_optional')}</p>
		{#each status.nodes as node (node.id)}
			{@const login = node.panel[panel]}
			<fieldset class="rf-login">
				<legend>{nodeName(node)} <span class="rf-mono rf-subtle rf-small">{hostOf(node.url)}</span></legend>
				{#if login.user && login.token}
					<p class="rf-small"><Badge tone="success">{t('geo_login_saved', login.user)}</Badge></p>
				{/if}
				<div class="rf-grid">
					{#each ['url', 'user', 'token'] as field (field)}
						{@const id = 'rafoo-geo-panel-' + field + '-' + node.id}
						<div class="rf-field">
							<label for={id}>{field === 'url' ? t('geo_panel_url') : field === 'user' ? t('geo_panel_user') : panel === 'cpanel' ? t('geo_panel_token') : t('geo_panel_key')}</label>
							<input
								{id}
								class="rf-input rf-mono"
								type={field === 'token' ? 'password' : field === 'url' ? 'url' : 'text'}
								autocomplete="off"
								spellcheck="false"
								placeholder={field === 'url' ? login.url || 'https://' + bareHost(hostOf(node.url)) + ':' + PANEL_PORT[panel] : field === 'user' ? login.user : login.token ? t('geo_secret_saved') : ''}
								value={logins[node.id + '_' + field] ?? ''}
								oninput={(event) => (logins[node.id + '_' + field] = event.currentTarget.value)}
							/>
						</div>
					{/each}
				</div>
			</fieldset>
		{/each}
	{:else}
		<Notice tone="info">{t('geo_manual_text')}</Notice>
	{/if}

	<div class="rf-field">
		<span class="rf-field-label"><label for="rafoo-geo-ttl">{t('geo_ttl')}</label><Help text={help('geo_ttl')} label={t('geo_ttl')} /></span>
		<select id="rafoo-geo-ttl" class="rf-input rf-narrow" bind:value={ttl}>
			{#each TTLS as value (value)}<option {value}>{t('geo_ttl_' + value)}</option>{/each}
		</select>
	</div>

	<div class="rf-row">
		<Button variant="primary" busy={busy === 'save'} disabled={busy !== ''} onclick={save}>{t('save')}</Button>
		{#if saved.type === 'provider' || saved.type === 'panel'}
			<Button busy={busy === 'test'} disabled={busy !== '' || changed} onclick={() => act('test', () => geo('test'))}>{t('geo_test')}</Button>
			<Button busy={busy === 'apply'} disabled={busy !== '' || changed} onclick={() => act('apply', () => geo('apply'), t('geo_applied'))}>{t('geo_apply')}</Button>
		{/if}
	</div>
	{#if changed && saved.type !== ''}<p class="rf-subtle rf-small">{t('geo_save_first')}</p>{/if}

	{#if test}
		<ul class="rf-list-rows rf-small">
			{#each Object.entries(test) as [id, result] (id)}
				<li>
					<span>{id === 'zone' ? t('geo_p_' + saved.provider) : (names[id] ?? id)}</span>
					<span class={['rf-mono', !result.ok && 'rf-bad']}>{testLine(result)}</span>
				</li>
			{/each}
		</ul>
	{/if}

	{#if saved.type === 'provider' || saved.type === 'panel'}
		{#if status.dns.at && status.dns.ok}
			<p class="rf-subtle rf-small">{t('geo_dns_ok', ago(status.dns.at))}{status.dns.by && names[status.dns.by] ? ' · ' + t('geo_dns_by', names[status.dns.by]) : ''}</p>
		{:else if status.dns.at && status.dns.error}
			<Notice tone="danger">{t('geo_dns_failed', status.dns.error)}</Notice>
		{:else}
			<p class="rf-subtle rf-small">{t('geo_dns_pending')}</p>
		{/if}
	{/if}
</Card>

<Card title={t('geo_records_title')} help={help('card_geo_records')} description={saved.type === 'panel' ? t('geo_records_panel_desc') : saved.type === 'manual' ? t('geo_records_manual_desc') : t('geo_records_provider_desc')}>
	{#if saved.type === ''}
		<p class="rf-subtle">{t('geo_records_none')}</p>
	{:else}
		{#if saved.type === 'manual' || saved.panel === 'cpanel'}
			<Guide label={t('geo_guide_a_cpanel_title')} steps={steps('geo_guide_a_cpanel')} note={t('geo_guide_a_note')} />
		{/if}
		{#if saved.type === 'manual' || saved.panel === 'directadmin'}
			<Guide label={t('geo_guide_a_da_title')} steps={steps('geo_guide_a_da')} note={t('geo_guide_a_note')} />
		{/if}

		{#if saved.type === 'panel'}
			{#each status.nodes.filter((node) => node.ip) as node (node.id)}
				<h3 class="rf-small">{t('geo_zone_of', nodeName(node))}</h3>
				<div class="rf-table-wrap">
					<table class="rf-table rf-small">
						<caption class="rf-sr">{t('geo_zone_of', nodeName(node))}</caption>
						<thead><tr><th scope="col">{t('geo_col_name')}</th><th scope="col">{t('geo_col_type')}</th><th scope="col">{t('geo_col_value')}</th></tr></thead>
						<tbody>
							<tr><td class="rf-mono">{main}</td><td>A</td><td class="rf-mono">{node.ip}</td></tr>
							{#each subs as sub (sub.name)}
								<tr><td class="rf-mono">{sub.name}</td><td>A</td><td class="rf-mono">{sub.ip}</td></tr>
							{/each}
						</tbody>
					</table>
				</div>
			{/each}
			<h3 class="rf-small">{t('geo_ns_title')}</h3>
			<p class="rf-small">{t('geo_ns_panel')}</p>
		{:else}
			<h3 class="rf-small">{t('geo_answers_title', main)}</h3>
			<div class="rf-table-wrap">
				<table class="rf-table rf-small">
					<caption class="rf-sr">{t('geo_answers_title', main)}</caption>
					<thead><tr><th scope="col">{t('geo_col_visitors')}</th><th scope="col">{t('geo_col_host')}</th><th scope="col">{t('geo_col_value')}</th></tr></thead>
					<tbody>
						{#each routed as node (node.id)}
							<tr><td>{routeText(node)}</td><td>{nodeName(node)}</td><td class="rf-mono">{node.ip}</td></tr>
						{/each}
					</tbody>
				</table>
			</div>
			<h3 class="rf-small">{t('geo_subs_title')}</h3>
			<div class="rf-table-wrap">
				<table class="rf-table rf-small">
					<caption class="rf-sr">{t('geo_subs_title')}</caption>
					<thead><tr><th scope="col">{t('geo_col_name')}</th><th scope="col">{t('geo_col_type')}</th><th scope="col">{t('geo_col_value')}</th></tr></thead>
					<tbody>
						{#each subs as sub (sub.name)}
							<tr><td class="rf-mono">{sub.name}</td><td>A</td><td class="rf-mono">{sub.ip}</td></tr>
						{/each}
					</tbody>
				</table>
			</div>
			<h3 class="rf-small">{t('geo_ns_title')}</h3>
			<p class="rf-small">{saved.type === 'manual' ? t('geo_ns_manual') : t('geo_ns_provider')}</p>
		{/if}
		<Guide label={t('geo_guide_irnic_title')} steps={steps('geo_guide_irnic')} />
		<Guide label={t('geo_guide_registrar_title')} steps={steps('geo_guide_registrar')} />
	{/if}
</Card>

<Card title={t('geo_verify_title')} help={help('card_geo_verify')} description={t('geo_verify_desc')}>
	{#snippet actions()}
		<Button size="sm" busy={busy === 'verify'} disabled={busy !== ''} onclick={() => act('verify', () => geo('verify'))}>{t('geo_verify_run')}</Button>
	{/snippet}
	{#if !status.verify}
		<p class="rf-subtle rf-small">{t('geo_verify_never')}</p>
	{:else}
		<p class="rf-subtle rf-small">{t('geo_verify_at', date(status.verify.at))}</p>
		{#each Object.entries(status.verify.results) as [id, result] (id)}
			<div class="rf-view">
				<h3 class="rf-small">{t('geo_verify_from', names[id] ?? id)}</h3>
				{#if !result}
					<p class="rf-small rf-bad">{t('geo_verify_noreply')}</p>
				{:else}
					<p class="rf-small">{result.zone ? t('geo_verify_zone', result.zone) : t('geo_verify_nozone')}</p>
					<ul class="rf-list-rows rf-small">
						{#each Object.entries(result.nameservers) as [server, answer] (server)}
							<li>
								<span class="rf-mono">{server}</span>
								<span class="rf-row">
									<span class="rf-mono">{answer.ips === null ? t('geo_verify_silent') : answer.ips.length ? answer.ips.join(', ') : t('geo_verify_empty')}</span>
									<Badge tone={answer.known ? 'success' : 'warning'}>{answer.known ? t('geo_verify_known') : t('geo_verify_unknown')}</Badge>
								</span>
							</li>
						{/each}
						{#each Object.entries(result.subs) as [name, sub] (name)}
							<li>
								<span class="rf-mono">{name}</span>
								<span class="rf-row">
									<span class="rf-mono">{sub.got || '—'}</span>
									<Badge tone={sub.got === sub.want ? 'success' : 'warning'}>{sub.got === sub.want ? t('geo_verify_match') : t('geo_verify_want', sub.want)}</Badge>
								</span>
							</li>
						{/each}
					</ul>
					{#if result.local.length}<p class="rf-subtle rf-small">{t('geo_verify_local', result.local.join(', '))}</p>{/if}
				{/if}
			</div>
		{/each}
	{/if}
</Card>

<style>
	.rf-grid {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
		gap: 16px;
	}
	.rf-narrow {
		max-inline-size: 280px;
	}
	.rf-login {
		display: flex;
		flex-direction: column;
		gap: 10px;
		margin: 0;
		padding: 12px;
		border: 1px solid var(--rf-l2);
		border-radius: var(--rf-r-control);
	}
	.rf-login legend {
		padding-inline: 4px;
		font-weight: 700;
	}
	.rf-view {
		display: flex;
		flex-direction: column;
		gap: 6px;
	}
	.rf-bad {
		color: var(--rf-danger-fg);
	}
</style>
