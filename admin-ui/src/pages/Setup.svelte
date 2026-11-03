<script lang="ts">
	import Button from '../components/Button.svelte';
	import Card from '../components/Card.svelte';
	import Choices from '../components/Choices.svelte';
	import Notice from '../components/Notice.svelte';
	import Toggle from '../components/Toggle.svelte';
	import { boot } from '../lib/boot';
	import { t } from '../lib/i18n';
	import { store } from '../lib/store.svelte';
	import type { Preset, Settings } from '../lib/types';

	interface Props {
		onfinish: () => void;
	}

	let { onfinish }: Props = $props();

	const complete = boot.edition === 'complete';
	const draft = $state<Settings>({ ...store.settings });
	const steps = ['files', 'outage', 'safety', 'backup', 'privacy'] as const;

	let step = $state(-1);
	let busy = $state('');
	let error = $state('');
	let finished = $state(false);

	const presets: { id: Preset; points: string[] }[] = [
		{ id: 'recommended', points: ['preset_recommended_1', 'preset_recommended_2', 'preset_recommended_3'] },
		{ id: 'outage', points: ['preset_outage_1', 'preset_outage_2', 'preset_outage_3'] },
		{ id: 'maximum', points: ['preset_maximum_1', 'preset_maximum_2', 'preset_maximum_3'] }
	];

	async function submit(preset: Preset | 'custom' | 'skip') {
		busy = preset;
		error = '';
		try {
			const { setup: _setup, preset: _preset, consent_at: _consent, ...settings } = draft;
			await store.setup(preset, preset === 'custom' ? settings : { telemetry: draft.telemetry });
			if (preset === 'skip') {
				onfinish();
			} else {
				finished = true;
			}
		} catch (e) {
			error = e instanceof Error ? e.message : t('error_generic');
		}
		busy = '';
	}

	function go(next: number) {
		step = next;
		document.querySelector('.rafoo')?.scrollIntoView({ block: 'start' });
	}
</script>

<div class="rf-setup">
	{#if finished}
		<Card>
			<div class="rf-done">
				<span class="rf-check" aria-hidden="true"></span>
				<h2>{t('setup_done_title')}</h2>
				<p class="rf-subtle">{t('setup_done_text')}</p>
				<div class="rf-row">
					<Button variant="primary" onclick={onfinish}>{t('setup_open_dashboard')}</Button>
				</div>
			</div>
		</Card>
	{:else if step < 0}
		<div class="rf-intro">
			<h2>{t('setup_title')}</h2>
			<p class="rf-subtle">{t('setup_intro')}</p>
		</div>
		<div class="rf-presets">
			{#each presets as preset (preset.id)}
				<section class={['rf-preset', preset.id === 'recommended' && 'rf-preset-main']}>
					<div class="rf-preset-head">
						<h3>{t('preset_' + preset.id)}</h3>
						{#if preset.id === 'recommended'}<span class="rf-tag">{t('preset_suggested')}</span>{/if}
					</div>
					<p class="rf-subtle rf-small">{t('preset_' + preset.id + '_help')}</p>
					<ul class="rf-points">
						{#each preset.points as point (point)}
							<li>{t(point)}</li>
						{/each}
					</ul>
					<Button variant={preset.id === 'recommended' ? 'primary' : 'secondary'} busy={busy === preset.id} disabled={busy !== ''} onclick={() => submit(preset.id)}>
						{t('preset_use')}
					</Button>
				</section>
			{/each}
		</div>
		<label class="rf-consent">
			<input type="checkbox" bind:checked={draft.telemetry} />
			<span>
				<strong>{t('telemetry_label')}</strong>
				<span class="rf-subtle rf-small">{t('setup_telemetry_short')}</span>
			</span>
		</label>
		{#if error}<Notice tone="danger" live>{error}</Notice>{/if}
		<div class="rf-spread">
			<Button variant="ghost" disabled={busy !== ''} busy={busy === 'skip'} onclick={() => submit('skip')}>{t('setup_later')}</Button>
			<Button disabled={busy !== ''} onclick={() => go(0)}>{t('setup_custom')}</Button>
		</div>
	{:else}
		<nav class="rf-steps" aria-label={t('setup_title')}>
			<ol>
				{#each steps as name, index (name)}
					<li class={[index === step && 'rf-current', index < step && 'rf-past']} aria-current={index === step ? 'step' : undefined}>
						<span class="rf-step-number">{index + 1}</span>
						<span class="rf-step-label">{t('setup_step_' + name)}</span>
					</li>
				{/each}
			</ol>
		</nav>

		<Card title={t('setup_step_' + steps[step])} description={t('setup_step_' + steps[step] + '_help')}>
			{#if steps[step] === 'files'}
				<Choices
					name="setup-replace"
					legend={t('replace_label')}
					bind:value={draft.replace}
					options={[
						{ value: 'always', label: t('replace_always'), description: t('replace_always_help') },
						{ value: 'outage', label: t('replace_outage'), description: t('replace_outage_help') },
						{ value: 'off', label: t('replace_off'), description: t('replace_off_help') }
					]}
				/>
				<Toggle label={t('html_scan_label')} description={t('html_scan_help')} bind:checked={draft.html_scan} />
				<Toggle label={t('fonts_label')} description={t('fonts_help')} bind:checked={draft.fonts} />
			{:else if steps[step] === 'outage'}
				<Choices
					name="setup-avatars"
					legend={t('avatars_label')}
					bind:value={draft.avatars}
					options={[
						{ value: 'outage', label: t('choice_outage'), description: t('avatars_outage_help') },
						{ value: 'always', label: t('choice_always'), description: t('avatars_always_help') },
						{ value: 'off', label: t('choice_off') }
					]}
				/>
				<Choices
					name="setup-captcha"
					legend={t('captcha_label')}
					description={t('captcha_help')}
					bind:value={draft.captcha}
					options={[
						{ value: 'outage', label: t('choice_outage') },
						{ value: 'always', label: t('choice_always') },
						{ value: 'off', label: t('choice_off') }
					]}
				/>
				<Choices
					name="setup-analytics"
					legend={t('analytics_label')}
					description={t('analytics_help')}
					bind:value={draft.analytics}
					options={[
						{ value: 'outage', label: t('choice_outage') },
						{ value: 'always', label: t('choice_always') },
						{ value: 'off', label: t('choice_off') }
					]}
				/>
				<Toggle label={t('embeds_label')} description={t('embeds_help')} bind:checked={draft.embeds} />
				<Toggle label={t('core_assets_label')} description={t('core_assets_help')} bind:checked={draft.core_assets} />
				<Toggle label={t('social_login_label')} description={t('social_login_help')} bind:checked={draft.social_login} />
				<Toggle label={t('mail_queue_label')} description={t('mail_queue_help')} bind:checked={draft.mail_queue} />
			{:else if steps[step] === 'safety'}
				{#if complete}
					<Choices
						name="setup-updates"
						legend={t('updates_label')}
						bind:value={draft.updates}
						options={[
							{ value: 'outage', label: t('choice_outage'), description: t('updates_outage_help') },
							{ value: 'always', label: t('choice_always'), description: t('updates_always_help') },
							{ value: 'off', label: t('choice_off') }
						]}
					/>
					<Toggle label={t('translations_label')} description={t('translations_help')} bind:checked={draft.translations} />
				{/if}
				<Toggle label={t('safe_updates_label')} description={t('safe_updates_help')} bind:checked={draft.safe_updates} />
				<Toggle label={t('request_log_label')} description={t('request_log_help')} bind:checked={draft.request_log} />
			{:else if steps[step] === 'backup'}
				<Toggle label={t('backup_label')} description={t('backup_help')} bind:checked={draft.backup} />
				<p class="rf-subtle rf-small">{t('setup_backup_later')}</p>
			{:else}
				<Toggle label={t('telemetry_label')} description={t('telemetry_help')} bind:checked={draft.telemetry} />
			{/if}
		</Card>

		{#if error}<Notice tone="danger" live>{error}</Notice>{/if}
		<div class="rf-spread">
			<Button variant="ghost" disabled={busy !== ''} onclick={() => go(step - 1)}>{t('setup_back')}</Button>
			{#if step < steps.length - 1}
				<Button variant="primary" onclick={() => go(step + 1)}>{t('setup_next')}</Button>
			{:else}
				<Button variant="primary" busy={busy === 'custom'} disabled={busy !== ''} onclick={() => submit('custom')}>{t('setup_finish')}</Button>
			{/if}
		</div>
	{/if}
</div>

<style>
	.rf-setup {
		display: flex;
		flex-direction: column;
		gap: 16px;
		max-inline-size: 960px;
		margin-inline: auto;
	}
	.rf-intro {
		display: flex;
		flex-direction: column;
		gap: 6px;
		padding-block: 8px;
	}
	.rf-presets {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
		gap: 12px;
	}
	.rf-preset {
		display: flex;
		flex-direction: column;
		gap: 10px;
		padding: 20px;
		border: 1px solid var(--rf-l2);
		border-radius: var(--rf-r-card);
		background: var(--rf-l0);
	}
	.rf-preset-main {
		border-color: var(--rf-l5);
	}
	.rf-preset-head {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		justify-content: space-between;
		gap: 8px;
	}
	.rf-tag {
		padding-block: 2px;
		padding-inline: 8px;
		border-radius: var(--rf-r-pill);
		background: var(--rf-l5);
		color: var(--rf-l0);
		font-size: var(--rf-fs-xs);
	}
	.rf-points {
		display: flex;
		flex: 1;
		flex-direction: column;
		gap: 6px;
		margin: 0;
		padding-inline-start: 18px;
		font-size: var(--rf-fs-sm);
	}
	.rf-consent {
		display: flex;
		align-items: flex-start;
		gap: 10px;
		padding: 14px 16px;
		border: 1px solid var(--rf-l2);
		border-radius: var(--rf-r-control);
		background: var(--rf-l0);
		cursor: pointer;
	}
	.rf-consent input {
		margin: 0;
		margin-block-start: 4px;
		accent-color: var(--rf-l5);
	}
	.rf-consent span {
		display: flex;
		flex-direction: column;
		gap: 2px;
	}
	.rf-steps ol {
		display: flex;
		flex-wrap: wrap;
		gap: 8px;
		margin: 0;
		padding: 0;
		list-style: none;
	}
	.rf-steps li {
		display: flex;
		align-items: center;
		gap: 8px;
		margin: 0;
		padding-block: 6px;
		padding-inline: 10px 12px;
		border: 1px solid var(--rf-l2);
		border-radius: var(--rf-r-pill);
		background: var(--rf-l0);
		color: var(--rf-l4);
		font-size: var(--rf-fs-sm);
	}
	.rf-step-number {
		display: grid;
		place-items: center;
		inline-size: 22px;
		block-size: 22px;
		border-radius: var(--rf-r-pill);
		background: var(--rf-l1);
		font-size: var(--rf-fs-xs);
	}
	.rf-steps li.rf-current {
		border-color: var(--rf-l5);
		color: var(--rf-l6);
	}
	.rf-steps li.rf-current .rf-step-number,
	.rf-steps li.rf-past .rf-step-number {
		background: var(--rf-l5);
		color: var(--rf-l0);
	}
	.rf-done {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 10px;
		padding-block: 24px;
		text-align: center;
	}
	.rf-check {
		inline-size: 44px;
		block-size: 44px;
		border-radius: var(--rf-r-pill);
		background: var(--rf-success-bg);
		border: 1px solid var(--rf-success-dot);
		position: relative;
	}
	.rf-check::after {
		content: '';
		position: absolute;
		inset-block-start: 11px;
		inset-inline-start: 16px;
		inline-size: 9px;
		block-size: 16px;
		border: solid var(--rf-success-fg);
		border-width: 0 2px 2px 0;
		rotate: 45deg;
	}
</style>
