/* Bombora B2B – volba země doručení při přidání lihovin do košíku. */
(function () {
	'use strict';
	var C = window.zgB2BCountry;
	if (!C) { return; }
	var ids = (C.ids || []).map(String);
	var t = function (k) { return (C.i18n && C.i18n[k]) || k; };

	function allowedNow() {
		return !C.country || C.allowed.indexOf(C.country) !== -1;
	}

	/** Zjistí ID produktu z formuláře / tlačítka přidání do košíku. */
	function productIdFrom(el) {
		if (!el) { return ''; }
		if (el.dataset && el.dataset.pid) { return el.dataset.pid; }                 // design „varianta C“
		if (el.dataset && el.dataset.product_id) { return el.dataset.product_id; }   // WooCommerce AJAX tlačítko
		var f = el.querySelector && el.querySelector('[name="add-to-cart"]');         // klasický formulář
		if (f) { return f.value; }
		var v = el.querySelector && el.querySelector('[name="variation_id"]');
		return v ? v.value : '';
	}

	function needsPrompt(el) {
		var id = productIdFrom(el);
		return id && ids.indexOf(String(id)) !== -1 && !allowedNow();
	}

	var modal, resume;

	function hasSelectWoo() {
		return !!(window.jQuery && jQuery.fn && jQuery.fn.selectWoo);
	}

	function setValue(sel, v) {
		sel.value = v || '';
		if (hasSelectWoo()) { jQuery(sel).trigger('change.select2'); }
	}

	function build() {
		modal = document.createElement('div');
		modal.className = 'zg-b2b-modal';
		modal.setAttribute('hidden', '');
		var opts = C.countries.map(function (c) {
			return '<option value="' + c[0] + '"' + (c[0] === C.country ? ' selected' : '') + '>' + c[1].replace(/</g, '&lt;') + '</option>';
		}).join('');
		modal.innerHTML =
			'<div class="zg-b2b-modal__backdrop" data-zg-close></div>' +
			'<div class="zg-b2b-modal__box" role="dialog" aria-modal="true" aria-labelledby="zg-b2b-modal-title">' +
			'<button type="button" class="zg-b2b-modal__x" data-zg-close aria-label="' + t('close') + '">×</button>' +
			'<h2 id="zg-b2b-modal-title" class="zg-b2b-modal__title"></h2>' +
			'<p class="zg-b2b-modal__intro"></p>' +
			'<label class="zg-b2b-modal__label" for="zg-b2b-modal-country"></label>' +
			'<div class="zg-b2b-modal__field"><select id="zg-b2b-modal-country" class="zg-b2b-modal__select">' + opts + '</select></div>' +
			'<p class="zg-b2b-modal__hint"></p>' +
			'<p class="zg-b2b-modal__denied" role="alert" hidden></p>' +
			'<div class="zg-b2b-modal__actions">' +
			'<button type="button" class="zg-b2b-btn zg-b2b-btn--outline zg-b2b-btn--sm" data-zg-close></button>' +
			'<button type="button" class="zg-b2b-btn zg-b2b-btn--primary zg-b2b-btn--sm" data-zg-go></button>' +
			'</div></div>';
		modal.querySelector('.zg-b2b-modal__title').textContent = t('title');
		modal.querySelector('.zg-b2b-modal__intro').textContent = t('intro');
		modal.querySelector('.zg-b2b-modal__label').textContent = t('label');
		modal.querySelector('.zg-b2b-modal__hint').textContent = t('hint');
		modal.querySelector('[data-zg-close].zg-b2b-btn').textContent = t('cancel');
		modal.querySelector('[data-zg-go]').textContent = t('continue');
		document.body.appendChild(modal);

		modal.addEventListener('click', function (e) {
			if (e.target.closest('[data-zg-close]')) { close(); }
			if (e.target.closest('[data-zg-go]')) { go(); }
		});
		var onChange = function () {
			modal.querySelector('.zg-b2b-modal__denied').hidden = true;
			modal.querySelector('[data-zg-go]').hidden = false;
		};
		var sel = modal.querySelector('select');
		if (hasSelectWoo()) {
			jQuery(sel).selectWoo({ dropdownParent: jQuery(modal.querySelector('.zg-b2b-modal__field')), width: '100%' }).on('change', onChange);
		} else {
			sel.addEventListener('change', onChange);
		}
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && modal && !modal.hidden) { close(); }
		});
	}

	function open(fn) {
		if (!modal) { build(); }
		resume = fn;
		var sel = modal.querySelector('select');
		setValue(sel, C.country);
		modal.querySelector('.zg-b2b-modal__denied').hidden = true;
		modal.querySelector('[data-zg-go]').hidden = false;
		modal.hidden = false;
		document.documentElement.classList.add('zg-b2b-modal-open');
		if (hasSelectWoo()) { jQuery(sel).selectWoo('focus'); } else { sel.focus(); }
	}

	function close() {
		if (hasSelectWoo()) { jQuery(modal.querySelector('select')).selectWoo('close'); }
		modal.hidden = true;
		resume = null;
		document.documentElement.classList.remove('zg-b2b-modal-open');
	}

	function deny() {
		var p = modal.querySelector('.zg-b2b-modal__denied');
		p.textContent = t('denied');
		p.hidden = false;
		modal.querySelector('[data-zg-go]').hidden = true;
	}

	function go() {
		var country = modal.querySelector('select').value;
		if (C.allowed.indexOf(country) === -1) { deny(); return; }
		var btn = modal.querySelector('[data-zg-go]');
		btn.classList.add('is-loading');
		var fd = new FormData();
		fd.append('action', 'zg_b2b_set_country');
		fd.append('nonce', C.nonce);
		fd.append('country', country);
		fetch(C.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
			.then(function (r) { return r.json(); })
			.then(function (r) {
				btn.classList.remove('is-loading');
				if (r && r.success) {
					C.country = r.data.country;
					var fn = resume;
					close();
					if (window.jQuery) { jQuery(document.body).trigger('wc_fragment_refresh').trigger('update_checkout'); }
					if (fn) { fn(); }
				} else if (r && r.data && r.data.denied) {
					deny();
				} else {
					alertMsg();
				}
			})
			.catch(function () { btn.classList.remove('is-loading'); alertMsg(); });
	}

	function alertMsg() {
		var p = modal.querySelector('.zg-b2b-modal__denied');
		p.textContent = t('error');
		p.hidden = false;
	}

	/* ---- Okno: lihoviny odebrané z košíku po volbě nepovolené země ---- */
	var info;
	function showRemoved(items, country) {
		if (!info) {
			info = document.createElement('div');
			info.className = 'zg-b2b-modal';
			info.setAttribute('hidden', '');
			info.innerHTML =
				'<div class="zg-b2b-modal__backdrop" data-zg-ok></div>' +
				'<div class="zg-b2b-modal__box" role="alertdialog" aria-modal="true" aria-labelledby="zg-b2b-rem-title">' +
				'<button type="button" class="zg-b2b-modal__x" data-zg-ok aria-label="' + t('close') + '">×</button>' +
				'<h2 id="zg-b2b-rem-title" class="zg-b2b-modal__title"></h2>' +
				'<p class="zg-b2b-modal__intro"></p><ul class="zg-b2b-modal__list"></ul>' +
				'<p class="zg-b2b-modal__hint"></p>' +
				'<div class="zg-b2b-modal__actions"><button type="button" class="zg-b2b-btn zg-b2b-btn--primary zg-b2b-btn--sm" data-zg-ok></button></div>' +
				'</div>';
			document.body.appendChild(info);
			info.querySelector('.zg-b2b-modal__title').textContent = t('remTitle');
			info.querySelector('.zg-b2b-modal__hint').textContent = t('remHint');
			info.querySelector('.zg-b2b-btn').textContent = t('ok');
			info.addEventListener('click', function (e) {
				if (e.target.closest('[data-zg-ok]')) {
					info.hidden = true;
					document.documentElement.classList.remove('zg-b2b-modal-open');
				}
			});
		}
		info.querySelector('.zg-b2b-modal__intro').textContent = t('remIntro').replace('%s', country || '');
		var ul = info.querySelector('.zg-b2b-modal__list');
		ul.innerHTML = '';
		items.forEach(function (n) { var li = document.createElement('li'); li.textContent = n; ul.appendChild(li); });
		info.hidden = false;
		document.documentElement.classList.add('zg-b2b-modal-open');
		info.querySelector('.zg-b2b-btn').focus();
	}

	function checkRemoved() {
		var slot = document.querySelector('.zg-b2b-removed-slot[data-items]');
		if (!slot) { return; }
		var items = [];
		try { items = JSON.parse(slot.getAttribute('data-items')) || []; } catch (e) { items = []; }
		var country = slot.getAttribute('data-country') || '';
		slot.removeAttribute('data-items');
		if (items.length) {
			showRemoved(items, country);
		}
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', checkRemoved); } else { checkRemoved(); }
	if (window.jQuery) {
		jQuery(document.body).on('updated_checkout updated_cart_totals updated_wc_div wc_fragments_refreshed', function () { setTimeout(checkRemoved, 30); });
	}

	// Formuláře (design „varianta C“ i klasický formulář WooCommerce) – zachytit ještě před jejich vlastním JS.
	window.addEventListener('submit', function (e) {
		var f = e.target;
		if (!f || !f.matches || !(f.matches('form.zgb-add') || f.matches('form.cart'))) { return; }
		if (f.dataset.zgOk === '1') { delete f.dataset.zgOk; return; }
		if (!needsPrompt(f)) { return; }
		e.preventDefault();
		e.stopImmediatePropagation();
		var submitter = e.submitter || null;
		open(function () {
			f.dataset.zgOk = '1';
			if (f.requestSubmit) { f.requestSubmit(submitter || undefined); } else { f.submit(); }
		});
	}, true);

	// AJAX tlačítka WooCommerce ve výpisech.
	window.addEventListener('click', function (e) {
		var b = e.target.closest && e.target.closest('.add_to_cart_button[data-product_id]');
		if (!b) { return; }
		if (b.dataset.zgOk === '1') { delete b.dataset.zgOk; return; }
		if (!needsPrompt(b)) { return; }
		e.preventDefault();
		e.stopImmediatePropagation();
		open(function () { b.dataset.zgOk = '1'; b.click(); });
	}, true);
})();
