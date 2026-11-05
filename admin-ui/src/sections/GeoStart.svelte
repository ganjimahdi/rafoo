<script lang="ts">
	import { untrack } from 'svelte';
	import Button from '../components/Button.svelte';
	import Card from '../components/Card.svelte';
	import Choices from '../components/Choices.svelte';
	import CountrySelect from '../components/CountrySelect.svelte';
	import Guide from '../components/Guide.svelte';
	import Notice from '../components/Notice.svelte';
	import { copyText, geo, message, steps } from '../lib/geo';
	import { date, help, t } from '../lib/i18n';
	import type { GeoStatus } from '../lib/types';

	interface Props {
		status: GeoStatus;
		onupdate: (next: GeoStatus) => void;
	}

	let { status, onupdate }: Props = $props();

	const initial = untrack(() => status.suggest);
	let role = $state<'primary' | 'copy'>('primary');
	let primaryUrl = $state(initial.primary);
	let copyUrl = $state(initial.copy);
	let label = $state('');
	let place = $state('IR');
	let code = $state('');
	let busy = $state('');
	let error = $state('');
	let copied = $state(false);

	const joining = $derived(status.role === 'joining');

	async function act(id: string, task: () => Promise<GeoStatus>) {
		busy = id;
		error = '';
		try {
			const next = await task();
			if (next.code) code = next.code;
			onupdate(next);
		} catch (e) {
			error = message(e);
		}
		busy = '';
	}

	async function copy() {
		copied = await copyText(code);
		if (copied) setTimeout(() => (copied = false), 2000);
	}
</script>

<Card title={t('geo_start_title')} help={help('card_geo_start')} description={t('geo_start_desc')}>
	{#if error}<Notice tone="danger" live>{error}</Notice>{/if}

	{#if joining}
		<div class="rf-code">
			<h3>{t('geo_join_title')}</h3>
			{#if code}
				<label for="rafoo-geo-code" class="rf-small">{t('geo_join_code')}</label>
				<textarea id="rafoo-geo-code" class="rf-input rf-mono" rows="3" readonly value={code}></textarea>
				<div class="rf-row">
					<Button size="sm" onclick={copy}>{copied ? t('move_copied') : t('move_copy')}</Button>
					{#if status.join}<span class="rf-subtle rf-small">{t('move_expires', date(status.join.expires))}</span>{/if}
				</div>
			{:else}
				<p class="rf-small">{t('geo_join_lost')}</p>
			{/if}
			<p class="rf-small">{t('geo_join_next')}</p>
			{#if status.join}<p class="rf-subtle rf-small">{t('geo_join_address', status.join.url)}</p>{/if}
		</div>
		<div class="rf-row">
			<Button busy={busy === 'code'} disabled={busy !== ''} onclick={() => act('code', () => geo('join', { url: status.join?.url ?? copyUrl }))}>{t('move_new_code')}</Button>
			<Button variant="ghost" busy={busy === 'cancel'} disabled={busy !== ''} onclick={() => act('cancel', async () => { const next = await geo('join/cancel'); code = ''; return next; })}>{t('geo_join_cancel')}</Button>
		</div>
	{:else}
		<Choices
			name="rafoo-geo-role"
			legend={t('geo_role_legend')}
			bind:value={role}
			options={[
				{ value: 'primary', label: t('geo_role_primary'), description: t('geo_role_primary_desc') },
				{ value: 'copy', label: t('geo_role_copy'), description: t('geo_role_copy_desc') }
			]}
		/>

		{#if role === 'primary'}
			<Guide label={t('geo_guide_tech_title')} steps={steps('geo_guide_tech')} note={t('geo_guide_tech_note')} />
			<div class="rf-grid">
				<div class="rf-field">
					<label for="rafoo-geo-url">{t('geo_tech_address')}</label>
					<p id="rafoo-geo-url-help" class="rf-subtle rf-small">{t('geo_tech_primary_help')}</p>
					<input id="rafoo-geo-url" class="rf-input rf-mono" type="url" spellcheck="false" aria-describedby="rafoo-geo-url-help" bind:value={primaryUrl} />
				</div>
				<div class="rf-field">
					<label for="rafoo-geo-label">{t('geo_label')}</label>
					<p id="rafoo-geo-label-help" class="rf-subtle rf-small">{t('geo_label_help')}</p>
					<input id="rafoo-geo-label" class="rf-input" maxlength="40" aria-describedby="rafoo-geo-label-help" placeholder={t('geo_label_primary_ph')} bind:value={label} />
				</div>
				<div class="rf-field">
					<label for="rafoo-geo-country">{t('geo_country')}</label>
					<p id="rafoo-geo-country-help" class="rf-subtle rf-small">{t('geo_country_help')}</p>
					<CountrySelect id="rafoo-geo-country" describedby="rafoo-geo-country-help" codes={status.countries} bind:value={place} />
				</div>
			</div>
			<div class="rf-row">
				<Button variant="primary" busy={busy === 'primary'} disabled={busy !== '' || primaryUrl.trim() === ''} onclick={() => act('primary', () => geo('primary', { url: primaryUrl.trim(), label: label.trim(), country: place }))}>{t('geo_setup_primary')}</Button>
			</div>
		{:else}
			<Notice tone="warning">{t('geo_copy_warning')}</Notice>
			<Guide label={t('geo_guide_copy_title')} steps={steps('geo_guide_copy')} />
			<div class="rf-field">
				<label for="rafoo-geo-copy-url">{t('geo_tech_address')}</label>
				<p id="rafoo-geo-copy-url-help" class="rf-subtle rf-small">{t('geo_tech_copy_help')}</p>
				<input id="rafoo-geo-copy-url" class="rf-input rf-mono rf-narrow" type="url" spellcheck="false" aria-describedby="rafoo-geo-copy-url-help" bind:value={copyUrl} />
			</div>
			{#if !status.config}
				<Notice tone="info">{t('geo_prefix_note', status.prefix)}</Notice>
			{/if}
			<div class="rf-row">
				<Button variant="primary" busy={busy === 'code'} disabled={busy !== '' || copyUrl.trim() === ''} onclick={() => act('code', () => geo('join', { url: copyUrl.trim() }))}>{t('geo_make_code')}</Button>
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
	.rf-grid {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
		gap: 16px;
	}
	.rf-narrow {
		max-inline-size: 420px;
	}
</style>
