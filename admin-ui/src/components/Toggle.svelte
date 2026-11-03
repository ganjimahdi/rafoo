<script lang="ts">
	interface Props {
		label: string;
		description?: string;
		checked: boolean;
		disabled?: boolean;
	}

	let { label, description, checked = $bindable(), disabled = false }: Props = $props();
	const uid = $props.id();
	const id = 'rf-toggle-' + uid;
</script>

<div class="rf-toggle">
	<span class="rf-switch">
		<input
			{id}
			type="checkbox"
			role="switch"
			bind:checked
			{disabled}
			aria-describedby={description ? id + '-desc' : undefined}
		/>
		<span class="rf-track" aria-hidden="true"></span>
	</span>
	<span class="rf-text">
		<label for={id}>{label}</label>
		{#if description}<span id={id + '-desc'} class="rf-desc">{description}</span>{/if}
	</span>
</div>

<style>
	.rf-toggle {
		display: flex;
		align-items: flex-start;
		gap: 12px;
	}
	.rf-switch {
		position: relative;
		flex: none;
		inline-size: 36px;
		block-size: 20px;
		margin-block-start: 2px;
	}
	.rf-switch input {
		position: absolute;
		inset: 0;
		inline-size: 100%;
		block-size: 100%;
		margin: 0;
		opacity: 0;
		cursor: pointer;
		z-index: 1;
	}
	.rf-switch input:disabled {
		cursor: not-allowed;
	}
	.rf-track {
		position: absolute;
		inset: 0;
		border: 1px solid var(--rf-l3);
		border-radius: var(--rf-r-pill);
		background: var(--rf-l1);
		transition: background-color var(--rf-dur-2) var(--rf-ease-out);
	}
	.rf-track::after {
		content: '';
		position: absolute;
		inset-block-start: 2px;
		inset-inline-start: 2px;
		inline-size: 14px;
		block-size: 14px;
		border-radius: var(--rf-r-pill);
		background: var(--rf-l4);
		transition:
			translate var(--rf-dur-2) var(--rf-ease-out),
			background-color var(--rf-dur-2) var(--rf-ease-out);
	}
	.rf-switch input:checked + .rf-track {
		border-color: var(--rf-l5);
		background: var(--rf-l5);
	}
	.rf-switch input:checked + .rf-track::after {
		background: var(--rf-l0);
		translate: 16px 0;
	}
	:global(.rafoo[dir='rtl']) .rf-switch input:checked + .rf-track::after {
		translate: -16px 0;
	}
	.rf-switch input:focus-visible + .rf-track {
		outline: 2px solid var(--rf-focus);
		outline-offset: 2px;
	}
	.rf-switch input:disabled + .rf-track {
		opacity: 0.5;
	}
	.rf-text {
		display: flex;
		flex-direction: column;
		gap: 2px;
	}
	.rf-text label {
		font-weight: 700;
		cursor: pointer;
	}
	.rf-desc {
		color: var(--rf-l4);
		font-size: var(--rf-fs-sm);
	}
</style>
