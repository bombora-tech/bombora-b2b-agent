/* Bombora B2B – registrační formulář (bez závislostí). */
(function () {
	'use strict';

	var cfg = window.zgB2B || { ajaxUrl: '', allowed: ['CZ'], i18n: {} };
	var t = function (k) { return (cfg.i18n && cfg.i18n[k]) || k; };

	var ICON_CHECK = '<svg class="zg-b2b-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>';
	var ICON_ALERT = '<svg class="zg-b2b-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16v.3"/></svg>';

	function post(data) {
		var body = new FormData();
		Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
		return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.json(); });
	}

	function init(root) {
		var form = root.querySelector('[data-zg-form]');
		if (!form) { return; }

		var $ = function (name) { return form.querySelector('[name="' + name + '"]'); };
		var fieldWrap = function (name) { return form.querySelector('[data-field="' + name + '"]'); };
		var statusBox = form.querySelector('[data-zg-status]');
		var country = $('company_country');
		var ico = $('ico');
		var dic = $('dic');
		var same = form.querySelector('[data-zg-same]');
		var shipping = form.querySelector('[data-zg-shipping]');
		var shipCountry = $('ship_country');
		var czWarning = form.querySelector('[data-zg-cz-warning]');
		var formError = form.querySelector('[data-zg-form-error]');
		var submit = form.querySelector('[data-zg-submit]');
		var lastAres = '';

		function setStatus(type, text) {
			if (!statusBox) { return; }
			statusBox.innerHTML = '';
			if (!type) { return; }
			var chip = document.createElement('span');
			chip.className = 'zg-chip zg-chip--' + type;
			chip.innerHTML = type === 'ok' ? ICON_CHECK : (type === 'err' ? ICON_ALERT : '');
			var span = document.createElement('span');
			span.textContent = text;
			chip.appendChild(span);
			statusBox.appendChild(chip);
		}

		function setError(name, msg) {
			var w = fieldWrap(name);
			if (!w) { return; }
			var p = w.querySelector('.zg-error');
			w.classList.toggle('has-error', !!msg);
			if (p) { p.textContent = msg || ''; }
			var input = w.querySelector('.zg-input, input');
			if (input) {
				if (msg) { input.setAttribute('aria-invalid', 'true'); input.setAttribute('aria-describedby', p ? p.id : ''); }
				else { input.removeAttribute('aria-invalid'); }
			}
		}

		function clearErrors() {
			form.querySelectorAll('.has-error').forEach(function (w) { w.classList.remove('has-error'); });
			form.querySelectorAll('.zg-error').forEach(function (p) { p.textContent = ''; });
			form.querySelectorAll('[aria-invalid]').forEach(function (i) { i.removeAttribute('aria-invalid'); });
			if (formError) { formError.hidden = true; formError.textContent = ''; }
		}

		function fill(name, value) {
			var el = $(name);
			if (!el || !value) { return; }
			el.value = value;
			el.classList.remove('is-filled');
			void el.offsetWidth; // restart animace
			el.classList.add('is-filled');
			setError(name, '');
		}

		function setRequired(name, req) {
			var el = $(name);
			var w = fieldWrap(name);
			if (!el || !w) { return; }
			el.required = req;
			var label = w.querySelector('.zg-label');
			var star = label && label.querySelector('.zg-req');
			if (req && label && !star) {
				star = document.createElement('span');
				star.className = 'zg-req';
				star.setAttribute('aria-hidden', 'true');
				star.textContent = '*';
				label.appendChild(document.createTextNode(' '));
				label.appendChild(star);
			} else if (!req && star) {
				star.remove();
			}
		}

		/* Země sídla → IČO/DIČ povinnost a tlačítka */
		function onCountry() {
			var cc = country ? country.value : 'CZ';
			var icoRequired = cc === 'CZ' || cc === 'SK';
			setRequired('ico', icoRequired);
			setRequired('dic', !icoRequired);
			var aresBtn = form.querySelector('[data-zg-lookup="ares"]');
			var viesBtn = form.querySelector('[data-zg-lookup="vies"]');
			if (aresBtn) { aresBtn.hidden = cc !== 'CZ'; }
			if (viesBtn) { viesBtn.hidden = cc === 'CZ'; }
			form.querySelectorAll('.zg-input-group').forEach(function (g) {
				var btns = g.querySelectorAll('[data-zg-lookup]');
				if (!btns.length) { return; }
				var visible = Array.prototype.some.call(btns, function (b) { return !b.hidden; });
				g.classList.toggle('zg-input-group--plain', !visible);
			});
			var icoWrap = fieldWrap('ico');
			var hint = icoWrap && icoWrap.querySelector('.zg-hint');
			if (hint) { hint.hidden = cc !== 'CZ'; }
			if (!same || same.checked) { if (shipCountry) { shipCountry.value = cc; } }
			setStatus('', '');
			updateCzWarning();
		}

		/* Upozornění: lihoviny a pivo jen do ČR */
		function updateCzWarning() {
			if (!czWarning) { return; }
			var target = (same && same.checked) ? (country ? country.value : 'CZ') : (shipCountry ? shipCountry.value : 'CZ');
			czWarning.hidden = cfg.allowed.indexOf(target) !== -1;
		}

		function onSame() {
			var on = same.checked;
			shipping.hidden = on;
			shipping.querySelectorAll('[data-zg-ship-req]').forEach(function (el) {
				setRequired(el.name, !on);
			});
			if (on && shipCountry && country) { shipCountry.value = country.value; }
			updateCzWarning();
		}

		/* ARES */
		function lookupAres(force) {
			var val = (ico.value || '').replace(/\D/g, '');
			if (val.length && val.length < 8) { val = ('00000000' + val).slice(-8); }
			if (val.length !== 8) { if (force) { setStatus('err', t('icoInvalid')); } return; }
			if (!force && val === lastAres) { return; }
			lastAres = val;
			var btn = form.querySelector('[data-zg-lookup="ares"]');
			if (btn) { btn.classList.add('is-loading'); }
			setStatus('load', t('loading'));
			post({ action: 'zg_b2b_lookup', type: 'ares', ico: val }).then(function (r) {
				if (r && r.success) {
					var d = r.data;
					ico.value = val;
					fill('company', d.company);
					fill('street', d.street);
					fill('city', d.city);
					fill('postcode', d.postcode);
					fill('dic', d.dic);
					setError('ico', '');
					setStatus('ok', t('aresOk') + ': ' + d.company);
				} else {
					setStatus('err', (r && r.data && r.data.message) || t('error'));
				}
			}).catch(function () {
				setStatus('err', t('error'));
			}).then(function () {
				if (btn) { btn.classList.remove('is-loading'); }
			});
		}

		/* VIES */
		function lookupVies() {
			var val = (dic.value || '').trim();
			if (!val) { return; }
			var btn = form.querySelector('[data-zg-lookup="vies"]');
			if (btn) { btn.classList.add('is-loading'); }
			setStatus('load', t('loading'));
			post({ action: 'zg_b2b_lookup', type: 'vies', vat: val, country: country ? country.value : '' }).then(function (r) {
				if (r && r.success) {
					var d = r.data;
					dic.value = d.dic;
					if (!$('company').value) { fill('company', d.company); }
					if (!$('street').value) { fill('street', d.street); }
					if (!$('city').value) { fill('city', d.city); }
					if (!$('postcode').value) { fill('postcode', d.postcode); }
					setStatus('ok', t('viesOk') + (d.company ? ': ' + d.company : ''));
				} else {
					setStatus('err', (r && r.data && r.data.message) || t('error'));
				}
			}).catch(function () {
				setStatus('err', t('error'));
			}).then(function () {
				if (btn) { btn.classList.remove('is-loading'); }
			});
		}

		form.querySelectorAll('[data-zg-lookup]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				if (btn.getAttribute('data-zg-lookup') === 'ares') { lookupAres(true); } else { lookupVies(); }
			});
		});
		if (ico) {
			ico.addEventListener('blur', function () { if (country && country.value === 'CZ') { lookupAres(false); } });
			ico.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); if (country.value === 'CZ') { lookupAres(true); } } });
		}
		if (dic) {
			dic.addEventListener('blur', function () { if (country && country.value !== 'CZ' && dic.value.trim()) { lookupVies(); } });
		}
		if (country) { country.addEventListener('change', onCountry); }
		if (same) { same.addEventListener('change', onSame); }
		if (shipCountry) { shipCountry.addEventListener('change', updateCzWarning); }

		/* Zobrazit heslo */
		var pwBtn = form.querySelector('[data-zg-toggle-pw]');
		if (pwBtn) {
			pwBtn.addEventListener('click', function () {
				var pw = $('password');
				var show = pw.type === 'password';
				pw.type = show ? 'text' : 'password';
				pwBtn.classList.toggle('is-on', show);
				pwBtn.setAttribute('aria-label', show ? t('hidePassword') : t('showPassword'));
			});
		}

		/* Odstranit chybu při psaní */
		form.addEventListener('input', function (e) {
			var w = e.target.closest('[data-field]');
			if (w && w.classList.contains('has-error')) { setError(w.getAttribute('data-field'), ''); }
		});
		form.addEventListener('change', function (e) {
			var w = e.target.closest('[data-field]');
			if (w && w.classList.contains('has-error')) { setError(w.getAttribute('data-field'), ''); }
		});

		/* Odeslání */
		form.addEventListener('submit', function (e) {
			if (!window.fetch || !cfg.ajaxUrl) { return; } // fallback: klasický POST
			e.preventDefault();
			clearErrors();

			// Rychlá kontrola povinných polí v prohlížeči.
			var firstBad = null;
			form.querySelectorAll('[required]').forEach(function (el) {
				if (el.closest('[hidden]')) { return; }
				var empty = el.type === 'checkbox' ? !el.checked : !String(el.value || '').trim();
				if (empty || (el.validity && !el.validity.valid)) {
					var w = el.closest('[data-field]');
					if (w) {
						var lbl = w.querySelector('.zg-label');
						setError(w.getAttribute('data-field'), el.type === 'checkbox' ? (w.querySelector('.zg-error').textContent || t('fixErrors')) : (el.validationMessage || t('fixErrors')));
						if (!lbl && el.type === 'checkbox') { w.classList.add('has-error'); }
					}
					firstBad = firstBad || el;
				}
			});
			if (firstBad) {
				if (formError) { formError.textContent = t('fixErrors'); formError.hidden = false; }
				firstBad.focus();
				return;
			}

			var label = submit.querySelector('span');
			var original = label.textContent;
			submit.classList.add('is-loading');
			label.textContent = t('sending');

			var data = new FormData(form);
			data.append('action', 'zg_b2b_register');
			fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
				.then(function (r) { return r.json(); })
				.then(function (r) {
					if (r && r.success) {
						root.outerHTML = r.data.html;
						window.scrollTo({ top: Math.max(0, (document.querySelector('.zg-b2b-reg--done') || document.body).getBoundingClientRect().top + window.pageYOffset - 120), behavior: 'smooth' });
						return;
					}
					var errs = (r && r.data && r.data.errors) || {};
					var first = null;
					Object.keys(errs).forEach(function (k) {
						if (k === '_form') {
							if (formError) { formError.textContent = errs[k]; formError.hidden = false; }
							return;
						}
						setError(k, errs[k]);
						first = first || $(k);
					});
					if (!errs._form && formError) { formError.textContent = t('fixErrors'); formError.hidden = false; }
					(first || formError).scrollIntoView({ behavior: 'smooth', block: 'center' });
					if (first) { first.focus({ preventScroll: true }); }
				})
				.catch(function () {
					if (formError) { formError.textContent = t('error'); formError.hidden = false; }
				})
				.then(function () {
					submit.classList.remove('is-loading');
					label.textContent = original;
				});
		});

		onCountry();
		if (same) { onSame(); }
	}

	function boot() {
		document.querySelectorAll('[data-zg-reg]').forEach(init);
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
