export type Mode = 'online' | 'offline';
export type Override = 'auto' | 'offline' | 'online';

export interface Target {
	id: string;
	group: 'external' | 'domestic';
	ok: boolean;
	code: number;
}

export interface Summary {
	ready: number;
	unverified: number;
	pending: number;
	failed: number;
	bytes: number;
}

export interface RafooEvent {
	type: string;
	at: number;
	data: Record<string, unknown>;
}

export interface Overview {
	mode: Mode;
	detected: 'unknown' | 'online' | 'offline';
	override: Override;
	checked_at: number;
	changed_at: number;
	outage_total: number;
	local_down: boolean;
	targets: Target[];
	hint: { state: 'up' | 'down'; issued_at: number } | null;
	signature: boolean;
	assets: Summary;
	breaker: { host: string; until: number }[];
	events: RafooEvent[];
	edition: 'standard' | 'complete';
	version: string;
	network: boolean;
	cron: { disabled: boolean; next_check: number; late: boolean };
	now: number;
}

export type AssetStatus = 'ready' | 'pending' | 'failed' | 'excluded';

export interface Asset {
	key: string;
	origin: string;
	local: string;
	kind: 'asset' | 'font';
	type: string;
	cdn: string;
	package: string;
	version: string;
	status: AssetStatus;
	verified: '' | 'sri' | 'origin' | 'index';
	source: '' | 'mirror' | 'origin';
	size: number;
	files: number;
	error: string;
	updated_at: number;
}

export type Choice3 = 'off' | 'outage' | 'always';

export interface Settings {
	replace: 'always' | 'outage' | 'off';
	html_scan: boolean;
	fonts: boolean;
	avatars: Choice3;
	embeds: boolean;
	updates: Choice3;
	telemetry: boolean;
	consent_at: number;
	allow_hosts: string[];
	core_assets: boolean;
	analytics: Choice3;
	social_login: boolean;
	mail_queue: boolean;
	request_log: boolean;
	captcha: Choice3;
	safe_updates: boolean;
	translations: boolean;
	backup: boolean;
	backup_hours: 6 | 12 | 24 | 48 | 168;
	backup_hour: number;
	backup_mode: 'incremental' | 'full';
	backup_local: boolean;
	setup: '' | 'done' | 'skipped';
	preset: '' | Preset | 'custom';
}

export type Preset = 'recommended' | 'outage' | 'maximum';

export interface UpdateItem {
	type: 'plugin' | 'theme' | 'core';
	slug: string;
	name: string;
	installed: string;
	latest: string;
	in_mirror: boolean;
}

export interface UpdatesReport {
	available: boolean;
	items: UpdateItem[];
	checked_at: number;
}

export interface RequestEntry {
	origin: string;
	name: string;
	host: string;
	foreign: boolean;
	count: number;
	ok: number;
	failed: number;
	refused: number;
	average: number;
	last: number;
}

export interface RequestsReport {
	enabled: boolean;
	items: RequestEntry[];
}

export interface MailItem {
	id: string;
	to: string;
	subject: string;
	attempts: number;
	created: number;
	next: number;
	error: string;
}

export type RelaySecure = '' | 'tls' | 'ssl';

export interface Relay {
	host: string;
	port: number;
	secure: RelaySecure;
	user: string;
	from: string;
	has_pass: boolean;
}

export interface MailReport {
	enabled: boolean;
	down: boolean;
	queue: MailItem[];
	relay: Relay;
	admin: string;
}

export interface RollbackVersion {
	version: string;
	size: number;
	at: number;
}

export interface RollbackItem {
	type: 'plugin' | 'theme';
	slug: string;
	name: string;
	installed: string;
	versions: RollbackVersion[];
}

export interface RollbackReport {
	enabled: boolean;
	available: boolean;
	items: RollbackItem[];
}

export interface HealthReport {
	healthy: boolean;
	reason: string;
}

export interface BackupJob {
	id: string;
	kind: 'backup' | 'restore' | 'receive' | 'send' | 'move';
	step: string;
	status: 'running' | 'done' | 'failed' | 'stalled';
	error: string;
	started: number;
	updated: number;
	progress: { done: number; total: number };
	role: string;
	backup: string;
	notice: '' | 'login_again' | { url: string; key: string };
}

export interface BackupEntry {
	id: string;
	created: number;
	kind: 'full' | 'incremental';
	size: number;
	files: number;
	tables: number;
	encrypted: boolean;
	complete: boolean;
	local: boolean;
	places: string[];
	wp: string;
}

export interface Destination {
	id: string;
	label: string;
	type: 'sftp' | 'ftps' | 'ftps-implicit' | 'ftp';
	host: string;
	port: number;
	user: string;
	path: string;
	speed: number;
	free: number;
	plain: boolean;
	last_ok: number;
	last_error: string;
}

export interface BackupStatus {
	job: BackupJob | null;
	backups: BackupEntry[];
	destinations: Destination[];
	next: number;
	last: number;
	password: boolean;
	support: { zip: boolean; crypt: boolean; ftp: boolean; implicit: boolean; free: number; writable: boolean };
	invite: { expires: number } | null;
	frozen: boolean;
	download: string;
	home: string;
	code?: { code: string; short: string; expires: number };
	added?: { type: string; path: string; speed: number; plain: boolean };
}

export interface Boot {
	root: string;
	nonce: string;
	edition: 'standard' | 'complete';
	version: string;
	rtl: boolean;
	locale: string;
	timezone: string;
	strings: Record<string, string>;
	overview: Overview;
	settings: Settings;
	privacy_url: string;
}
