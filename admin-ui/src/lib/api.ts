import { boot } from './boot';
import { t } from './i18n';

export class ApiError extends Error {
	constructor(
		message: string,
		readonly status: number
	) {
		super(message);
	}
}

function endpoint(path: string): string {
	const root = boot.root;
	let address = root + path;
	if (root.includes('?')) {
		const [base, query] = root.split('?');
		const [route, extra] = path.split('?');
		const params = new URLSearchParams(query);
		params.set('rest_route', (params.get('rest_route') ?? '').replace(/\/$/, '') + '/' + route);
		new URLSearchParams(extra ?? '').forEach((value, key) => params.set(key, value));
		address = base + '?' + params.toString();
	}
	const url = new URL(address, location.href);
	url.searchParams.set('_locale', 'user');
	return url.toString();
}

export async function api<T>(path: string, method: 'GET' | 'POST' = 'GET', body?: unknown): Promise<T> {
	let response: Response;
	try {
		response = await fetch(endpoint(path), {
			method,
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': boot.nonce, 'Content-Type': 'application/json', Accept: 'application/json' },
			body: body === undefined ? undefined : JSON.stringify(body)
		});
	} catch {
		throw new ApiError(t('error_network'), 0);
	}
	const data = await response.json().catch(() => null);
	if (!response.ok) {
		const message = data && typeof data.message === 'string' ? data.message : t('error_generic');
		throw new ApiError(message, response.status);
	}
	return data as T;
}
