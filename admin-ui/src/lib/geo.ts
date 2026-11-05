import { api } from './api';
import { boot } from './boot';
import { list, t } from './i18n';
import type { GeoNode, GeoStatus } from './types';

const locale = boot.locale.replace('_', '-');

let regions: Intl.DisplayNames | null = null;
try {
	regions = new Intl.DisplayNames([locale, 'en'], { type: 'region' });
} catch {
	regions = null;
}

const collator = new Intl.Collator(locale);
const joined = typeof Intl.ListFormat === 'function' ? new Intl.ListFormat(locale, { style: 'long', type: 'conjunction' }) : null;

export function country(code: string): string {
	if (!code) return '—';
	try {
		return regions?.of(code) ?? code;
	} catch {
		return code;
	}
}

export function continent(code: string): string {
	const key = 'geo_continent_' + code.toLowerCase();
	return key in boot.strings ? t(key) : code;
}

export function sortedCountries(codes: string[]): { code: string; name: string }[] {
	return codes.map((code) => ({ code, name: country(code) })).sort((a, b) => collator.compare(a.name, b.name));
}

export function hostOf(url: string): string {
	try {
		return new URL(url).host;
	} catch {
		return url;
	}
}

export function bareHost(host: string): string {
	return host.replace(/:\d+$/, '');
}

export function nodeName(node: GeoNode): string {
	return node.label || hostOf(node.url);
}

export function routeText(node: GeoNode): string {
	if (!node.routes) return '';
	const parts = [...node.routes.countries.map(country), ...node.routes.continents.map(continent)];
	if (node.routes.default) parts.push(t('geo_route_rest'));
	return joined ? joined.format(parts) : list(parts);
}

export function steps(prefix: string): string[] {
	const out: string[] = [];
	for (let index = 1; index < 20; index++) {
		const key = prefix + '_' + index;
		if (!(key in boot.strings)) break;
		out.push(t(key));
	}
	return out;
}

export function geo(path: string, body?: unknown): Promise<GeoStatus> {
	return api<GeoStatus>('geo/' + path, 'POST', body ?? {});
}

export function message(error: unknown): string {
	return error instanceof Error ? error.message : t('error_generic');
}

export async function copyText(text: string): Promise<boolean> {
	try {
		await navigator.clipboard.writeText(text);
		return true;
	} catch {
		return false;
	}
}
