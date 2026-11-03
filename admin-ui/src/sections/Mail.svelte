<script lang="ts">
	import { onMount } from 'svelte';
	import Button from '../components/Button.svelte';
	import Card from '../components/Card.svelte';
	import Notice from '../components/Notice.svelte';
	import Segmented from '../components/Segmented.svelte';
	import { api } from '../lib/api';
	import { ago, num, t } from '../lib/i18n';
	import type { MailReport, RelaySecure } from '../lib/types';

	let report = $state.raw<MailReport | null>(null);
	let busy = $state('');
	let error = $state('');
	let message = $state('');
	let relayError = $state('');
	let relayMessage = $state('');

	const relay = $state({ host: '', port: 587, secure: 'tls' as RelaySecure, user: '', pass: '', from: '' });

	function fill(next: MailReport) {
		report = next;
		relay.host = next.relay.host;
		relay.port = next.relay.port;
		relay.secure = next.relay.secure;
		relay.user = next.relay.user;
		relay.from = next.relay.from;
		relay.pass = '';
	}

	async function act(id: string, task: () => Promise<void>, scope: 'queue' | 'relay' = 'queue') {
		busy = id;
		error = relayError = message = relayMessage = '';
		try {
			await task();
		} catch (e) {
			const text = e instanceof Error ? e.message : t('error_generic');
			if (scope === 'relay') relayError = text;
			else error = text;
		}
		busy = '';
	}

	onMount(() => {
		act('load', async () => fill(await api<MailReport>('mail')));
	});

	function sendNow() {
		return act('send', async () => {
			fill(await api<MailReport>('mail/send', 'POST'));
			const waiting = report?.queue.length ?? 0;
			message = waiting === 0 ? t('mail_all_sent') : t('mail_send_result', num(waiting));
		});
	}

	function remove(id: string) {
		return act('delete' + id, async () => fill(await api<MailReport>('mail/delete', 'POST', { id })));
	}

	function save(event: SubmitEvent) {
		event.preventDefault();
		const body: Record<string, unknown> = { host: relay.host.trim(), port: Number(relay.port) || 0, secure: relay.secure, user: relay.user.trim(), from: relay.from.trim() };
		if (relay.pass !== '') body.pass = relay.pass;
		return act(
			'relay',
			async () => {
				fill(await api<MailReport>('mail/relay', 'POST', body));
				relayMessage = t('relay_saved');
			},
			'relay'
		);
	}

	function forget() {
		return act(
			'forget',
			async () => {
				fill(await api<MailReport>('mail/relay', 'POST', { host: '' }));
				relayMessage = t('relay_removed');
			},
			'relay'
		);
	}

	function test() {
		return act(
			'test',
			async () => {
				await api<{ sent: boolean }>('mail/test', 'POST');
				relayMessage = t('relay_test_sent');
			},
			'relay'
		);
	}

	function secureChanged(value: RelaySecure) {
		relay.secure = value;
		if (value === 'ssl' && relay.port === 587) relay.port = 465;
		if (value === 'tls' && relay.port === 465) relay.port = 587;
	}
</script>

<Card title={t('mail_title')} description={t('mail_help')}>
	{#snippet actions()}
		<Button onclick={sendNow} busy={busy === 'send'} disabled={busy !== '' || !report || report.queue.length === 0}>{t('mail_send_now')}</Button>
	{/snippet}

	{#if message}<Notice tone="success" live>{message}</Notice>{/if}
	{#if error}<Notice tone="danger" live>{error}</Notice>{/if}

	{#if report === null}
		<p class="rf-subtle">{t('loading')}</p>
	{:else}
		{#if !report.enabled}<Notice tone="info">{t('mail_off')}</Notice>{/if}
		{#if report.down}<Notice tone="warning">{t('mail_down')}</Notice>{/if}
		{#if report.queue.length === 0}
			<p class="rf-subtle">{t('mail_empty')}</p>
		{:else}
			<div class="rf-table-wrap">
				<table class="rf-table">
					<caption class="rf-sr">{t('mail_title')}</caption>
					<thead>
						<tr>
							<th scope="col">{t('col_to')}</th>
							<th scope="col">{t('col_subject')}</th>
							<th scope="col">{t('col_attempts')}</th>
							<th scope="col">{t('col_next')}</th>
							<th scope="col"><span class="rf-sr">{t('col_actions')}</span></th>
						</tr>
					</thead>
					<tbody>
						{#each report.queue as item (item.id)}
							<tr>
								<td class="rf-mono rf-small">{item.to}</td>
								<td>
									<div class="rf-mail">
										<span>{item.subject}</span>
										{#if item.error}<span class="rf-subtle rf-small">{item.error}</span>{/if}
									</div>
								</td>
								<td>{num(item.attempts)}</td>
								<td class="rf-small rf-subtle">{ago(item.next)}</td>
								<td>
									<Button size="sm" variant="ghost" disabled={busy !== ''} busy={busy === 'delete' + item.id} onclick={() => remove(item.id)}>{t('delete')}</Button>
								</td>
							</tr>
						{/each}
					</tbody>
				</table>
			</div>
		{/if}
	{/if}
</Card>

<Card title={t('relay_title')} description={t('relay_help')}>
	<form class="rf-stack" onsubmit={save} novalidate>
		<div class="rf-relay">
			<div class="rf-field">
				<label for="rafoo-relay-host">{t('relay_host')}</label>
				<input id="rafoo-relay-host" class="rf-input rf-mono" type="text" autocomplete="off" spellcheck="false" placeholder="mail.example.ir" bind:value={relay.host} />
			</div>
			<div class="rf-field">
				<label for="rafoo-relay-port">{t('relay_port')}</label>
				<input id="rafoo-relay-port" class="rf-input rf-mono" type="number" min="1" max="65535" inputmode="numeric" bind:value={relay.port} />
			</div>
			<div class="rf-field rf-wide rf-start">
				<span class="rf-label" aria-hidden="true">{t('relay_secure')}</span>
				<Segmented
					name="rafoo-relay-secure"
					label={t('relay_secure')}
					value={relay.secure}
					onchange={secureChanged}
					options={[
						{ value: 'tls', label: t('relay_secure_tls') },
						{ value: 'ssl', label: t('relay_secure_ssl') },
						{ value: '', label: t('relay_secure_none') }
					]}
				/>
				{#if relay.secure === ''}<p class="rf-subtle rf-small">{t('relay_secure_none_help')}</p>{/if}
			</div>
			<div class="rf-field">
				<label for="rafoo-relay-user">{t('relay_user')}</label>
				<input id="rafoo-relay-user" class="rf-input rf-mono" type="text" autocomplete="off" spellcheck="false" bind:value={relay.user} />
			</div>
			<div class="rf-field">
				<label for="rafoo-relay-pass">{t('relay_pass')}</label>
				<input
					id="rafoo-relay-pass"
					class="rf-input rf-mono"
					type="password"
					autocomplete="new-password"
					placeholder={report?.relay.has_pass ? t('relay_pass_kept') : ''}
					bind:value={relay.pass}
				/>
			</div>
			<div class="rf-field rf-wide">
				<label for="rafoo-relay-from">{t('relay_from')}</label>
				<p id="rafoo-relay-from-help" class="rf-subtle rf-small">{t('relay_from_help')}</p>
				<input id="rafoo-relay-from" class="rf-input rf-mono" type="email" autocomplete="off" aria-describedby="rafoo-relay-from-help" bind:value={relay.from} />
			</div>
		</div>

		{#if relayMessage}<Notice tone="success" live>{relayMessage}</Notice>{/if}
		{#if relayError}<Notice tone="danger" live>{relayError}</Notice>{/if}

		<div class="rf-row rf-relay-actions">
			{#if report?.relay.host}
				<Button variant="ghost" onclick={forget} busy={busy === 'forget'} disabled={busy !== ''}>{t('relay_remove')}</Button>
				<Button onclick={test} busy={busy === 'test'} disabled={busy !== ''}>{t('relay_test', report.admin)}</Button>
			{/if}
			<Button type="submit" variant="primary" busy={busy === 'relay'} disabled={busy !== ''}>{t('relay_save')}</Button>
		</div>
	</form>
</Card>

<style>
	.rf-mail {
		display: flex;
		flex-direction: column;
		gap: 2px;
	}
	.rf-relay {
		display: grid;
		grid-template-columns: repeat(2, minmax(0, 1fr));
		gap: 16px;
		max-inline-size: 640px;
	}
	.rf-relay .rf-wide {
		grid-column: 1 / -1;
	}
	.rf-relay .rf-start {
		align-items: flex-start;
	}
	.rf-label {
		font-weight: 700;
	}
	.rf-relay-actions {
		justify-content: flex-end;
	}
	@media (max-width: 600px) {
		.rf-relay {
			grid-template-columns: minmax(0, 1fr);
		}
	}
</style>
