(function (w, d) {
	var c = w.rafooGuard;
	if (!c || c.on) {
		return;
	}
	c.on = 1;
	c.blocked = [];
	var loc = w.location;
	var block = c.b || [];
	var foreign = c.f || [];
	var allow = c.a || [];
	var rules = !!(c.o || block.length);
	var seen = {};
	var drill = c.x;
	var sendBeacon = w.navigator && w.navigator.sendBeacon;

	if (drill) {
		c.urls = [];
		c.errors = [];
		c.broken = [];
		w.addEventListener(
			'error',
			function (event) {
				var target = event.target;
				if (target && target !== w && target.tagName) {
					var src = target.currentSrc || target.src || target.href;
					if (src && c.broken.length < 30) {
						c.broken.push(String(src));
					}
					return;
				}
				if (c.errors.length < 20) {
					c.errors.push({ m: String(event.message || ''), f: String(event.filename || ''), l: event.lineno || 0 });
				}
			},
			true
		);
		w.addEventListener('unhandledrejection', function (event) {
			var reason = event.reason;
			if (reason && reason.rafoo) {
				return;
			}
			if (c.errors.length < 20) {
				c.errors.push({ m: String((reason && reason.message) || reason || 'Unhandled rejection'), f: String((reason && reason.fileName) || ''), l: 0 });
			}
		});
		c.collect = function () {
			var perf = w.performance;
			var out = { u: loc.href, s: 0, l: 0, r: [], x: c.urls.slice(0, 60), e: c.errors.slice(0, 20), g: c.broken.slice(0, 30) };
			try {
				var nav = perf.getEntriesByType('navigation')[0];
				if (nav) {
					out.s = nav.responseStatus || 0;
					out.l = Math.round((nav.loadEventEnd || perf.now()) - nav.startTime);
				}
				var list = perf.getEntriesByType('resource');
				for (var i = 0; i < list.length && out.r.length < 150; i++) {
					var entry = list[i];
					var u = parse(entry.name);
					if (!u || !/^https?:$/.test(u.protocol) || u.hostname === loc.hostname) {
						continue;
					}
					out.r.push({ u: String(entry.name).slice(0, 600), b: entry.renderBlockingStatus === 'blocking' ? 1 : 0, i: entry.initiatorType, d: Math.round(entry.duration) });
				}
			} catch (e) {}
			return out;
		};
		if (drill.b && sendBeacon) {
			var posted = 0;
			var post = function () {
				if (posted) {
					return;
				}
				posted = 1;
				try {
					sendBeacon.call(w.navigator, drill.b, new Blob([JSON.stringify(c.collect())], { type: 'application/json' }));
				} catch (e) {}
			};
			w.addEventListener('load', function () {
				w.setTimeout(post, 2500);
			});
			w.addEventListener('pagehide', post);
		}
		if (w.top === w.self && drill.m && !drill.n) {
			var banner = function () {
				if (!d.body || d.getElementById('rafoo-drill-banner')) {
					return;
				}
				var box = d.createElement('div');
				box.id = 'rafoo-drill-banner';
				box.setAttribute('role', 'status');
				box.setAttribute('dir', 'auto');
				box.style.cssText = 'position:fixed;inset-block-end:12px;inset-inline-start:12px;z-index:2147483647;display:flex;flex-wrap:wrap;align-items:center;gap:8px;max-inline-size:min(520px,calc(100vw - 24px));padding:8px 12px;border:1px solid CanvasText;border-radius:8px;background:Canvas;color:CanvasText;font:13px/1.6 system-ui,Tahoma,sans-serif';
				var text = d.createElement('span');
				text.textContent = drill.m;
				var link = d.createElement('a');
				link.href = drill.u;
				link.textContent = drill.e;
				link.style.cssText = 'color:inherit;font-weight:700;text-decoration:underline';
				box.appendChild(text);
				box.appendChild(link);
				d.body.appendChild(box);
			};
			if (d.readyState === 'loading') {
				d.addEventListener('DOMContentLoaded', banner);
			} else {
				banner();
			}
		}
	}

	function parse(raw) {
		try {
			return new URL(String(raw), loc.href);
		} catch (e) {
			return null;
		}
	}

	function match(host, rule) {
		if (rule.indexOf('*.') === 0) {
			var base = rule.slice(2);
			return host === base || host.slice(-base.length - 1) === '.' + base;
		}
		return host === rule;
	}

	function listed(host, list) {
		for (var i = 0; i < list.length; i++) {
			if (match(host, list[i])) {
				return true;
			}
		}
		return false;
	}

	function note(host, raw) {
		if (!seen[host]) {
			seen[host] = 1;
			c.blocked.push(host);
		}
		if (drill && c.urls.length < 60) {
			var href = String(raw).slice(0, 600);
			if (c.urls.indexOf(href) < 0) {
				c.urls.push(href);
			}
		}
		return 1;
	}

	function judge(raw) {
		var u = parse(raw);
		if (!u || !/^(https?|wss?):$/.test(u.protocol)) {
			return 0;
		}
		var host = u.hostname.toLowerCase();
		if (host === loc.hostname.toLowerCase()) {
			return 0;
		}
		var path = (host + u.pathname + u.search).toLowerCase();
		for (var i = 0; i < block.length; i++) {
			var rule = block[i];
			if (rule.indexOf('/') < 0 ? match(host, rule) : path.indexOf(rule) === 0) {
				return note(host, u.href);
			}
		}
		if (!c.o || host === 'ir' || /\.ir$/.test(host) || listed(host, allow)) {
			return 0;
		}
		return listed(host, foreign) ? note(host, u.href) : 2;
	}

	function failure() {
		var e = new TypeError('Failed to fetch');
		e.rafoo = 1;
		return e;
	}

	function later(fn) {
		w.setTimeout(fn, 0);
	}

	function fire(target, type) {
		var event;
		try {
			event = new Event(type);
		} catch (e) {
			event = d.createEvent('Event');
			event.initEvent(type, false, false);
		}
		target.dispatchEvent(event);
	}

	w.addEventListener('unhandledrejection', function (event) {
		if (event.reason && event.reason.rafoo) {
			event.preventDefault();
		}
	});

	var internal = /\/(wp-admin\/admin-ajax\.php|wp-json\/)|[?&]rest_route=/;
	var nativeFetch = w.fetch;
	if (nativeFetch) {
		var flight = {};
		w.fetch = function (input, init) {
			var url = typeof input === 'string' ? input : input && (input.url || input.href) ? input.url || input.href : String(input);
			var verdict = rules ? judge(url) : 0;
			if (verdict === 1) {
				return Promise.reject(failure());
			}
			var self = this;
			var args = arguments;
			var method = String((init && init.method) || (input && input.method) || 'GET').toUpperCase();
			if (verdict === 0 && c.d && typeof input === 'string' && (method === 'GET' || method === 'HEAD') && !(init && (init.signal || init.body || init.cache === 'no-store'))) {
				var u = parse(url);
				if (u && u.origin === loc.origin && internal.test(u.pathname + u.search)) {
					var key = method + ' ' + u.href + ' ' + (init && init.credentials ? init.credentials : '');
					if (!flight[key]) {
						flight[key] = nativeFetch.apply(self, args);
						var done = function () {
							delete flight[key];
						};
						flight[key].then(done, done);
					}
					return flight[key].then(function (response) {
						return response.clone();
					});
				}
			}
			if (verdict === 2 && c.t && w.AbortController && !(init && init.signal) && !(input && input.signal)) {
				var controller = new AbortController();
				var options = {};
				for (var k in init || {}) {
					options[k] = init[k];
				}
				options.signal = controller.signal;
				var timer = w.setTimeout(function () {
					controller.abort();
				}, c.t);
				var clear = function () {
					w.clearTimeout(timer);
				};
				var pending = nativeFetch.call(self, input, options);
				pending.then(clear, clear);
				return pending;
			}
			return nativeFetch.apply(self, args);
		};
	}

	if (!rules) {
		return;
	}

	var XHR = w.XMLHttpRequest;
	if (XHR && XHR.prototype) {
		var open = XHR.prototype.open;
		var send = XHR.prototype.send;
		XHR.prototype.open = function (method, url) {
			this.__rafoo = judge(url);
			return open.apply(this, arguments);
		};
		XHR.prototype.send = function () {
			var x = this;
			if (x.__rafoo === 1) {
				later(function () {
					try {
						Object.defineProperty(x, 'readyState', { value: 4, configurable: true });
						Object.defineProperty(x, 'status', { value: 0, configurable: true });
						Object.defineProperty(x, 'responseText', { value: '', configurable: true });
					} catch (e) {}
					fire(x, 'readystatechange');
					fire(x, 'error');
					fire(x, 'loadend');
				});
				return;
			}
			if (x.__rafoo === 2 && c.t && !x.timeout) {
				try {
					x.timeout = c.t;
				} catch (e) {}
			}
			return send.apply(this, arguments);
		};
	}

	var nav = w.navigator;
	if (nav && nav.sendBeacon) {
		var beacon = nav.sendBeacon;
		nav.sendBeacon = function (url) {
			return judge(url) === 1 ? true : beacon.apply(nav, arguments);
		};
	}

	function stand(Base, name, closed) {
		if (!Base) {
			return;
		}
		var Guarded = function (url, extra) {
			if (judge(url) !== 1) {
				return arguments.length > 1 ? new Base(url, extra) : new Base(url);
			}
			var fake = w.EventTarget ? new EventTarget() : d.createDocumentFragment();
			fake.url = String(url);
			fake.readyState = closed;
			fake.send = fake.close = function () {};
			later(function () {
				var types = name === 'WebSocket' ? ['error', 'close'] : ['error'];
				for (var i = 0; i < types.length; i++) {
					fire(fake, types[i]);
					if (typeof fake['on' + types[i]] === 'function') {
						fake['on' + types[i]]({ type: types[i], target: fake, code: 1006 });
					}
				}
			});
			return fake;
		};
		Guarded.prototype = Base.prototype;
		for (var key in Base) {
			Guarded[key] = Base[key];
		}
		Guarded.CONNECTING = 0;
		Guarded.OPEN = 1;
		Guarded.CLOSED = closed;
		w[name] = Guarded;
	}
	stand(w.WebSocket, 'WebSocket', 3);
	stand(w.EventSource, 'EventSource', 2);

	var setters = {};
	var elements = { SCRIPT: 'src', IMG: 'src', IFRAME: 'src', LINK: 'href' };

	function stop(el, value) {
		try {
			setAttribute.call(el, 'data-rafoo-blocked', String(value));
		} catch (e) {}
		if (el.tagName === 'IFRAME' && setters.IFRAME) {
			setters.IFRAME.call(el, 'about:blank');
			return;
		}
		later(function () {
			fire(el, 'error');
		});
	}

	function hook(Ctor, tag) {
		var proto = Ctor && Ctor.prototype;
		var prop = elements[tag];
		var desc = proto && Object.getOwnPropertyDescriptor(proto, prop);
		if (!desc || !desc.set) {
			return;
		}
		setters[tag] = desc.set;
		Object.defineProperty(proto, prop, {
			configurable: true,
			enumerable: desc.enumerable,
			get: desc.get,
			set: function (value) {
				if (judge(value) === 1) {
					return stop(this, value);
				}
				desc.set.call(this, value);
			}
		});
	}
	hook(w.HTMLScriptElement, 'SCRIPT');
	hook(w.HTMLImageElement, 'IMG');
	hook(w.HTMLIFrameElement, 'IFRAME');
	hook(w.HTMLLinkElement, 'LINK');

	var setAttribute = w.Element && w.Element.prototype.setAttribute;
	if (setAttribute) {
		w.Element.prototype.setAttribute = function (name, value) {
			var attr = elements[this.tagName];
			if (attr && typeof name === 'string' && name.toLowerCase() === attr && judge(value) === 1) {
				return stop(this, value);
			}
			return setAttribute.apply(this, arguments);
		};
	}

	if (c.r && nav && nav.sendBeacon) {
		var sent = 0;
		var report = function () {
			if (c.blocked.length > sent) {
				sent = c.blocked.length;
				try {
					beacon.call(nav, c.r, new Blob([JSON.stringify({ hosts: c.blocked.slice(0, 20) })], { type: 'application/json' }));
				} catch (e) {}
			}
		};
		w.addEventListener('load', function () {
			w.setTimeout(report, 3000);
		});
		w.addEventListener('pagehide', report);
	}
})(window, document);
