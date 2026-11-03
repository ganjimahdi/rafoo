<script lang="ts">
	import type { Snippet } from 'svelte';

	interface Props {
		tone?: 'neutral' | 'success' | 'warning' | 'danger' | 'info';
		children: Snippet;
	}

	let { tone = 'neutral', children }: Props = $props();
</script>

<span class={['rf-badge', `rf-badge-${tone}`]}>
	<span class="rf-dot" aria-hidden="true"></span>
	{@render children()}
</span>

<style>
	.rf-badge {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		padding-block: 2px;
		padding-inline: 8px;
		border: 1px solid var(--rf-l2);
		border-radius: var(--rf-r-pill);
		background: var(--rf-l1);
		color: var(--rf-l4);
		font-size: var(--rf-fs-xs);
		line-height: 1.6;
		white-space: nowrap;
	}
	.rf-dot {
		inline-size: 6px;
		block-size: 6px;
		border-radius: var(--rf-r-pill);
		background: var(--rf-l3);
	}
	.rf-badge-success {
		border-color: transparent;
		background: var(--rf-success-bg);
		color: var(--rf-success-fg);
	}
	.rf-badge-success .rf-dot {
		background: var(--rf-success-dot);
	}
	.rf-badge-warning {
		border-color: transparent;
		background: var(--rf-warning-bg);
		color: var(--rf-warning-fg);
	}
	.rf-badge-warning .rf-dot {
		background: var(--rf-warning-dot);
	}
	.rf-badge-danger {
		border-color: transparent;
		background: var(--rf-danger-bg);
		color: var(--rf-danger-fg);
	}
	.rf-badge-danger .rf-dot {
		background: var(--rf-danger-dot);
	}
	.rf-badge-info {
		border-color: transparent;
		background: var(--rf-info-bg);
		color: var(--rf-info-fg);
	}
	.rf-badge-info .rf-dot {
		background: var(--rf-info-dot);
	}
</style>
