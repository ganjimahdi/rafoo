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

	function note(host) {
		if (!seen[host]) {
			seen[host] = 1;
			c.blocked.push(host);
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
				return note(host);
			}
		}
		if (!c.o || host === 'ir' || /\.ir$/.test(host) || listed(host, allow)) {
			return 0;
		}
		return listed(host, foreign) ? note(host) : 2;
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
