import { boot } from './boot';

const locale = boot.locale.replace('_', '-');

let zone: string | undefined;
try {
	new Intl.DateTimeFormat(locale, { timeZone: boot.timezone });
	zone = boot.timezone;
} catch {
	zone = undefined;
}

const numbers = new Intl.NumberFormat(locale);
const dates = new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short', timeZone: zone });
const relative = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });

export function t(key: string, ...values: (string | number)[]): string {
	const template = boot.strings[key] ?? key;
	let index = 0;
	return template.replace(/%(?:(\d+)\$)?([sd])/g, (_, position: string | undefined, kind: string) => {
		const value = values[position ? Number(position) - 1 : index++];
		if (value === undefined) return '';
		return kind === 'd' || typeof value === 'number' ? numbers.format(Number(value)) : String(value);
	});
}

export function num(value: number): string {
	return numbers.format(value);
}

export function date(seconds: number): string {
	return seconds > 0 ? dates.format(new Date(seconds * 1000)) : '—';
}

export function ago(seconds: number, now = Date.now() / 1000): string {
	if (seconds <= 0) return t('never');
	const diff = Math.round(seconds - now);
	const abs = Math.abs(diff);
	if (abs < 45) return relative.format(0, 'second');
	if (abs < 3600) return relative.format(Math.round(diff / 60), 'minute');
	if (abs < 86400) return relative.format(Math.round(diff / 3600), 'hour');
	return relative.format(Math.round(diff / 86400), 'day');
}

export function duration(seconds: number): string {
	const minutes = Math.round(seconds / 60);
	if (minutes < 60) return t('minutes', minutes);
	const hours = Math.round(minutes / 6) / 10;
	if (hours < 48) return t('hours', hours);
	return t('days', Math.round(hours / 24));
}

export function bytes(value: number): string {
	if (value < 1024) return t('bytes_b', value);
	if (value < 1048576) return t('bytes_kb', Math.round(value / 102.4) / 10);
	return t('bytes_mb', Math.round(value / 104857.6) / 10);
}
