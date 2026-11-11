(function () {
	var d = window.rafooShield || {};
	var bar = document.getElementById('rf-bar');
	var state = document.getElementById('rf-state');
	var K = [];
	(function () {
		var n = 2, c = 0;
		function f(x) { return ((x - Math.floor(x)) * 4294967296) | 0; }
		while (c < 64) {
			var p = true;
			for (var i = 2; i * i <= n; i++) { if (n % i === 0) { p = false; break; } }
			if (p) { K[c++] = f(Math.pow(n, 1 / 3)); }
			n++;
		}
	})();
	function rot(x, n) { return (x >>> n) | (x << (32 - n)); }
	function sha(text) {
		var s = unescape(encodeURIComponent(text)), l = s.length, w = [], i, j;
		for (i = 0; i < l; i++) { w[i >> 2] |= s.charCodeAt(i) << (24 - (i % 4) * 8); }
		w[l >> 2] |= 128 << (24 - (l % 4) * 8);
		w[(((l + 8) >> 6) + 1) * 16 - 1] = l * 8;
		var H = [1779033703, -1150833019, 1013904242, -1521486534, 1359893119, -1694144372, 528734635, 1541459225];
		for (i = 0; i < w.length; i += 16) {
			var a = H[0], b = H[1], c = H[2], e = H[3], g = H[4], h = H[5], k = H[6], m = H[7], W = [];
			for (j = 0; j < 64; j++) {
				if (j < 16) { W[j] = w[i + j] | 0; } else {
					var x = W[j - 15], y = W[j - 2];
					W[j] = (W[j - 16] + (rot(x, 7) ^ rot(x, 18) ^ (x >>> 3)) + W[j - 7] + (rot(y, 17) ^ rot(y, 19) ^ (y >>> 10))) | 0;
				}
				var t1 = (m + (rot(g, 6) ^ rot(g, 11) ^ rot(g, 25)) + ((g & h) ^ (~g & k)) + K[j] + W[j]) | 0;
				var t2 = ((rot(a, 2) ^ rot(a, 13) ^ rot(a, 22)) + ((a & b) ^ (a & c) ^ (b & c))) | 0;
				m = k; k = h; h = g; g = (e + t1) | 0; e = c; c = b; b = a; a = (t1 + t2) | 0;
			}
			H[0] = (H[0] + a) | 0; H[1] = (H[1] + b) | 0; H[2] = (H[2] + c) | 0; H[3] = (H[3] + e) | 0;
			H[4] = (H[4] + g) | 0; H[5] = (H[5] + h) | 0; H[6] = (H[6] + k) | 0; H[7] = (H[7] + m) | 0;
		}
		var out = '';
		for (i = 0; i < 8; i++) { out += ('00000000' + (H[i] >>> 0).toString(16)).slice(-8); }
		return out;
	}
	function hex(buffer) {
		var bytes = new Uint8Array(buffer), out = '';
		for (var i = 0; i < bytes.length; i++) { out += ('0' + bytes[i].toString(16)).slice(-2); }
		return out;
	}
	function show(n) {
		if (bar) { bar.style.width = Math.min(100, Math.round((n / d.max) * 100)) + '%'; }
	}
	function done(n) {
		var field = document.getElementById('rf-pow');
		var form = document.getElementById('rf-form');
		if (!field || !form) { return; }
		show(d.max);
		field.value = JSON.stringify({ salt: d.salt, challenge: d.challenge, sig: d.sig, number: n });
		form.submit();
	}
	function fail() {
		if (state) { state.textContent = d.failed || ''; }
	}
	var subtle = window.crypto && window.crypto.subtle && window.TextEncoder ? window.crypto.subtle : null;
	if (subtle) {
		var enc = new TextEncoder();
		var n = 0;
		var step = function () {
			var batch = [];
			var start = n;
			for (var i = 0; i < 500 && n <= d.max; i++, n++) {
				batch.push(subtle.digest('SHA-256', enc.encode(d.salt + n)));
			}
			Promise.all(batch).then(function (list) {
				for (var j = 0; j < list.length; j++) {
					if (hex(list[j]) === d.challenge) { done(start + j); return; }
				}
				show(n);
				if (n > d.max) { fail(); return; }
				setTimeout(step, 0);
			}, fail);
		};
		step();
		return;
	}
	var c = 0;
	var slow = function () {
		for (var i = 0; i < 2000 && c <= d.max; i++, c++) {
			if (sha(d.salt + c) === d.challenge) { done(c); return; }
		}
		show(c);
		if (c > d.max) { fail(); return; }
		setTimeout(slow, 0);
	};
	slow();
})();
