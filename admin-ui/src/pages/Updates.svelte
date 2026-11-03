<script lang="ts">
	import { boot } from '../lib/boot';
	import Mirror from '../sections/Mirror.svelte';
	import Rollback from '../sections/Rollback.svelte';
</script>

<div class="rf-stack">
	{#if boot.edition === 'complete'}<Mirror />{/if}
	<Rollback />
</div>
