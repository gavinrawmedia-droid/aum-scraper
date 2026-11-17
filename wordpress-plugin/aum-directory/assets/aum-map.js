/*
 * AUM Directory: dealer map. Leaflet (bundled) with OpenStreetMap tiles, a pin per dealer,
 * and an optional "nearest to my postcode" search using postcodes.io.
 */
(function () {
	'use strict';
	if (typeof L === 'undefined') { return; }

	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}
	function miles(a, b) {
		var R = 3958.8, toRad = Math.PI / 180;
		var dLat = (b.lat - a.lat) * toRad, dLng = (b.lng - a.lng) * toRad;
		var h = Math.sin(dLat / 2) * Math.sin(dLat / 2) + Math.cos(a.lat * toRad) * Math.cos(b.lat * toRad) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
		return 2 * R * Math.asin(Math.sqrt(h));
	}
	function pin(cls) {
		return L.divIcon({ className: '', html: '<span class="aumd-pin ' + (cls || '') + '"></span>', iconSize: [28, 28], iconAnchor: [14, 28], popupAnchor: [0, -26] });
	}

	document.querySelectorAll('[data-aum-map]').forEach(function (block) {
		var dataEl = block.querySelector('.aumd-map-data');
		var mapEl = block.querySelector('.aumd-map');
		if (!dataEl || !mapEl) { return; }
		var dealers = JSON.parse(dataEl.textContent || '[]').filter(function (d) { return d.lat && d.lng; });
		if (!dealers.length) { mapEl.hidden = true; return; }

		var map = L.map(mapEl, { scrollWheelZoom: false, zoomControl: true });
		L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
			maxZoom: 18,
			attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
		}).addTo(map);

		var markers = {};
		var bounds = [];
		dealers.forEach(function (d) {
			var html = '<div class="aumd-popup"><strong>' + esc(d.name) + '</strong>' + esc(d.address) +
				'<br><a href="' + esc(d.stockUrl) + '">See ' + esc(d.count) + ' listing' + (d.count === 1 ? '' : 's') + '</a>' +
				' · <a href="' + esc(d.url) + '">Dealer details</a>' +
				'<br><a href="' + esc(d.directions) + '" target="_blank" rel="noopener">Directions</a></div>';
			var m = L.marker([d.lat, d.lng], { icon: pin(), title: d.name, alt: d.name, keyboard: true }).addTo(map).bindPopup(html);
			m.on('click', function () { highlight(d.id); });
			markers[d.id] = m;
			bounds.push([d.lat, d.lng]);
		});

		function fitAll() {
			if (bounds.length === 1) { map.setView(bounds[0], 13); } else { map.fitBounds(bounds, { padding: [36, 36] }); }
		}
		fitAll();

		var list = block.querySelector('.aumd-dealer-list');
		function highlight(id) {
			if (!list) { return; }
			list.querySelectorAll('.aumd-dealer-item').forEach(function (li) {
				li.classList.toggle('is-active', String(li.getAttribute('data-id')) === String(id));
			});
		}
		block.querySelectorAll('[data-aum-show]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var id = btn.getAttribute('data-aum-show');
				var m = markers[id];
				if (!m) { return; }
				map.setView(m.getLatLng(), Math.max(map.getZoom(), 10));
				m.openPopup();
				highlight(id);
				mapEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
			});
		});

		// Nearest to a postcode.
		var form = block.querySelector('[data-aum-near]');
		if (!form) { return; }
		var msg = form.querySelector('.aumd-near-msg');
		var you = null;
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var pc = (form.postcode.value || '').trim().toUpperCase().replace(/\s+/g, ' ');
			if (!pc) { msg.textContent = 'Enter a postcode first, for example DT1 1UZ.'; form.postcode.focus(); return; }
			msg.textContent = 'Looking up ' + pc + '…';
			var full = 'https://api.postcodes.io/postcodes/' + encodeURIComponent(pc);
			var outcode = 'https://api.postcodes.io/outcodes/' + encodeURIComponent(pc.split(' ')[0]);
			fetch(full).then(function (r) { return r.ok ? r.json() : fetch(outcode).then(function (r2) { return r2.ok ? r2.json() : null; }); })
				.then(function (j) {
					var res = j && j.result;
					if (!res || res.latitude == null) {
						msg.textContent = 'We couldn’t find ' + pc + '. Check it and try again.';
						return;
					}
					var here = { lat: res.latitude, lng: res.longitude };
					dealers.forEach(function (d) { d.dist = miles(here, d); });
					var sorted = dealers.slice().sort(function (a, b) { return a.dist - b.dist; });
					if (list) {
						sorted.forEach(function (d) {
							var li = list.querySelector('.aumd-dealer-item[data-id="' + d.id + '"]');
							if (!li) { return; }
							var dist = li.querySelector('.aumd-dealer-dist');
							dist.textContent = 'About ' + Math.round(d.dist) + ' miles from ' + pc + ' as the crow flies';
							dist.hidden = false;
							list.appendChild(li);
						});
					}
					if (you) { map.removeLayer(you); }
					you = L.marker([here.lat, here.lng], { icon: pin('aumd-pin-you'), title: 'Your postcode', alt: 'Your postcode' }).addTo(map);
					var nearest = sorted[0];
					map.fitBounds([[here.lat, here.lng], [nearest.lat, nearest.lng]], { padding: [48, 48], maxZoom: 11 });
					highlight(nearest.id);
					msg.textContent = 'Nearest: ' + nearest.name + ', ' + nearest.town + ', about ' + Math.round(nearest.dist) + ' miles away in a straight line (further by road). The list is now sorted by distance.';
				})
				.catch(function () { msg.textContent = 'The postcode search isn’t working right now. Please try again later.'; });
		});
	});
})();
