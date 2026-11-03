(function (scope) {
	'use strict';

	var K = [
		0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
		0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
		0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
		0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
		0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
		0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
		0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
		0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2
	];

	var w = new Array(64);

	function rotate(x, n) {
		return (x >>> n) | (x << (32 - n));
	}

	function sha256(text) {
		var length = text.length;
		var words = [];
		var i;
		for (i = 0; i < length; i++) {
			words[i >> 2] |= (text.charCodeAt(i) & 0xff) << (24 - (i % 4) * 8);
		}
		words[length >> 2] |= 0x80 << (24 - (length % 4) * 8);
		var last = (((length + 8) >> 6) + 1) * 16 - 1;
		words[last] = length * 8;

		var h0 = 0x6a09e667, h1 = 0xbb67ae85, h2 = 0x3c6ef372, h3 = 0xa54ff53a;
		var h4 = 0x510e527f, h5 = 0x9b05688c, h6 = 0x1f83d9ab, h7 = 0x5be0cd19;

		for (var block = 0; block < last; block += 16) {
			var a = h0, b = h1, c = h2, d = h3, e = h4, f = h5, g = h6, h = h7;
			for (var t = 0; t < 64; t++) {
				if (t < 16) {
					w[t] = words[block + t] | 0;
				} else {
					var x = w[t - 15];
					var y = w[t - 2];
					w[t] = (w[t - 16] + (rotate(x, 7) ^ rotate(x, 18) ^ (x >>> 3)) + w[t - 7] + (rotate(y, 17) ^ rotate(y, 19) ^ (y >>> 10))) | 0;
				}
				var t1 = (h + (rotate(e, 6) ^ rotate(e, 11) ^ rotate(e, 25)) + ((e & f) ^ (~e & g)) + K[t] + w[t]) | 0;
				var t2 = ((rotate(a, 2) ^ rotate(a, 13) ^ rotate(a, 22)) + ((a & b) ^ (a & c) ^ (b & c))) | 0;
				h = g;
				g = f;
				f = e;
				e = (d + t1) | 0;
				d = c;
				c = b;
				b = a;
				a = (t1 + t2) | 0;
			}
			h0 = (h0 + a) | 0;
			h1 = (h1 + b) | 0;
			h2 = (h2 + c) | 0;
			h3 = (h3 + d) | 0;
			h4 = (h4 + e) | 0;
			h5 = (h5 + f) | 0;
			h6 = (h6 + g) | 0;
			h7 = (h7 + h) | 0;
		}

		var out = '';
		var parts = [h0, h1, h2, h3, h4, h5, h6, h7];
		for (i = 0; i < 8; i++) {
			out += ('00000000' + (parts[i] >>> 0).toString(16)).slice(-8);
		}
		return out;
	}

	function search(salt, challenge, from, to) {
		for (var n = from; n <= to; n++) {
			if (sha256(salt + n) === challenge) {
				return n;
			}
		}
		return -1;
	}

	if (typeof document === 'undefined') {
		scope.onmessage = function (event) {
			var job = event.data || {};
			scope.postMessage({ number: search(String(job.salt), String(job.challenge), 0, job.max | 0) });
		};
		return;
	}

	var config = scope.rafooCaptcha || {};
	var source = document.currentScript ? document.currentScript.src : '';
	var states = typeof WeakMap === 'function' ? new WeakMap() : null;
	var FIELD = 'rafoo_captcha';
	var OUTSIDE = '.g-recaptcha, .h-captcha, .cf-turnstile, .wpcf7-recaptcha, .wpcf7-turnstile, [name="g-recaptcha-response"], [name="h-captcha-response"], [name="cf-turnstile-response"], [name="_wpcf7_recaptcha_response"], [name="_wpcf7_turnstile_response"]';
	var global = null;

	function inWorker(job) {
		return new Promise(function (resolve, reject) {
			var worker;
			try {
				worker = new Worker(source);
			} catch (error) {
				reject(error);
				return;
			}
			worker.onmessage = function (event) {
				worker.terminate();
				resolve(event.data.number);
			};
			worker.onerror = function (error) {
				worker.terminate();
				if (error && error.preventDefault) {
					error.preventDefault();
				}
				reject(error);
			};
			worker.postMessage(job);
		});
	}

	function onPage(job) {
		return new Promise(function (resolve) {
			var from = 0;
			var step = 4000;
			(function next() {
				var to = Math.min(from + step - 1, job.max);
				var found = search(job.salt, job.challenge, from, to);
				if (found >= 0 || to >= job.max) {
					resolve(found);
					return;
				}
				from = to + 1;
				setTimeout(next, 0);
			})();
		});
	}

	function solve(challenge) {
		var job = { salt: challenge.salt, challenge: challenge.challenge, max: challenge.maxnumber };
		var run = source && typeof Worker === 'function' ? inWorker(job).catch(function () {
			return onPage(job);
		}) : onPage(job);
		return run.then(function (number) {
			if (number < 0) {
				throw new Error('unsolved');
			}
			var payload = JSON.stringify({
				algorithm: challenge.algorithm,
				challenge: challenge.challenge,
				number: number,
				salt: challenge.salt,
				signature: challenge.signature
			});
			return { token: btoa(payload), expires: challenge.expires * 1000 };
		});
	}

	function fetchChallenge() {
		return fetch(config.url, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } }).then(function (response) {
			if (!response.ok) {
				throw new Error('http ' + response.status);
			}
			return response.json();
		}).then(function (body) {
			return body && body.data ? body.data : body;
		});
	}

	function fresh(state) {
		return state && state.result && !state.spent && state.result.expires - Date.now() > 60000;
	}

	function obtain(state) {
		if (fresh(state)) {
			return Promise.resolve(state.result);
		}
		if (state.pending) {
			return state.pending;
		}
		state.spent = false;
		state.pending = fetchChallenge().then(solve).then(function (result) {
			state.result = result;
			state.pending = null;
			return result;
		}, function (error) {
			state.pending = null;
			throw error;
		});
		return state.pending;
	}

	function status(form, text) {
		var box = form.querySelector('[data-rafoo-captcha]');
		var note = box ? box.querySelector('.rafoo-captcha-note') : null;
		if (note) {
			note.textContent = text || '';
			note.hidden = !text;
		}
	}

	function fill(form, state) {
		return obtain(state).then(function (result) {
			state.input.value = result.token;
			status(form, '');
			return result;
		});
	}

	function track(form) {
		if (!states || !form || states.get(form)) {
			return states ? states.get(form) : null;
		}
		var input = form.querySelector('input[name="' + FIELD + '"]');
		if (!input) {
			input = document.createElement('input');
			input.type = 'hidden';
			input.name = FIELD;
			form.appendChild(input);
		}
		var state = { input: input, result: null, pending: null, spent: false };
		states.set(form, state);
		if (form.querySelector(OUTSIDE)) {
			['g-recaptcha-response', 'h-captcha-response', 'cf-turnstile-response', '_wpcf7_recaptcha_response', '_wpcf7_turnstile_response'].forEach(function (name) {
				var field = form.querySelector('[name="' + name + '"]');
				if (field && !field.value) {
					field.value = 'rafoo';
				}
			});
		}
		return state;
	}

	function protectedForm(form) {
		return form && form.tagName === 'FORM' && (form.querySelector('[data-rafoo-captcha]') || (config.outside && form.querySelector(OUTSIDE)));
	}

	document.addEventListener('focusin', function (event) {
		var form = event.target && event.target.closest ? event.target.closest('form') : null;
		if (protectedForm(form)) {
			fill(form, track(form)).catch(function () {});
		}
	}, true);

	document.addEventListener('submit', function (event) {
		var form = event.target;
		if (!protectedForm(form)) {
			return;
		}
		var state = track(form);
		if (!state) {
			return;
		}
		if (fresh(state) && state.input.value === state.result.token) {
			state.spent = true;
			setTimeout(function () {
				if (document.body.contains(form)) {
					fill(form, state).catch(function () {});
				}
			}, 1500);
			return;
		}
		event.preventDefault();
		event.stopImmediatePropagation();
		var submitter = event.submitter && event.submitter.form === form ? event.submitter : null;
		status(form, config.working || '');
		fill(form, state).then(function () {
			if (typeof form.requestSubmit === 'function') {
				form.requestSubmit(submitter || undefined);
			} else {
				form.submit();
			}
		}, function () {
			status(form, config.failed || '');
		});
	}, true);

	function globalToken() {
		if (!global) {
			global = { input: { value: '' }, result: null, pending: null, spent: false };
		}
		return obtain(global).then(function (result) {
			global.spent = true;
			return result.token;
		});
	}

	if (config.store && scope.wp && scope.wp.apiFetch && typeof scope.wp.apiFetch.use === 'function') {
		scope.wp.apiFetch.use(function (options, next) {
			var target = String(options.path || options.url || '');
			var method = String(options.method || 'GET').toUpperCase();
			if (method !== 'POST' || !/\/wc\/store(\/v\d+)?\/checkout(\?|$)/.test(target)) {
				return next(options);
			}
			return globalToken().then(function (token) {
				var headers = Object.assign({}, options.headers || {});
				headers['X-Rafoo-Captcha'] = token;
				return next(Object.assign({}, options, { headers: headers }));
			}, function () {
				return next(options);
			});
		});
		document.addEventListener('focusin', function () {
			if (!global || (!global.result && !global.pending)) {
				globalToken().then(function () {
					global.spent = false;
				}).catch(function () {});
			}
		}, { capture: true, once: true });
	}
})(self);
