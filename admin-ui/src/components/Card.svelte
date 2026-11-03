<script lang="ts">
	import type { Snippet } from 'svelte';

	interface Props {
		title?: string;
		description?: string;
		actions?: Snippet;
		children: Snippet;
	}

	let { title, description, actions, children }: Props = $props();
</script>

<section class="rf-card">
	{#if title || actions}
		<header class="rf-card-head">
			<div class="rf-card-titles">
				{#if title}<h2>{title}</h2>{/if}
				{#if description}<p class="rf-card-desc">{description}</p>{/if}
			</div>
			{#if actions}<div class="rf-card-actions">{@render actions()}</div>{/if}
		</header>
	{/if}
	{@render children()}
</section>

<style>
	.rf-card {
		display: flex;
		flex-direction: column;
		gap: 16px;
		padding: 20px;
		border: 1px solid var(--rf-l2);
		border-radius: var(--rf-r-card);
		background: var(--rf-l0);
	}
	.rf-card-head {
		display: flex;
		flex-wrap: wrap;
		align-items: flex-start;
		justify-content: space-between;
		gap: 12px;
	}
	.rf-card-titles {
		display: flex;
		flex-direction: column;
		gap: 4px;
		min-inline-size: 0;
	}
	.rf-card-desc {
		color: var(--rf-l4);
		font-size: var(--rf-fs-sm);
	}
	.rf-card-actions {
		display: flex;
		flex-wrap: wrap;
		gap: 8px;
	}
</style>
