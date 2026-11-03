import type { Boot } from './types';

declare global {
	interface Window {
		rafooBoot?: Boot;
	}
}

export const boot = window.rafooBoot as Boot;
