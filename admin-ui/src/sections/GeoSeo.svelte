<script lang="ts">
	import Badge from '../components/Badge.svelte';
	import Button from '../components/Button.svelte';
	import Card from '../components/Card.svelte';
	import Guide from '../components/Guide.svelte';
	import Notice from '../components/Notice.svelte';
	import { geo, message, nodeName, steps } from '../lib/geo';
	import { ago, date, help, num, t } from '../lib/i18n';
	import type { GeoCrawl, GeoStatus } from '../lib/types';

	interface Props {
		status: GeoStatus;
		onupdate: (next: GeoStatus) => void;
	}

	let { status, onupdate }: Props = $props();

	const BOTS = ['google', 'bing', 'yandex', 'apple', 'other'];

	let busy = $state(false);
	let error = $state('');

	const names = $derived(Object.fromEntries(status.nodes.map((node) => [node.id, nodeName(node)])));
	const results = $derived(status.checks.results ?? {});
	const crawl = $derived(
		status.nodes.flatMap((node) =>
			Object.entries((node.health?.crawl ?? {}) as Record<string, GeoCrawl>)
				.sort((a, b) => BOTS.indexOf(a[0]) - BOTS.indexOf(b[0]))
				.map(([bot, row]) => ({ id: node.id + bot, host: nodeName(node), bot, row }))
		)
	);

	async function run() {
		busy = true;
		error = '';
		try {
			onupdate(await geo('checks'));
		} catch (e) {
			error = message(e);
		}
		busy = false;
	}

	function bot(name: string): string {
		const key = 'geo_bot_' + name;
		const label = t(key);
		return label === key ? name : label;
	}
</script>

{#snippet mark(ok: boolean, good: string, bad: string)}
	<Badge tone={ok ? 'success' : 'danger'}>{ok ? good : bad}</Badge>
{/snippet}

<Card title={t('geo_seo_title')} help={help('card_geo_seo')} description={t('geo_seo_desc')}>
	{#snippet actions()}
		<Button size="sm" {busy} onclick={run}>{t('geo_seo_run')}</Button>
	{/snippet}
	{#if error}<Notice tone="danger" live>{error}</Notice>{/if}

	{#if !status.checks.at}
		<p class="rf-subtle rf-small">{t('geo_seo_never')}</p>
	{:else}
		<p class="rf-subtle rf-small">{t('geo_verify_at', date(status.checks.at))}</p>
		<div class="rf-table-wrap">
			<table class="rf-table rf-small">
				<caption class="rf-sr">{t('geo_seo_title')}</caption>
				<thead>
					<tr>
						<th scope="col">{t('geo_col_host')}</th>
						<th scope="col">{t('geo_seo_redirect')}</th>
						<th scope="col">{t('geo_seo_same')}</th>
						<th scope="col">{t('geo_seo_canonical')}</th>
						<th scope="col">{t('geo_seo_index')}</th>
					</tr>
				</thead>
				<tbody>
					{#each Object.entries(results) as [id, row] (id)}
						<tr>
							<td>{names[id] ?? id}</td>
							<td>{@render mark(row.redirect, t('geo_ok'), t('geo_problem'))}</td>
							<td>{#if row.print}{@render mark(row.same, t('geo_ok'), t('geo_seo_differs'))}{:else}<Badge tone="warning">{t('geo_verify_noreply')}</Badge>{/if}</td>
							<td>{@render mark(row.canonical, t('geo_ok'), t('geo_problem'))}</td>
							<td>{@render mark(!row.noindex && row.home === 200, t('geo_ok'), row.noindex ? t('geo_seo_noindex') : t('geo_seo_code', row.home))}</td>
						</tr>
					{/each}
				</tbody>
			</table>
		</div>
	{/if}

	<h3 class="rf-small">{t('geo_crawl_title')}</h3>
	{#if crawl.length === 0}
		<p class="rf-subtle rf-small">{t('geo_crawl_empty')}</p>
	{:else}
		<div class="rf-table-wrap">
			<table class="rf-table rf-small">
				<caption class="rf-sr">{t('geo_crawl_title')}</caption>
				<thead>
					<tr>
						<th scope="col">{t('geo_col_host')}</th>
						<th scope="col">{t('geo_crawl_bot')}</th>
						<th scope="col">{t('geo_crawl_hits')}</th>
						<th scope="col">{t('geo_crawl_codes')}</th>
						<th scope="col">{t('geo_crawl_ms')}</th>
						<th scope="col">{t('geo_crawl_verified')}</th>
						<th scope="col">{t('geo_crawl_last')}</th>
					</tr>
				</thead>
				<tbody>
					{#each crawl as item (item.id)}
						<tr>
							<td>{item.host}</td>
							<td>{bot(item.bot)}</td>
							<td>{num(item.row.hits)}</td>
							<td class="rf-mono">2xx {num(item.row.codes['2'])} · 3xx {num(item.row.codes['3'])} · 4xx {num(item.row.codes['4'])} · 5xx {num(item.row.codes['5'])}</td>
							<td>{t('geo_ms', item.row.ms)}</td>
							<td>{t('geo_crawl_verified_n', item.row.verified, item.row.fake)}</td>
							<td>{ago(item.row.last)}</td>
						</tr>
					{/each}
				</tbody>
			</table>
		</div>
	{/if}

	{#if status.relayed}
		<p class="rf-subtle rf-small">{t('geo_relayed', names[status.relayed.from] ?? status.relayed.from, ago(status.relayed.at))}</p>
	{/if}

	<Guide label={t('geo_guide_gsc_title')} steps={steps('geo_guide_gsc')} />
	<Guide label={t('geo_guide_firewall_title')} steps={steps('geo_guide_firewall')} />
	<Guide label={t('geo_guide_ssl_title')} steps={steps('geo_guide_ssl')} />
</Card>
