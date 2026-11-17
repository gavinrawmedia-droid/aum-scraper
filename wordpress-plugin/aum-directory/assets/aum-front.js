/* AUM Directory: filter panel, photo gallery and compare. No dependencies. */
(function () {
	'use strict';

	// Filters: open beside the results on wide screens; folded away on small screens unless filters are on.
	var wide = window.matchMedia('(min-width: 1024px)');
	function setFilters() {
		document.querySelectorAll('[data-aum-filters]').forEach(function (d) {
			if (wide.matches) {
				d.open = true;
			} else if (!d.dataset.aumTouched) {
				d.open = !!d.querySelector('.aumd-filters-badge');
			}
		});
	}
	document.querySelectorAll('[data-aum-filters]').forEach(function (d) {
		d.querySelector('summary').addEventListener('click', function (e) {
			if (wide.matches) { e.preventDefault(); return; }
			d.dataset.aumTouched = '1';
		});
	});
	setFilters();
	if (wide.addEventListener) { wide.addEventListener('change', setFilters); }

	// Card photos are shown from the dealer's website; if one has gone, show a plain panel instead of a broken image.
	function markBroken(img) {
		var box = img.closest('.aum-card-media, .aumd-cmp-photo');
		if (box) { box.classList.add('is-broken'); }
	}
	document.querySelectorAll('img.aum-card-img, img.aumd-cmp-img').forEach(function (img) {
		if (img.complete && img.naturalWidth === 0 && img.getAttribute('src')) { markBroken(img); }
		img.addEventListener('error', function () { markBroken(img); });
	});

	// Gallery: thumbnails swap the main photo.
	document.querySelectorAll('[data-aum-gallery]').forEach(function (g) {
		var main = g.querySelector('[data-aum-main]');
		var thumbs = g.querySelectorAll('.aumd-thumb');
		thumbs.forEach(function (t) {
			t.addEventListener('click', function () {
				main.src = t.getAttribute('data-src');
				thumbs.forEach(function (o) { o.setAttribute('aria-pressed', o === t ? 'true' : 'false'); });
			});
		});
	});

	/* ------------------------------------------------------------ compare */
	// Picks are kept in this browser only (localStorage), as [{id, t: title, p: price}].
	var cfg = window.aumCompare || { url: '/compare/', max: 3 };
	var KEY = 'aumCompare';
	var page = document.querySelector('[data-aum-compare-page]');

	function load() {
		try {
			var v = JSON.parse(window.localStorage.getItem(KEY) || '[]');
			return Array.isArray(v) ? v.filter(function (x) { return x && x.id > 0; }).slice(0, cfg.max) : [];
		} catch (e) { return []; }
	}
	function save(list) {
		try { window.localStorage.setItem(KEY, JSON.stringify(list.slice(0, cfg.max))); } catch (e) { /* private mode: picks last for this page only */ }
	}
	function compareUrl(list) {
		var ids = list.map(function (x) { return x.id; }).join(',');
		return cfg.url + (cfg.url.indexOf('?') > -1 ? '&' : '?') + 'ids=' + ids;
	}
	function has(list, id) { return list.some(function (x) { return x.id === id; }); }

	// A polite announcer for screen readers, always in the page.
	var live = document.createElement('p');
	live.className = 'aumd-sr';
	live.setAttribute('aria-live', 'polite');
	document.body.appendChild(live);
	function say(text) { live.textContent = ''; window.setTimeout(function () { live.textContent = text; }, 50); }

	// The compare page: what's on the page becomes the visitor's picks.
	if (page) {
		var params = new URLSearchParams(window.location.search);
		var onPage = [];
		try { onPage = JSON.parse(page.getAttribute('data-items') || '[]'); } catch (e) { onPage = []; }
		if (!params.has('ids') && !onPage.length && load().length) {
			window.location.replace(compareUrl(load()));
			return;
		}
		save(onPage);
		var diff = page.querySelector('[data-aum-diff]');
		var table = page.querySelector('[data-aum-cmp-table]');
		if (diff && table) {
			diff.closest('label').hidden = false;
			diff.addEventListener('change', function () {
				table.classList.toggle('is-diff', diff.checked);
				var hidden = table.querySelectorAll('tr[data-same]').length;
				say(diff.checked ? (hidden ? hidden + ' matching rows hidden.' : 'Every row is different.') : 'All rows shown.');
			});
		}
	}

	// The bar along the bottom of the screen.
	var tray = null;
	function buildTray() {
		tray = document.createElement('div');
		tray.className = 'aumd-tray';
		tray.setAttribute('role', 'region');
		tray.setAttribute('aria-label', 'Machines to compare');
		tray.hidden = true;
		tray.innerHTML = '<div class="aumd-tray-inner"><p class="aumd-tray-title"></p><ul class="aumd-tray-list"></ul>' +
			'<div class="aumd-tray-actions"><button type="button" class="aumd-linkbtn aumd-tray-clear">Clear</button><a class="aumd-btn aumd-tray-go"></a><span class="aumd-tray-hint">Pick one more to compare</span></div></div>';
		document.body.appendChild(tray);
		tray.querySelector('.aumd-tray-clear').addEventListener('click', function () {
			save([]);
			sync();
			say('Compare list cleared.');
		});
	}
	function renderTray(list) {
		if (page) { return; }
		if (!tray) { buildTray(); }
		tray.hidden = list.length === 0;
		document.body.classList.toggle('aumd-has-tray', list.length > 0);
		if (!list.length) { document.body.style.removeProperty('--aumd-tray-h'); return; }
		tray.querySelector('.aumd-tray-title').textContent = 'Compare (' + list.length + ' of ' + cfg.max + ')';
		var ul = tray.querySelector('.aumd-tray-list');
		ul.textContent = '';
		list.forEach(function (x) {
			var li = document.createElement('li');
			li.className = 'aumd-tray-chip';
			var name = document.createElement('span');
			name.className = 'aumd-tray-name';
			name.textContent = x.t || 'Listing';
			li.appendChild(name);
			if (x.p) {
				var price = document.createElement('span');
				price.className = 'aumd-tray-price';
				price.textContent = x.p;
				li.appendChild(price);
			}
			var rm = document.createElement('button');
			rm.type = 'button';
			rm.className = 'aumd-tray-x';
			rm.setAttribute('aria-label', 'Remove ' + (x.t || 'listing') + ' from compare');
			rm.textContent = '×';
			rm.addEventListener('click', function () {
				save(load().filter(function (o) { return o.id !== x.id; }));
				sync();
				say('Removed ' + (x.t || 'listing') + '.');
			});
			li.appendChild(rm);
			ul.appendChild(li);
		});
		var go = tray.querySelector('.aumd-tray-go');
		go.hidden = list.length < 2;
		tray.querySelector('.aumd-tray-hint').hidden = list.length >= 2;
		go.href = compareUrl(list);
		go.textContent = 'Compare ' + list.length + ' side by side';
		document.body.style.setProperty('--aumd-tray-h', tray.offsetHeight + 'px');
	}

	// The tick boxes on cards and listing pages.
	function boxes() { return document.querySelectorAll('input[data-aum-cmp]'); }
	function sync() {
		var list = load();
		boxes().forEach(function (b) { b.checked = has(list, parseInt(b.getAttribute('data-aum-cmp'), 10)); });
		renderTray(list);
	}
	boxes().forEach(function (b) {
		b.closest('label').hidden = false;
		b.addEventListener('change', function () {
			var id = parseInt(b.getAttribute('data-aum-cmp'), 10);
			var title = b.getAttribute('data-title') || 'Listing';
			var list = load().filter(function (x) { return x.id !== id; });
			if (b.checked) {
				if (list.length >= cfg.max) {
					b.checked = false;
					say('You can compare up to ' + cfg.max + ' machines. Remove one first.');
					if (tray) {
						tray.classList.remove('is-full');
						void tray.offsetWidth;
						tray.classList.add('is-full');
					}
					return;
				}
				list.push({ id: id, t: title, p: b.getAttribute('data-price') || '' });
				say('Added ' + title + '. ' + list.length + ' of ' + cfg.max + ' picked.');
			} else {
				say('Removed ' + title + '.');
			}
			save(list);
			sync();
		});
	});

	// Remove links on the compare page update the picks before the page reloads.
	document.querySelectorAll('[data-aum-remove]').forEach(function (a) {
		a.addEventListener('click', function () {
			var id = parseInt(a.getAttribute('data-aum-remove'), 10);
			save(load().filter(function (x) { return x.id !== id; }));
		});
	});

	sync();
	// Keep in step with other tabs, and when coming back with the browser's Back button.
	window.addEventListener('storage', function (e) { if (e.key === KEY) { sync(); } });
	window.addEventListener('pageshow', function (e) { if (e.persisted) { sync(); } });
	window.addEventListener('resize', function () {
		if (tray && !tray.hidden) { document.body.style.setProperty('--aumd-tray-h', tray.offsetHeight + 'px'); }
	});
})();
