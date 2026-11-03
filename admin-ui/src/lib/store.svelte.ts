import { api } from './api';
import { boot } from './boot';
import type { Asset, Overview, Override, Preset, Settings } from './types';

function syncAdminBar(overview: Overview) {
	const offline = overview.mode === 'offline';
	let node = document.getElementById('wp-admin-bar-rafoo');
	if (!node && offline) {
		const bar = document.querySelector('#wp-admin-bar-root-default');
		if (!bar) return;
		node = document.createElement('li');
		node.id = 'wp-admin-bar-rafoo';
		const link = document.createElement('a');
		link.className = 'ab-item';
		link.href = location.pathname + location.search;
		link.textContent = boot.strings.admin_bar_offline ?? '';
		node.append(link);
		bar.append(node);
	}
	if (node) node.style.display = offline ? '' : 'none';
}

class Store {
	overview = $state.raw<Overview>(boot.overview);

	constructor() {
		$effect.root(() => {
			$effect(() => syncAdminBar(this.overview));
		});
	}
	settings = $state.raw<Settings>(boot.settings);
	assets = $state.raw<Asset[] | null>(null);

	async refresh() {
		this.overview = await api<Overview>('overview');
	}

	async check() {
		this.overview = await api<Overview>('check', 'POST');
	}

	async setMode(mode: Override) {
		this.overview = await api<Overview>('mode', 'POST', { mode });
	}

	async loadAssets() {
		this.assets = await api<Asset[]>('assets');
	}

	async assetAction(action: 'sync' | 'scan' | 'retry' | 'remove' | 'exclude', body?: Record<string, unknown>) {
		const result = await api<{ assets: Asset[] }>('assets/' + action, 'POST', body);
		this.assets = result.assets;
		await this.refresh();
	}

	async saveSettings(changes: Partial<Settings>) {
		this.settings = await api<Settings>('settings', 'POST', changes);
	}

	async setup(preset: Preset | 'custom' | 'skip', settings: Partial<Settings> = {}) {
		this.settings = await api<Settings>('setup', 'POST', { preset, settings });
	}
}

export const store = new Store();
