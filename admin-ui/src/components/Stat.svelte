<script lang="ts">
	interface Props {
		label: string;
		value: string;
		hint?: string;
	}

	let { label, value, hint }: Props = $props();
</script>

<div class="rf-stat">
	<span class="rf-stat-label">{label}</span>
	<span class="rf-stat-value">{value}</span>
	{#if hint}<span class="rf-stat-hint">{hint}</span>{/if}
</div>

<style>
	.rf-stat {
		display: flex;
		flex-direction: column;
		gap: 4px;
		padding: 16px;
		border: 1px solid var(--rf-l2);
		border-radius: var(--rf-r-card);
		background: var(--rf-l0);
	}
	.rf-stat-label {
		color: var(--rf-l4);
		font-size: var(--rf-fs-sm);
	}
	.rf-stat-value {
		font-size: var(--rf-fs-xl);
		font-weight: 700;
		line-height: 1.4;
	}
	.rf-stat-hint {
		color: var(--rf-l4);
		font-size: var(--rf-fs-xs);
	}
</style>
