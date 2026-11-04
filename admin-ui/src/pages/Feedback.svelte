<script lang="ts">
	import Button from '../components/Button.svelte';
	import Card from '../components/Card.svelte';
	import Notice from '../components/Notice.svelte';
	import { api } from '../lib/api';
	import { help, t } from '../lib/i18n';

	let message = $state('');
	let contact = $state('');
	let sending = $state(false);
	let error = $state('');
	let sent = $state(false);

	const length = $derived(message.trim().length);

	async function send(event: SubmitEvent) {
		event.preventDefault();
		error = '';
		sent = false;
		if (length < 5) {
			error = t('feedback_short');
			return;
		}
		sending = true;
		try {
			await api('feedback', 'POST', { message, contact });
			sent = true;
			message = '';
			contact = '';
		} catch (e) {
			error = e instanceof Error ? e.message : t('error_generic');
		}
		sending = false;
	}
</script>

<Card title={t('feedback_title')} help={help('card_feedback')} description={t('feedback_help')}>
	<form class="rf-stack" onsubmit={send} novalidate>
		<div class="rf-field">
			<label for="rafoo-feedback">{t('feedback_message')}</label>
			<textarea id="rafoo-feedback" class="rf-input" rows="6" maxlength="2000" bind:value={message} aria-invalid={error !== '' && length < 5}></textarea>
		</div>
		<div class="rf-field">
			<label for="rafoo-contact">{t('feedback_contact')}</label>
			<p id="rafoo-contact-help" class="rf-subtle rf-small">{t('feedback_contact_help')}</p>
			<input id="rafoo-contact" class="rf-input" type="text" maxlength="200" autocomplete="email" aria-describedby="rafoo-contact-help" bind:value={contact} />
		</div>
		<p class="rf-subtle rf-small">{t('feedback_privacy')}</p>
		{#if sent}<Notice tone="success" live>{t('feedback_sent')}</Notice>{/if}
		{#if error}<Notice tone="danger" live>{error}</Notice>{/if}
		<div class="rf-row">
			<Button type="submit" variant="primary" busy={sending}>{t('send')}</Button>
		</div>
	</form>
</Card>
