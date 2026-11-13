<script lang="ts">
	import Button from '../components/Button.svelte';
	import Dialog from '../components/Dialog.svelte';
	import Mixed from '../components/Mixed.svelte';
	import Notice from '../components/Notice.svelte';
	import { t } from '../lib/i18n';
	import type { FilesDestination } from '../lib/types';

	interface Props {
		files: FilesDestination;
		busy: boolean;
		message: string;
		error: string;
		ontest: () => void;
	}

	let { files, busy, message, error, ontest }: Props = $props();

	const STEPS = { size: 4, space: 4, key: 3, php: 5, ssl: 3, network: 3, other: 2 } as const;
	const NOTES = ['size', 'space', 'php'];
	const PLANS = 'https://my.files.ir/billing/change-plan';
	const KEYS = 'https://my.files.ir/account-settings/#developers';

	let open = $state(false);
	const issue = $derived(files.issue && files.issue in STEPS ? (files.issue as keyof typeof STEPS) : 'other');
	const rtl = $derived(/[\u0600-\u06FF]/.test(files.last_error));
	const steps = $derived(Array.from({ length: STEPS[issue] }, (_, i) => t(`fix_${issue}_${i + 1}`)));
</script>

<Button size="sm" variant="ghost" onclick={() => (open = true)}>{t('filesir_fix')}</Button>

<Dialog title={t(`fix_${issue}_title`)} bind:open>
	<p><Mixed text={t(`fix_${issue}_why`)} /></p>
	<ol class="rf-fix-steps">
		{#each steps as step, index (index)}
			<li><Mixed text={step} /></li>
		{/each}
	</ol>
	{#if issue === 'size' || issue === 'space'}
		<p class="rf-small"><a href={PLANS} target="_blank" rel="noopener noreferrer">{t('filesir_plans')}</a></p>
	{:else if issue === 'key'}
		<p class="rf-small">{t('filesir_key_where')} <a href={KEYS} target="_blank" rel="noopener noreferrer">{t('filesir_key_page')}</a></p>
	{/if}
	{#if NOTES.includes(issue)}<p class="rf-subtle rf-small"><Mixed text={t(`fix_${issue}_note`)} /></p>{/if}
	<div class="rf-fix-message">
		<span class="rf-subtle rf-small">{t('filesir_fix_message')}</span>
		{#if rtl}
			<p class="rf-small" dir="rtl"><Mixed text={files.last_error} /></p>
		{:else}
			<code dir="ltr">{files.last_error}</code>
		{/if}
	</div>
	{#if message}<Notice tone="success" live>{message}</Notice>{/if}
	{#if error}<Notice tone="danger" live>{error}</Notice>{/if}
	{#snippet actions()}
		<Button size="sm" variant="ghost" onclick={() => (open = false)}>{t('dialog_close')}</Button>
		<Button size="sm" variant="primary" {busy} disabled={busy} onclick={ontest}>{t('destination_test')}</Button>
	{/snippet}
</Dialog>

<style>
	.rf-fix-steps {
		display: flex;
		flex-direction: column;
		gap: 8px;
		margin: 0;
		padding-inline-start: 20px;
	}
	.rf-fix-steps li {
		margin: 0;
	}
	.rf-fix-message {
		display: flex;
		flex-direction: column;
		gap: 4px;
		padding: 10px 12px;
		border: 1px solid var(--rf-l2);
		border-radius: var(--rf-r-control);
		background: var(--rf-l1);
	}
	.rf-fix-message p {
		overflow-wrap: anywhere;
	}
	.rf-fix-message code {
		padding: 0;
		background: transparent;
		font-size: var(--rf-fs-sm);
		overflow-wrap: anywhere;
		white-space: pre-wrap;
	}
</style>
