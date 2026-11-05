<script lang="ts">
	import Badge from '../components/Badge.svelte';
	import Button from '../components/Button.svelte';
	import Card from '../components/Card.svelte';
	import CountrySelect from '../components/CountrySelect.svelte';
	import Guide from '../components/Guide.svelte';
	import Notice from '../components/Notice.svelte';
	import { country, geo, hostOf, message, nodeName, routeText, steps } from '../lib/geo';
	import { ago, help, num, t } from '../lib/i18n';
	import type { GeoNode, GeoStatus } from '../lib/types';
	import GeoJob from './GeoJob.svelte';

	interface Props {
		status: GeoStatus;
		onupdate: (next: GeoStatus) => void;
	}

	let { status, onupdate }: Props = $props();

	let busy = $state('');
	let error = $state('');
	let done = $state('');
	let open = $state('');
	let edit = $state({ label: '', country: '', ip: '' });
	let add = $state({ code: '', label: '', country: 'DE', ip: '' });

	const copies = $derived(status.nodes.filter((node) => !node.primary).length);
	const seeding = $derived(status.job && status.job.kind === 'send' ? status.job : null);
	const names = $derived(Object.fromEntries(status.nodes.map((node) => [node.id, nodeName(node)])));

	async function act(id: string, task: () => Promise<GeoStatus>, success = '') {
		busy = id;
		error = '';
		done = '';
		try {
			onupdate(await task());
			done = success;
			return true;
		} catch (e) {
			error = message(e);
			return false;
		} finally {
			busy = '';
		}
	}

	function toggle(name: string, node?: GeoNode) {
		open = open === name ? '' : name;
		if (node) edit = { label: node.label, country: node.country || 'DE', ip: node.ip };
	}

	function badgeFor(node: GeoNode): { tone: 'success' | 'warning' | 'danger'; text: string } {
		if (node.status === 'seeding') return { tone: 'warning', text: t('geo_status_seeding') };
		if (node.status === 'failed') return { tone: 'danger', text: t('geo_status_failed') };
		if (node.health && !node.health.up) return { tone: 'danger', text: t('geo_status_down') };
		if (node.health?.self && !node.health.self.ok) return { tone: 'warning', text: t('geo_status_unsure') };
		return { tone: 'success', text: t('geo_status_up') };
	}

	function reach(node: GeoNode): string {
		if (node.self) return t('geo_reach_self');
		const probe = node.health?.self;
		if (!probe) return t('geo_reach_wait');
		if (probe.ok) return t('geo_reach_ms', probe.ms ?? 0);
		return t('geo_reach_fail', probe.fails, ago(probe.last_ok ?? 0));
	}

	function views(node: GeoNode): string {
		const entries = Object.entries(node.health?.views ?? {}).filter(([from]) => from !== node.id);
		if (entries.length === 0) return '';
		return entries.map(([from, probe]) => (probe.ok ? t('geo_view_ok', names[from] ?? from) : t('geo_view_fail', names[from] ?? from))).join(' · ');
	}

	function sync(node: GeoNode): string {
		const info = node.health?.sync;
		if (node.primary) return t('geo_sync_source');
		if (!info) return '—';
		if (info.error) return t('geo_sync_error', info.error);
		if (info.busy) return t('geo_sync_busy');
		if (info.gen >= status.gen && status.gen > 0) return t('geo_sync_current', ago(info.ok_at));
		return t('geo_sync_behind', ago(info.ok_at));
	}
</script>

<Card title={t('geo_hosts_title')} help={help('card_geo_hosts')} description={t('geo_hosts_desc', status.host)}>
	{#snippet actions()}
		<Button size="sm" busy={busy === 'sync'} disabled={busy !== ''} onclick={() => act('sync', () => geo('sync'), t('geo_sync_started'))}>{t('geo_sync_now')}</Button>
	{/snippet}

	{#if done}<Notice tone="success" live>{done}</Notice>{/if}
	{#if error}<Notice tone="danger" live>{error}</Notice>{/if}
	{#if seeding}<GeoJob job={seeding} title={t('geo_seeding')} />{/if}

	<ul class="rf-hosts">
		{#each status.nodes as node (node.id)}
			{@const badge = badgeFor(node)}
			<li class="rf-host">
				<div class="rf-spread">
					<div class="rf-host-name">
						<strong>{nodeName(node)}</strong>
						<span class="rf-mono rf-subtle rf-small">{hostOf(node.url)}</span>
					</div>
					<div class="rf-row">
						<Badge tone="info">{node.primary ? t('geo_badge_main') : t('geo_badge_copy')}</Badge>
						{#if node.self}<Badge>{t('geo_badge_this')}</Badge>{/if}
						<Badge tone={badge.tone}>{badge.text}</Badge>
					</div>
				</div>

				<dl class="rf-facts">
					<div><dt>{t('geo_country')}</dt><dd>{country(node.country)}</dd></div>
					<div><dt>{t('geo_ip')}</dt><dd class="rf-mono">{node.ip || '—'}</dd></div>
					<div><dt>{t('geo_reach')}</dt><dd>{reach(node)}</dd></div>
					<div><dt>{t('geo_sync')}</dt><dd>{sync(node)}</dd></div>
					{#if node.health?.version}<div><dt>{t('geo_version')}</dt><dd>{node.health.version}</dd></div>{/if}
				</dl>

				{#if views(node)}<p class="rf-subtle rf-small">{views(node)}</p>{/if}
				{#if node.health?.offline}<p class="rf-small">{t('geo_host_offline')}</p>{/if}
				{#if routeText(node)}<p class="rf-small">{t('geo_routes', routeText(node))}</p>{/if}
				{#if !node.ip}<Notice tone="warning">{t('geo_ip_missing')}</Notice>{/if}
				{#if node.status === 'failed'}
					<Notice tone="danger">{t('geo_failed_hint', node.error || '—')}</Notice>
				{/if}

				<div class="rf-row">
					<Button size="sm" variant="ghost" aria-expanded={open === 'edit' + node.id} onclick={() => toggle('edit' + node.id, node)}>{t('geo_edit')}</Button>
					{#if !node.primary}
						<Button size="sm" variant="ghost" aria-expanded={open === 'remove' + node.id} onclick={() => toggle('remove' + node.id)}>{t('geo_remove')}</Button>
					{/if}
				</div>

				{#if open === 'edit' + node.id}
					<div class="rf-box">
						<div class="rf-grid">
							<div class="rf-field">
								<label for={'rafoo-geo-edit-label-' + node.id}>{t('geo_label')}</label>
								<input id={'rafoo-geo-edit-label-' + node.id} class="rf-input" maxlength="40" bind:value={edit.label} />
							</div>
							<div class="rf-field">
								<label for={'rafoo-geo-edit-country-' + node.id}>{t('geo_country')}</label>
								<CountrySelect id={'rafoo-geo-edit-country-' + node.id} codes={status.countries} bind:value={edit.country} />
							</div>
							<div class="rf-field">
								<label for={'rafoo-geo-edit-ip-' + node.id}>{t('geo_ip')}</label>
								<input id={'rafoo-geo-edit-ip-' + node.id} class="rf-input rf-mono" inputmode="decimal" spellcheck="false" placeholder="203.0.113.10" bind:value={edit.ip} />
							</div>
						</div>
						<p class="rf-subtle rf-small">{t('geo_ip_help')}</p>
						<div class="rf-row">
							<Button
								variant="primary"
								busy={busy === 'edit'}
								disabled={busy !== ''}
								onclick={async () => {
									if (await act('edit', () => geo('node', { id: node.id, label: edit.label.trim(), country: edit.country, ip: edit.ip.trim() }), t('settings_saved'))) open = '';
								}}>{t('save')}</Button
							>
							<Button variant="ghost" onclick={() => (open = '')}>{t('setup_back')}</Button>
						</div>
					</div>
				{:else if open === 'remove' + node.id}
					<div class="rf-box">
						<Notice tone="warning">{t('geo_remove_warning', nodeName(node))}</Notice>
						<div class="rf-row">
							<Button
								variant="danger"
								busy={busy === 'remove'}
								disabled={busy !== ''}
								onclick={async () => {
									if (await act('remove', () => geo('remove', { id: node.id }), t('geo_removed'))) open = '';
								}}>{t('geo_remove_confirm')}</Button
							>
							<Button variant="ghost" onclick={() => (open = '')}>{t('setup_back')}</Button>
						</div>
					</div>
				{/if}
			</li>
		{/each}
	</ul>
	{#if status.list}
		<p class="rf-subtle rf-small">{t('geo_list_info', num(status.list.files), ago(status.list.at))}</p>
	{/if}
</Card>

<Card title={t('geo_add_title')} help={help('card_geo_add')} description={t('geo_add_desc', status.max)}>
	{#if copies >= status.max}
		<Notice tone="info">{t('geo_full')}</Notice>
	{:else}
		<Guide label={t('geo_guide_add_title')} steps={steps('geo_guide_add')} note={t('geo_guide_add_note')} />
		<div class="rf-field">
			<label for="rafoo-geo-add-code">{t('geo_add_code')}</label>
			<textarea id="rafoo-geo-add-code" class="rf-input rf-mono" rows="3" spellcheck="false" placeholder="RAFOOG1-…" bind:value={add.code}></textarea>
		</div>
		<div class="rf-grid">
			<div class="rf-field">
				<label for="rafoo-geo-add-label">{t('geo_label')}</label>
				<input id="rafoo-geo-add-label" class="rf-input" maxlength="40" placeholder={t('geo_label_copy_ph')} bind:value={add.label} />
			</div>
			<div class="rf-field">
				<label for="rafoo-geo-add-country">{t('geo_country')}</label>
				<CountrySelect id="rafoo-geo-add-country" codes={status.countries} bind:value={add.country} />
			</div>
			<div class="rf-field">
				<label for="rafoo-geo-add-ip">{t('geo_ip_optional')}</label>
				<input id="rafoo-geo-add-ip" class="rf-input rf-mono" inputmode="decimal" spellcheck="false" placeholder="203.0.113.10" bind:value={add.ip} />
			</div>
		</div>
		<p class="rf-subtle rf-small">{t('geo_ip_help')}</p>
		<div class="rf-row">
			<Button
				variant="primary"
				busy={busy === 'add'}
				disabled={busy !== '' || add.code.trim() === '' || (status.job?.status === 'running')}
				onclick={async () => {
					if (await act('add', () => geo('add', { code: add.code.trim(), label: add.label.trim(), country: add.country, ip: add.ip.trim() }), t('geo_added'))) add = { code: '', label: '', country: 'DE', ip: '' };
				}}>{t('geo_add_start')}</Button
			>
		</div>
	{/if}
</Card>

<style>
	.rf-hosts {
		display: flex;
		flex-direction: column;
		gap: 12px;
		margin: 0;
		padding: 0;
		list-style: none;
	}
	.rf-host {
		display: flex;
		flex-direction: column;
		gap: 10px;
		margin: 0;
		padding: 14px;
		border: 1px solid var(--rf-l2);
		border-radius: var(--rf-r-control);
	}
	.rf-host-name {
		display: flex;
		flex-direction: column;
	}
	.rf-facts {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
		gap: 8px 16px;
		margin: 0;
	}
	.rf-facts div {
		display: flex;
		flex-direction: column;
	}
	.rf-facts dt {
		color: var(--rf-l4);
		font-size: var(--rf-fs-xs);
	}
	.rf-facts dd {
		margin: 0;
		font-size: var(--rf-fs-sm);
	}
	.rf-box {
		display: flex;
		flex-direction: column;
		gap: 12px;
		padding: 12px;
		border: 1px solid var(--rf-l2);
		border-radius: var(--rf-r-control);
		background: var(--rf-l1);
	}
	.rf-grid {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
		gap: 16px;
	}
</style>
