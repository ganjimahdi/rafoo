<script lang="ts">
	import CircleQuestionMark from '@lucide/svelte/icons/circle-question-mark';
	import { t } from '../lib/i18n';

	interface Props {
		text: string;
		label: string;
	}

	let { text, label }: Props = $props();
	const uid = $props.id();
	const id = 'rf-help-' + uid;
	let open = $state(false);
	let root: HTMLSpanElement | undefined = $state();
	let note: HTMLSpanElement | undefined = $state();
	let shift = $state(0);

	$effect(() => {
		if (!open || !note) {
			shift = 0;
			return;
		}
		const gap = 8;
		const width = document.documentElement.clientWidth;
		const rect = note.getBoundingClientRect();
		const rtl = getComputedStyle(note).direction === 'rtl';
		const over = rtl ? gap - rect.left : rect.right - (width - gap);
		const room = rtl ? width - gap - rect.right : rect.left - gap;
		shift = over > 0 ? -Math.max(0, Math.min(over, room)) : 0;
	});

	function outside(event: PointerEvent) {
		if (open && root && !root.contains(event.target as Node)) open = false;
	}

	function key(event: KeyboardEvent) {
		if (open && event.key === 'Escape') {
			open = false;
			root?.querySelector('button')?.focus();
		}
	}
</script>

<svelte:document onpointerdown={outside} onkeydown={key} />

<span class="rf-help" bind:this={root}>
	<button
		type="button"
		class="rf-help-button"
		aria-label={t('help_for', label)}
		aria-expanded={open}
		aria-controls={id}
		onclick={() => (open = !open)}
	>
		<CircleQuestionMark size={16} strokeWidth={1.75} aria-hidden="true" />
	</button>
	{#if open}
		<span {id} class="rf-help-text" role="note" bind:this={note} style:margin-inline-start="{shift}px">{text}</span>
	{/if}
</span>

<style>
	.rf-help {
		position: relative;
		display: inline-flex;
		vertical-align: middle;
	}
	.rf-help-button {
		display: inline-grid;
		place-items: center;
		inline-size: 24px;
		block-size: 24px;
		margin: 0;
		padding: 0;
		border: 0;
		border-radius: var(--rf-r-pill);
		background: transparent;
		color: var(--rf-l3);
		cursor: pointer;
		transition: color var(--rf-dur-1) var(--rf-ease-out);
	}
	.rf-help-button:hover,
	.rf-help-button[aria-expanded='true'] {
		color: var(--rf-l6);
	}
	.rf-help-button:focus-visible {
		outline: 2px solid var(--rf-focus);
		outline-offset: 1px;
	}
	.rf-help-text {
		position: absolute;
		inset-block-start: calc(100% + 6px);
		inset-inline-start: -8px;
		z-index: 20;
		inline-size: max-content;
		max-inline-size: min(320px, 80vw);
		padding: 10px 12px;
		border: 1px solid var(--rf-l2);
		border-radius: var(--rf-r-control);
		background: var(--rf-l0);
		color: var(--rf-l5);
		font-size: var(--rf-fs-sm);
		font-weight: 400;
		line-height: 1.7;
		white-space: normal;
		animation: rf-help-in var(--rf-dur-1) var(--rf-ease-out);
	}
	@keyframes rf-help-in {
		from {
			opacity: 0;
			translate: 0 -2px;
		}
	}
	@media (prefers-reduced-motion: reduce) {
		.rf-help-text {
			animation: none;
		}
	}
</style>
