<script lang="ts">
	import Badge from '../components/Badge.svelte';
	import Button from '../components/Button.svelte';
	import Card from '../components/Card.svelte';
	import Notice from '../components/Notice.svelte';
	import Mail from '../sections/Mail.svelte';
	import Requests from '../sections/Requests.svelte';
	import { ago, date, t } from '../lib/i18n';
	import { store } from '../lib/store.svelte';
	import type { Target } from '../lib/types';

	let checking = $state(false);
	let error = $state('');

	const overview = $derived(store.overview);
	const external = $derived(overview.targets.filter((target) => target.group === 'external'));
	const domestic = $derived(overview.targets.filter((target) => target.group === 'domestic'));

	const names: Record<string, string> = {
		google: 'Google',
		cloudflare: 'Cloudflare',
		apple: 'Apple',
		jsdelivr: 'jsDelivr',
		rafoo: 'rafoo.ir'
	};

	async function check() {
		checking = true;
		error = '';
		try {
			await store.check();
		} catch (e) {
			error = e instanceof Error ? e.message : t('error_generic');
		}
		checking = false;
	}
</script>

{#snippet targets(list: Target[])}
	{#if list.length === 0}
		<p class="rf-subtle">{t('targets_unknown')}</p>
	{:else}
		<ul class="rf-list-rows">
			{#each list as target (target.id)}
				<li>
					<span class="rf-mono">{names[target.id] ?? target.id}</span>
					{#if target.ok}
						<Badge tone="success">{t('reachable')}</Badge>
					{:else}
						<Badge tone="danger">{t('unreachable')}</Badge>
					{/if}
				</li>
			{/each}
		</ul>
	{/if}
{/snippet}

<div class="rf-stack">
	<Card title={t('connection_title')} description={t('connection_help')}>
		{#snippet actions()}
			<Button onclick={check} busy={checking}>{t('check_now')}</Button>
		{/snippet}
		{#if error}<Notice tone="danger" live>{error}</Notice>{/if}
		<p class="rf-subtle rf-small">{t('last_check', ago(overview.checked_at, overview.now))}</p>
		<div class="rf-groups">
			<div class="rf-stack">
				<h3>{t('targets_external')}</h3>
				{@render targets(external)}
			</div>
			<div class="rf-stack">
				<h3>{t('targets_domestic')}</h3>
				{@render targets(domestic)}
			</div>
		</div>
	</Card>

	<Card title={t('signal_title')} description={t('signal_help')}>
		{#if !overview.signature}
			<p class="rf-subtle">{t('signal_unavailable')}</p>
		{:else if overview.hint}
			<p>{t(overview.hint.state === 'down' ? 'signal_down' : 'signal_up', date(overview.hint.issued_at))}</p>
		{:else}
			<p class="rf-subtle">{t('signal_none')}</p>
		{/if}
	</Card>

	<Card title={t('breaker_title')} description={t('breaker_help')}>
		{#if overview.breaker.length === 0}
			<p class="rf-subtle">{t('breaker_empty')}</p>
		{:else}
			<ul class="rf-list-rows">
				{#each overview.breaker as entry (entry.host)}
					<li>
						<span class="rf-mono">{entry.host}</span>
						<span class="rf-subtle rf-small">{t('breaker_until', ago(entry.until, overview.now))}</span>
					</li>
				{/each}
			</ul>
		{/if}
	</Card>

	<Requests />
	<Mail />
</div>

<style>
	.rf-groups {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
		gap: 24px;
	}
</style>
