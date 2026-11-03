<script lang="ts">
	import type { Snippet } from 'svelte';
	import type { HTMLButtonAttributes } from 'svelte/elements';

	interface Props extends HTMLButtonAttributes {
		variant?: 'primary' | 'secondary' | 'ghost' | 'danger';
		size?: 'md' | 'sm';
		busy?: boolean;
		children: Snippet;
	}

	let { variant = 'secondary', size = 'md', busy = false, disabled, type = 'button', children, ...rest }: Props = $props();
</script>

<button
	{...rest}
	{type}
	class={['rf-btn', `rf-btn-${variant}`, size === 'sm' && 'rf-btn-sm']}
	disabled={disabled || busy}
	aria-busy={busy}
>
	<span class={['rf-btn-content', busy && 'rf-hidden']}>{@render children()}</span>
	{#if busy}
		<span class="rf-btn-loader" aria-hidden="true"><span class="rf-spinner"></span></span>
	{/if}
</button>

<style>
	.rf-btn {
		position: relative;
		display: inline-flex;
		align-items: center;
		justify-content: center;
		min-block-size: var(--rf-control);
		margin: 0;
		padding-block: 0;
		padding-inline: 14px;
		border: 1px solid transparent;
		border-radius: var(--rf-r-control);
		font: inherit;
		font-size: var(--rf-fs-base);
		line-height: 1;
		white-space: nowrap;
		cursor: pointer;
		transition:
			background-color var(--rf-dur-1) var(--rf-ease-out),
			transform var(--rf-dur-1) var(--rf-ease-out);
	}
	.rf-btn:active:not(:disabled) {
		transform: scale(0.98);
	}
	.rf-btn-sm {
		min-block-size: var(--rf-control-sm);
		padding-inline: 10px;
		font-size: var(--rf-fs-sm);
	}
	.rf-btn-primary {
		background: var(--rf-l5);
		color: var(--rf-l0);
	}
	.rf-btn-primary:hover:not(:disabled) {
		background: var(--rf-l6);
	}
	.rf-btn-secondary {
		background: var(--rf-l0);
		border-color: var(--rf-l2);
		color: var(--rf-l6);
	}
	.rf-btn-secondary:hover:not(:disabled) {
		background: var(--rf-l1);
	}
	.rf-btn-ghost {
		background: transparent;
		color: var(--rf-l6);
	}
	.rf-btn-ghost:hover:not(:disabled) {
		background: var(--rf-l2);
	}
	.rf-btn-danger {
		background: var(--rf-danger-bg);
		color: var(--rf-danger-fg);
	}
	.rf-btn-danger:hover:not(:disabled) {
		border-color: var(--rf-danger-dot);
	}
	.rf-btn:disabled {
		cursor: not-allowed;
	}
	.rf-btn:disabled:not([aria-busy='true']) {
		background: var(--rf-l1);
		border-color: var(--rf-l2);
		color: var(--rf-l4);
	}
	.rf-btn[aria-busy='true'] {
		cursor: progress;
	}
	.rf-btn-content {
		display: inline-flex;
		align-items: center;
		gap: 8px;
	}
	.rf-hidden {
		visibility: hidden;
	}
	.rf-btn-loader {
		position: absolute;
		inset: 0;
		display: grid;
		place-items: center;
	}
	.rf-spinner {
		inline-size: 14px;
		block-size: 14px;
		border: 2px solid currentColor;
		border-inline-end-color: transparent;
		border-radius: var(--rf-r-pill);
		animation: rf-spin 700ms linear infinite;
	}
	@keyframes rf-spin {
		to {
			transform: rotate(360deg);
		}
	}
</style>
