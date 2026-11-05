<script lang="ts">
	import { sortedCountries } from '../lib/geo';

	interface Props {
		id: string;
		codes: string[];
		value: string;
		describedby?: string;
	}

	let { id, codes, value = $bindable(), describedby }: Props = $props();
	const options = $derived(sortedCountries(codes));
</script>

<select {id} class="rf-input" aria-describedby={describedby} bind:value>
	{#each options as option (option.code)}
		<option value={option.code}>{option.name}</option>
	{/each}
</select>
