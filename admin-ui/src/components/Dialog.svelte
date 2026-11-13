<script lang="ts">
	import X from '@lucide/svelte/icons/x';
	import type { Snippet } from 'svelte';
	import { t } from '../lib/i18n';

	interface Props {
		title: string;
		open: boolean;
		children: Snippet;
		actions?: Snippet;
	}

	let { title, open = $bindable(false), children, actions }: Props = $props();
	const uid = $props.id();
	const id = 'rf-dialog-' + uid;
	let dialog: HTMLDialogElement | undefined = $state();

	$effect(() => {
		if (!dialog) return;
		if (open && !dialog.open) dialog.showModal();
		if (!open && dialog.open) dialog.close();
	});

	function backdrop(event: MouseEvent) {
		if (event.target === dialog) open = false;
	}
</script>

<dialog class="rf-dialog" aria-labelledby={id} bind:this={dialog} onclose={() => (open = false)} onclick={backdrop}>
	<div class="rf-dialog-box">
		<header class="rf-dialog-head">
			<h2 {id}>{title}</h2>
			<button type="button" class="rf-dialog-close" aria-label={t('dialog_close')} onclick={() => (open = false)}>
				<X size={18} strokeWidth={1.75} aria-hidden="true" />
			</button>
		</header>
		<div class="rf-dialog-body">
			{@render children()}
		</div>
		{#if actions}<footer class="rf-dialog-foot">{@render actions()}</footer>{/if}
	</div>
</dialog>

<style>
	.rf-dialog {
		inline-size: min(560px, calc(100vw - 32px));
		max-block-size: calc(100vh - 64px);
		padding: 0;
		border: 1px solid var(--rf-l2);
		border-radius: var(--rf-r-card);
		background: var(--rf-l0);
		color: var(--rf-l6);
	}
	.rf-dialog[open] {
		animation: rf-dialog-in var(--rf-dur-2) var(--rf-ease-out);
	}
	.rf-dialog::backdrop {
		background: var(--rf-overlay);
	}
	.rf-dialog-box {
		display: flex;
		flex-direction: column;
		gap: 16px;
		padding: 20px;
	}
	.rf-dialog-head {
		display: flex;
		align-items: flex-start;
		justify-content: space-between;
		gap: 12px;
	}
	.rf-dialog-close {
		display: inline-grid;
		flex: none;
		place-items: center;
		inline-size: var(--rf-control-sm);
		block-size: var(--rf-control-sm);
		margin: 0;
		padding: 0;
		border: 0;
		border-radius: var(--rf-r-control);
		background: transparent;
		color: var(--rf-l4);
		cursor: pointer;
	}
	.rf-dialog-close:hover {
		background: var(--rf-l1);
		color: var(--rf-l6);
	}
	.rf-dialog-close:focus-visible {
		outline: 2px solid var(--rf-focus);
		outline-offset: 1px;
	}
	.rf-dialog-body {
		display: flex;
		flex-direction: column;
		gap: 12px;
	}
	.rf-dialog-foot {
		display: flex;
		flex-wrap: wrap;
		justify-content: flex-end;
		gap: 8px;
	}
	@keyframes rf-dialog-in {
		from {
			opacity: 0;
			translate: 0 4px;
		}
	}
	@media (prefers-reduced-motion: reduce) {
		.rf-dialog[open] {
			animation: none;
		}
	}
</style>
