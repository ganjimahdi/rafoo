<script lang="ts" generics="T extends string">
	interface Option {
		value: T;
		label: string;
	}

	interface Props {
		name: string;
		label: string;
		options: Option[];
		value: T;
		disabled?: boolean;
		onchange: (value: T) => void;
	}

	let { name, label, options, value, disabled = false, onchange }: Props = $props();
</script>

<fieldset class="rf-seg" {disabled}>
	<legend class="rf-sr">{label}</legend>
	{#each options as option (option.value)}
		<label class={['rf-seg-item', option.value === value && 'rf-on']}>
			<input
				type="radio"
				{name}
				value={option.value}
				checked={option.value === value}
				onchange={() => onchange(option.value)}
			/>
			<span>{option.label}</span>
		</label>
	{/each}
</fieldset>

<style>
	.rf-seg {
		display: inline-flex;
		gap: 2px;
		margin: 0;
		padding: 3px;
		border: 1px solid var(--rf-l2);
		border-radius: var(--rf-r-control);
		background: var(--rf-l1);
	}
	.rf-seg:disabled {
		opacity: 0.6;
	}
	.rf-seg-item {
		position: relative;
		display: inline-flex;
		align-items: center;
		min-block-size: var(--rf-control-sm);
		padding-inline: 12px;
		border: 1px solid transparent;
		border-radius: 6px;
		color: var(--rf-l4);
		font-size: var(--rf-fs-sm);
		cursor: pointer;
		transition: background-color var(--rf-dur-1) var(--rf-ease-out);
	}
	.rf-seg-item:hover {
		color: var(--rf-l6);
	}
	.rf-seg-item.rf-on {
		border-color: var(--rf-l2);
		background: var(--rf-l0);
		color: var(--rf-l6);
	}
	.rf-seg-item input {
		position: absolute;
		inset: 0;
		margin: 0;
		opacity: 0;
		cursor: inherit;
	}
	.rf-seg-item:has(input:focus-visible) {
		outline: 2px solid var(--rf-focus);
		outline-offset: 2px;
	}
	.rf-seg:disabled .rf-seg-item {
		cursor: not-allowed;
	}
</style>
