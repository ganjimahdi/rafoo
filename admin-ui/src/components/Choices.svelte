<script lang="ts" generics="T extends string">
	interface Option {
		value: T;
		label: string;
		description?: string;
	}

	interface Props {
		name: string;
		legend: string;
		description?: string;
		options: Option[];
		value: T;
	}

	let { name, legend, description, options, value = $bindable() }: Props = $props();
</script>

<fieldset class="rf-choices">
	<legend>{legend}</legend>
	{#if description}<p class="rf-desc">{description}</p>{/if}
	<div class="rf-list">
		{#each options as option (option.value)}
			<label class={['rf-choice', value === option.value && 'rf-on']}>
				<input type="radio" {name} value={option.value} bind:group={value} />
				<span class="rf-choice-text">
					<span class="rf-choice-label">{option.label}</span>
					{#if option.description}<span class="rf-desc">{option.description}</span>{/if}
				</span>
			</label>
		{/each}
	</div>
</fieldset>

<style>
	.rf-choices {
		display: flex;
		flex-direction: column;
		gap: 8px;
		margin: 0;
		padding: 0;
		border: 0;
	}
	legend {
		margin-block-end: 4px;
		padding: 0;
		font-weight: 700;
	}
	.rf-list {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
		gap: 8px;
	}
	.rf-choice {
		display: flex;
		align-items: flex-start;
		gap: 10px;
		padding: 12px;
		border: 1px solid var(--rf-l2);
		border-radius: var(--rf-r-control);
		background: var(--rf-l0);
		cursor: pointer;
		transition: border-color var(--rf-dur-1) var(--rf-ease-out);
	}
	.rf-choice:hover {
		border-color: var(--rf-l3);
	}
	.rf-choice.rf-on {
		border-color: var(--rf-l5);
	}
	.rf-choice input {
		flex: none;
		margin: 0;
		margin-block-start: 4px;
		accent-color: var(--rf-l5);
	}
	.rf-choice input:focus-visible {
		outline: 2px solid var(--rf-focus);
		outline-offset: 2px;
	}
	.rf-choice-text {
		display: flex;
		flex-direction: column;
		gap: 2px;
	}
	.rf-choice-label {
		color: var(--rf-l6);
	}
	.rf-desc {
		color: var(--rf-l4);
		font-size: var(--rf-fs-sm);
	}
</style>
