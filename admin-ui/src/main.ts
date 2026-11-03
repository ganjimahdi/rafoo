import { mount } from 'svelte';
import App from './App.svelte';
import './styles.css';

const target = document.getElementById('rafoo-app');

if (target && window.rafooBoot) {
	target.replaceChildren();
	mount(App, { target });
}
