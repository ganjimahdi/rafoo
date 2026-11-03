<script lang="ts">
	import type { Snippet } from 'svelte';

	interface Props {
		tone?: 'success' | 'warning' | 'danger' | 'info';
		live?: boolean;
		children: Snippet;
	}

	let { tone = 'info', live = false, children }: Props = $props();
</script>

<div class={['rf-notice', `rf-notice-${tone}`]} role={live ? (tone === 'danger' ? 'alert' : 'status') : undefined}>
	{@render children()}
</div>

<style>
	.rf-notice {
		padding-block: 10px;
		padding-inline: 12px;
		border: 1px solid;
		border-radius: var(--rf-r-control);
		font-size: var(--rf-fs-sm);
	}
	.rf-notice-success {
		border-color: var(--rf-success-dot);
		background: var(--rf-success-bg);
		color: var(--rf-success-fg);
	}
	.rf-notice-warning {
		border-color: var(--rf-warning-dot);
		background: var(--rf-warning-bg);
		color: var(--rf-warning-fg);
	}
	.rf-notice-danger {
		border-color: var(--rf-danger-dot);
		background: var(--rf-danger-bg);
		color: var(--rf-danger-fg);
	}
	.rf-notice-info {
		border-color: var(--rf-info-dot);
		background: var(--rf-info-bg);
		color: var(--rf-info-fg);
	}
</style>
