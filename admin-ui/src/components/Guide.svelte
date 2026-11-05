<script lang="ts">
	interface Props {
		label: string;
		steps: string[];
		note?: string;
		open?: boolean;
	}

	let { label, steps, note = '', open = $bindable(false) }: Props = $props();
	const uid = $props.id();
	const id = 'rf-guide-' + uid;
</script>

<div class="rf-guide">
	<button type="button" class="rf-more" aria-expanded={open} aria-controls={id} onclick={() => (open = !open)}>{label}</button>
	{#if open}
		<div {id} class="rf-guide-box">
			<ol>
				{#each steps as step, index (index)}
					<li>{step}</li>
				{/each}
			</ol>
			{#if note}<p class="rf-subtle rf-small">{note}</p>{/if}
		</div>
	{/if}
</div>

<style>
	.rf-guide {
		display: flex;
		flex-direction: column;
		gap: 8px;
	}
	.rf-guide-box {
		display: flex;
		flex-direction: column;
		gap: 8px;
		padding: 12px;
		border: 1px solid var(--rf-l2);
		border-radius: var(--rf-r-control);
		background: var(--rf-l1);
		font-size: var(--rf-fs-sm);
	}
	ol {
		display: flex;
		flex-direction: column;
		gap: 6px;
		margin: 0;
		padding-inline-start: 20px;
	}
	li {
		margin: 0;
	}
</style>
