(function (w) {
	var c = w.rafooGuard;
	var nativeFetch = w.fetch;
	if (!c || c.on || !nativeFetch) {
		return;
	}
	c.on = 1;
	c.blocked = [];
	var loc = w.location;
	var internal = /\/(wp-admin\/admin-ajax\.php|wp-json\/)|[?&]rest_route=/;
	var flight = {};
	w.fetch = function (input, init) {
		var method = String((init && init.method) || 'GET').toUpperCase();
		if (typeof input === 'string' && (method === 'GET' || method === 'HEAD') && !(init && (init.signal || init.body || init.cache === 'no-store'))) {
			var u = null;
			try {
				u = new URL(input, loc.href);
			} catch (e) {}
			if (u && u.origin === loc.origin && internal.test(u.pathname + u.search)) {
				var key = method + ' ' + u.href + ' ' + (init && init.credentials ? init.credentials : '');
				if (!flight[key]) {
					flight[key] = nativeFetch.apply(this, arguments);
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
		return nativeFetch.apply(this, arguments);
	};
})(window);
