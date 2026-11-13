<script lang="ts">
	interface Props {
		text: string;
	}

	let { text }: Props = $props();

	const RTL = /[\u0600-\u06FF]/;
	const LATIN = /(\.?[…A-Za-z0-9][…A-Za-z0-9._\-/*:]*(?: [A-Za-z0-9][…A-Za-z0-9._\-/*:]*)*)/;

	const parts = $derived(RTL.test(text) ? text.split(LATIN) : [text]);
</script>

{#each parts as part, index (index)}{#if index % 2 === 1}<bdi dir="ltr" class="rf-latin">{part}</bdi>{:else}{part}{/if}{/each}

<style>
	.rf-latin {
		white-space: nowrap;
	}
</style>
