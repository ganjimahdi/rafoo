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
	shield: ShieldCard;
	edition: 'standard' | 'complete';
	version: string;
	paused: boolean;
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
	backup_exclude: string[];
	backup_part: 128 | 256 | 512 | 1024 | 2048;
	browser_guard: boolean;
	dedupe: boolean;
	heartbeat: 'smart' | 'default';
	block_hosts: string[];
	scan_as_user: boolean;
	shield: ShieldMode;
	shield_network: boolean;
	paused: boolean;
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
	state?: 'update' | 'current' | 'older' | 'missing' | 'own';
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
	blocked: boolean;
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

export interface BackupWarning {
	code: string;
	count: number;
	samples: string[];
}

export interface BackupJob {
	id: string;
	kind: 'backup' | 'restore' | 'receive' | 'send' | 'move' | 'check';
	step: string;
	phase: string;
	status: 'running' | 'done' | 'failed' | 'stalled';
	error: string;
	detail: Record<string, string | number | boolean>;
	started: number;
	updated: number;
	progress: { done: number; total: number };
	info: Record<string, string | number>;
	warnings: BackupWarning[];
	retried: string;
	role: string;
	backup: string;
	notice: '' | 'login_again' | 'checked' | { url: string; key: string };
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
	format: number;
	parts: number;
	warnings: BackupWarning[];
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
	support: {
		zip: boolean;
		gzip: boolean;
		part: number;
		excluded: string[];
		crypt: boolean;
		ftp: boolean;
		implicit: boolean;
		free: number;
		space?: 'quota' | 'disk' | 'shared' | 'unknown';
		writable: boolean;
	};
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
	context: 'site' | 'network';
	overview: Overview;
	settings: Settings;
	network: NetworkView | null;
	managed: Managed;
	privacy_url: string;
}

export type NetworkPolicy = 'defaults' | 'enforced';

export interface NetworkView {
	policy: NetworkPolicy;
	settings: Partial<Settings>;
	keys: (keyof Settings)[];
	sites: number;
}

export interface Managed {
	enforced: boolean;
	keys: (keyof Settings)[];
	url: string;
}

export interface PeerWatcher {
	country: string;
	network: string;
	scope: 'ir' | 'intl';
	at: string;
	ok: boolean;
	ms: number;
	checks_today: number;
	ok_today: number;
}

export interface PeerSlot {
	slot: number;
	scope: 'ir' | 'intl';
	ref: string;
	state: 'ok' | 'down' | 'filtered' | 'waiting';
	at: number;
	ms: number;
	code: number;
	checks_today: number;
	ok_today: number;
}

export interface PeerDay {
	day: string;
	scope: 'ir' | 'intl';
	checks: number;
	ok: number;
	ms: number;
}

export interface PeerRemote {
	member: number;
	country: string;
	network: string;
	scope: 'ir' | 'intl';
	interval: number;
	intl: 'up' | 'down';
	watchers: PeerWatcher[] | null;
	slots?: PeerSlot[] | null;
	wake_in?: number;
	days: PeerDay[] | null;
	assigned: number;
	watching: number;
	network_size: { members: number; iran: number; abroad: number };
}

export interface PeerStatus {
	member: boolean;
	ready: boolean;
	interval: number;
	joined_at: number;
	country: string;
	network: string;
	scope: 'ir' | 'intl';
	watching: number;
	checked: number;
	pending: number;
	sentinel: boolean;
	offline: boolean;
	detached: boolean;
	results: { id: number; at: number; ok: boolean; ms: number }[];
	remote: PeerRemote | null;
	error: string;
	now: number;
}

export type GeoProvider = 'arvan' | 'cloudflare' | 'route53' | 'gcore' | 'bunny';
export type GeoPanel = 'cpanel' | 'directadmin';
export type GeoMethodType = '' | 'provider' | 'panel' | 'manual';

export interface GeoProbe {
	ok: boolean;
	ms?: number;
	at: number;
	last_ok?: number;
	fails: number;
	gen?: number;
	offline?: boolean;
	version?: string;
	error?: string;
}

export interface GeoSync {
	gen: number;
	announced: number;
	ok_at: number;
	busy: boolean;
	error: string;
	lag: number;
	table: string;
	files: string;
	contact: number;
}

export interface GeoCrawl {
	hits: number;
	codes: Record<'2' | '3' | '4' | '5', number>;
	ms: number;
	last: number;
	verified: number;
	fake: number;
}

export interface GeoHealth {
	up: boolean;
	self: GeoProbe | null;
	views: Record<string, GeoProbe>;
	sync: GeoSync | null;
	crawl: Record<string, GeoCrawl> | null;
	version: string;
	offline: boolean;
	report: number;
}

export interface GeoPanelLogin {
	url: string;
	user: string;
	token: boolean;
}

export interface GeoNode {
	id: string;
	label: string;
	url: string;
	ip: string;
	country: string;
	primary: boolean;
	status: 'ready' | 'seeding' | 'failed' | string;
	error: string;
	self: boolean;
	added: number;
	health: GeoHealth | null;
	routes: { countries: string[]; continents: string[]; default: boolean } | null;
	panel: Record<GeoPanel, GeoPanelLogin>;
}

export interface GeoMethod {
	type: GeoMethodType;
	provider: '' | GeoProvider;
	panel: '' | GeoPanel;
	ttl: number;
}

export interface GeoCheck {
	redirect: boolean;
	print: boolean;
	same: boolean;
	home: number;
	canonical: boolean;
	noindex: boolean;
}

export interface GeoVerify {
	zone: string;
	nameservers: Record<string, { ips: string[] | null; known: boolean }>;
	local: string[];
	subs: Record<string, { want: string; got: string }>;
	at: number;
}

export interface GeoTestResult {
	ok: boolean;
	error?: string;
	zone?: string;
	nameservers?: string[];
}

export interface GeoStatus {
	role: '' | 'joining' | 'primary' | 'copy';
	host: string;
	home: string;
	nodes: GeoNode[];
	method: GeoMethod;
	secrets: Record<GeoProvider, Record<string, boolean>>;
	dns: { hash?: string; at?: number; ok?: boolean; error?: string; auto?: boolean; by?: string };
	checks: { at?: number; results?: Record<string, GeoCheck> };
	verify: { at: number; results: Record<string, GeoVerify | null> } | null;
	sync: GeoSync | null;
	gen: number;
	list: { at: number; files: number; bytes: number } | null;
	join: { url: string; expires: number } | null;
	job: BackupJob | null;
	multisite: boolean;
	prefix: string;
	config: boolean;
	suggest: { primary: string; copy: string };
	relayed: { path: string; from: string; at: number } | null;
	max: number;
	disk: number;
	countries: string[];
	code?: string;
	test?: Record<string, GeoTestResult> | null;
}

export type DrillKind = 'home' | 'post' | 'page' | 'shop' | 'product' | 'cart' | 'checkout' | 'account' | 'login' | 'dashboard' | 'editor' | 'custom' | 'visit';
export type DrillSeverity = 'blocker' | 'warning' | 'info' | 'handled';
export type DrillFrame = 'ok' | 'blocked' | 'timeout' | 'error' | 'skipped' | 'manual';
export type DrillAction = 'download' | 'include' | 'setting' | 'block' | 'allow' | 'scan' | 'sync' | 'resume';

export interface DrillCandidate {
	kind: DrillKind;
	url: string;
	admin: boolean;
	title: string;
}

export interface DrillPagePlan {
	id: number;
	kind: DrillKind;
	url: string;
	base: string;
	plain: string;
	admin: boolean;
	title: string;
}

export interface DrillSession {
	run: string;
	manual: boolean;
	guest: boolean;
	started: number;
	expires: number;
	pages: DrillPagePlan[];
	home: string;
}

export interface DrillReadiness {
	key: string;
	ok: boolean;
	sev: DrillSeverity;
	fix: string;
	meta: Record<string, number | boolean>;
}

export interface DrillFix {
	a: DrillAction;
	t: string;
}

export interface DrillIssue {
	id: string;
	cat: string;
	sev: DrillSeverity;
	subject: string;
	pages: number[];
	count: number;
	meta: Record<string, string | number | boolean>;
	fix: DrillFix[];
	origins: string[];
}

export interface DrillPageResult {
	id: number;
	kind: DrillKind;
	url: string;
	title: string;
	admin: boolean;
	code: number;
	server: number;
	load: number;
	normal: number;
	frame: DrillFrame;
	fatal: boolean;
	status: 'ok' | 'warning' | 'broken' | 'unknown';
	counts: Record<DrillSeverity, number>;
}

export interface DrillReport {
	run: string;
	at: number;
	started: number;
	mode: 'frame' | 'manual';
	guest: boolean;
	score: number;
	grade: 'ready' | 'good' | 'weak' | 'poor';
	pages: DrillPageResult[];
	issues: DrillIssue[];
	stats: { local: number; handled: number; refused: number; embeds: number; requests: number; foreign: number };
	readiness: DrillReadiness[];
	names: Record<string, string>;
	fixed: string[];
}

export interface DrillHistory {
	run: string;
	at: number;
	score: number;
	grade: DrillReport['grade'];
	mode: DrillReport['mode'];
	pages: number;
	counts: Record<DrillSeverity, number>;
}

export interface DrillView {
	active: DrillSession | null;
	candidates: DrillCandidate[];
	prefs: { kinds: DrillKind[]; custom: string[]; guest: boolean; compare: boolean };
	readiness: DrillReadiness[];
	report: DrillReport | null;
	history: DrillHistory[];
	paused: boolean;
	network: boolean;
	offline: boolean;
	edition: 'standard' | 'complete';
	ttl: number;
	now: number;
	done?: { state: string; error?: string; key?: string };
}

export interface DrillClient {
	u: string;
	s: number;
	l: number;
	r: { u: string; b: number; i: string; d: number }[];
	x: string[];
	e: { m: string; f: string; l: number }[];
	g: string[];
}

export type ShieldMode = 'off' | 'smart' | 'strict' | 'fortress';

export interface ShieldCard {
	mode: ShieldMode;
	setting: ShieldMode;
	today: number;
	week: number;
	network: boolean;
	early: boolean;
}

export interface ShieldCount {
	name: string;
	count: number;
}

export interface ShieldEvent {
	t: number;
	r: string;
	i: string;
	a: 'block' | 'limit' | 'locked' | 'lock' | 'challenge';
	g: string;
	u: string;
	w: string;
	m: string;
	p: string;
	b: string;
	x: string;
	k?: number;
}

export interface ShieldView {
	card: ShieldCard;
	stats: {
		today: Record<string, number>;
		week: Record<string, number>;
		groups: ShieldCount[];
		ips: ShieldCount[];
		paths: ShieldCount[];
		series: { day: string; stopped: number }[];
		stopped_today: number;
		stopped_week: number;
	};
	events: ShieldEvent[];
	allow: string[];
	deny: string[];
	proxies: string[];
	prepend: { status: 'active' | 'broken' | 'conflict' | 'pending' | 'unsupported' | 'off'; method: string; enabled: boolean; other: string; cache: number };
	first: boolean;
	running: boolean;
	apcu: boolean;
	you: string;
	network: {
		enabled: boolean;
		joined: number;
		count: number;
		sent: number;
		checked: number;
		rev: number;
		error: string;
		patches: { id: string; plugin: string; title: string }[];
	};
	edge: {
		provider: '' | 'arvan' | 'cloudflare';
		domain: string;
		count: number;
		error: string;
		at: number;
		geo: { arvan: boolean; cloudflare: boolean };
		host: string;
	};
	now: number;
}
