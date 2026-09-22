/**
 * cpms-patient-portal.js — Phase 9 Slice 1: لغو نوبت توسط خود بیمار (پورتال «نوبت‌های من»).
 *
 * مرز مسئولیت (عمداً باریک):
 *  - فقط روی صفحهٔ `cpms-patient` انکیو می‌شود (PatientPortalPage::enqueue_assets).
 *  - هیچ تصمیمِ مالکیت/Clinic/مهلت سمت کلاینت نیست: دکمه فقط `data-appointment-id`
 *    را حمل می‌کند و همان مسیرِ موجود B4 (`POST clinic/v1/appointments/{id}/cancel`)
 *    با nonce `wp_rest` و cookie هم‌مبدأ فراخوانی می‌شود؛ پاسخِ سرور مرجع نهایی است.
 *  - بدون framework/build/CDN — vanilla، ES5-سازگار، یک IIFE.
 *
 * رفتار:
 *  - کلیک/کیبورد روی `<button data-role="cancel-appointment">` ⇒ (اگر `data-cpms-confirm`
 *    هنوز روی دکمه باشد، ابتدا Modal تأیید موجودِ cpms-admin.js اجرا می‌شود و بعد
 *    از تأیید همان دکمه دوباره click می‌شود) ⇒ دکمه disabled + aria-busy ⇒ POST.
 *  - موفقیت ⇒ بارگذاری مجددِ صفحه (جدول از سرور می‌آید؛ ردیف به تاریخچه می‌رود).
 *  - شکست (پاکتِ canonical `{code, message}` یا خطای شبکه) ⇒ پیام فارسیِ سرور در
 *    ناحیهٔ `role="alert"` + دکمه دوباره فعال و focus روی آن.
 *  - جلوگیری از ارسال دوباره: تا پایانِ درخواست، همان دکمه disabled است.
 *
 * ساخت URL: همان الگوی `apiUrl()` سطح عمومی رزرو — در Plain permalink ریشه شامل
 * `?rest_route=` است و مسیر بدون `?` به آن الحاق می‌شود؛ در Pretty هم همین الحاق
 * درست است. هیچ URL استقراری hardcode نمی‌شود.
 *
 * Slice 2 — اعلان‌های داخلی: کلیک روی `<button data-role="notifications-mark-all-read">`
 * ⇒ همان دکمه disabled + aria-busy ⇒ `POST clinic/v1/notifications/read` با بدنهٔ
 * دقیقاً `{"all":true}` (هیچ شناسه‌ای؛ گیرنده را سرور از کاربرِ جاری حل می‌کند) ⇒
 * موفقیت: بارگذاری مجدد (فهرست/نشان از سرور)؛ شکست: پیام فارسی در ناحیهٔ
 * `role="alert"` مخصوصِ اعلان‌ها + دکمه دوباره فعال و focus. بدون polling.
 */
(function () {
	'use strict';

	var CONFIG_SELECTOR = 'script.cpms-patient-portal__config';
	var BUTTON_SELECTOR = 'button[data-role="cancel-appointment"]';
	var ERROR_SELECTOR = '[data-role="cancel-error"]';
	var CONFIRM_ATTR = 'data-cpms-confirm';
	var MARK_ALL_SELECTOR = 'button[data-role="notifications-mark-all-read"]';
	var NOTIFICATIONS_ERROR_SELECTOR = '[data-role="notifications-error"]';

	var TEXT_BUSY = 'در حال لغو…';
	var TEXT_FAILED = 'لغو نوبت انجام نشد. لطفاً دوباره تلاش کنید یا با مطب تماس بگیرید.';
	var TEXT_MARKING = 'در حال ثبت…';
	var TEXT_MARK_ALL_FAILED = 'علامت‌گذاری اعلان‌ها انجام نشد. لطفاً دوباره تلاش کنید.';

	function isNonEmptyString(value) {
		return typeof value === 'string' && value !== '';
	}

	/** خواندنِ ایمنِ پیکربندیِ منتشرشده توسط سرور؛ در هر شکست `null` (fail-closed). */
	function readConfig() {
		var element = document.querySelector(CONFIG_SELECTOR);
		if (!element) { return null; }
		var parsed;
		try { parsed = JSON.parse(element.textContent || ''); }
		catch (e) { return null; }
		if (!parsed || typeof parsed !== 'object') { return null; }
		if (!isNonEmptyString(parsed.rest_root) || !isNonEmptyString(parsed.nonce)) { return null; }
		if (!isNonEmptyString(parsed.cancel_path) || parsed.cancel_path.indexOf('{id}') === -1) { return null; }
		return parsed;
	}

	/**
	 * ساختِ URL سازگار با هر دو حالتِ permalink (همان الگوی موجود apiUrl):
	 * اگر ریشه `?` دارد (Plain: `index.php?rest_route=/clinic/v1`) و مسیر هم query
	 * دارد، نخستین `?` مسیر به `&` تبدیل می‌شود؛ در غیر این صورت الحاقِ ساده.
	 * مسیر می‌تواند مطلقِ namespace باشد (مثلاً `/clinic/v1/patient/me`) یا نسبی
	 * (مثلاً `/patient/me`)؛ در هر دو صورت پیشوند تکراری حذف می‌شود.
	 */
	function apiUrl(restRoot, path) {
		var NS_PREFIX = '/clinic/v1';
		var p = path;
		if (p.indexOf(NS_PREFIX + '/') === 0) { p = p.substring(NS_PREFIX.length); }
		if (restRoot.indexOf('?') !== -1 && p.indexOf('?') !== -1) {
			return restRoot + p.replace('?', '&');
		}
		return restRoot + p;
	}

	function cancelPath(template, appointmentId) {
		return template.replace('{id}', String(appointmentId));
	}

	/** همهٔ شکست‌ها (شبکه، JSON نامعتبر، HTTP غیرموفق) به `{ok, status, body}` تبدیل می‌شوند. */
	function requestJson(url, options) {
		return window.fetch(url, options).then(function (response) {
			return response.json().then(
				function (body) {
					return { ok: response.ok, status: response.status, body: body };
				},
				function () {
					return { ok: false, status: response.status, body: null };
				}
			);
		});
	}

	function serverMessage(result) {
		if (result && result.body && isNonEmptyString(result.body.message)) {
			return result.body.message;
		}
		return '';
	}

	function serverCode(result) {
		if (result && result.body && isNonEmptyString(result.body.code)) {
			return result.body.code;
		}
		return result && result.status ? 'HTTP_' + result.status : 'NETWORK';
	}

	function errorBox() {
		return document.querySelector(ERROR_SELECTOR);
	}

	function showError(box, message, code) {
		if (!box) {
			return;
		}
		var target = box.querySelector('p') || box;
		target.textContent = message;
		box.setAttribute('data-error-code', code);
		box.hidden = false;
	}

	function clearError(box) {
		if (!box) {
			return;
		}
		var target = box.querySelector('p') || box;
		target.textContent = '';
		box.removeAttribute('data-error-code');
		box.hidden = true;
	}

	function setBusy(button, busy, idleLabel, busyLabel) {
		button.disabled = busy;
		button.setAttribute('aria-busy', busy ? 'true' : 'false');
		button.textContent = busy ? (busyLabel || TEXT_BUSY) : idleLabel;
	}

	function cancelAppointment(config, button) {
		var appointmentId = parseInt(button.getAttribute('data-appointment-id') || '', 10);
		if (!(appointmentId > 0) || button.disabled) {
			return;
		}
		var idleLabel = button.textContent;
		clearError(errorBox());
		setBusy(button, true, idleLabel);

		// هم‌مبدأ + cookie جلسه + nonce `wp_rest`؛ بدنه عمداً خالی است (هیچ
		// clinic_id/patient_id — سرور از روی شناسهٔ نوبت و کاربرِ جاری تصمیم می‌گیرد).
		requestJson(apiUrl(config.rest_root, cancelPath(config.cancel_path, appointmentId)), {
			method: 'POST',
			credentials: 'same-origin',
			headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
			body: '{}'
		}).then(function (result) {
			var payload = result.body && result.body.data ? result.body.data : null;
			if (result.ok && payload && isNonEmptyString(payload.status)) {
				// جدول از سرور دوباره رندر می‌شود (ردیف به تاریخچه می‌رود).
				window.location.reload();
				return;
			}
			setBusy(button, false, idleLabel);
			showError(errorBox(), serverMessage(result) || TEXT_FAILED, serverCode(result));
			button.focus();
		}, function () {
			setBusy(button, false, idleLabel);
			showError(errorBox(), TEXT_FAILED, 'NETWORK');
			button.focus();
		});
	}

	/**
	 * Slice 2 — «خواندنِ همه»: همان مسیرِ موجود R2b با بدنهٔ دقیقاً `{"all":true}`.
	 * هیچ clinic_id/patient_id/user_id ارسال نمی‌شود؛ سرور از کاربرِ جاری تصمیم می‌گیرد.
	 * موفقیت ⇒ reload (نشان/فهرست از سرور)؛ شکست ⇒ پیام + دکمه فعال و focus.
	 */
	function markAllRead(config, button) {
		if (button.disabled) {
			return;
		}
		var box = document.querySelector(NOTIFICATIONS_ERROR_SELECTOR);
		var idleLabel = button.textContent;
		clearError(box);
		setBusy(button, true, idleLabel, TEXT_MARKING);

		requestJson(apiUrl(config.rest_root, config.notifications_read_path), {
			method: 'POST',
			credentials: 'same-origin',
			headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
			body: JSON.stringify({ all: true })
		}).then(function (result) {
			var payload = result.body && result.body.data ? result.body.data : null;
			if (result.ok && payload && typeof payload.marked === 'number') {
				window.location.reload();
				return;
			}
			setBusy(button, false, idleLabel);
			showError(box, serverMessage(result) || TEXT_MARK_ALL_FAILED, serverCode(result));
			button.focus();
		}, function () {
			setBusy(button, false, idleLabel);
			showError(box, TEXT_MARK_ALL_FAILED, 'NETWORK');
			button.focus();
		});
	}

	/** دکمه فقط وقتی سرور آن را رندر کرده (خوانده‌نشده > ۰) و مسیر منتشر شده وجود دارد. */
	function bindMarkAllRead(config) {
		var button = document.querySelector(MARK_ALL_SELECTOR);
		if (!button || !isNonEmptyString(config.notifications_read_path)) {
			return;
		}
		button.addEventListener('click', function () {
			markAllRead(config, button);
		});
	}

	// ================ Slice 4: Patient Profile ================

	var PROFILE_SECTION = '[data-role="profile-section"]';
	var PROFILE_EMPTY = '[data-role="profile-empty-state"]';
	var PROFILE_CONTEXT = '[data-role="profile-context"]';
	var PROFILE_CLINIC_LABEL = '[data-role="profile-clinic-label"]';
	var PROFILE_MRN = '[data-role="profile-mrn"]';
	var PROFILE_SELECT = '[data-role="profile-record-select"]';
	var PROFILE_FORM_WRAP = '[data-role="profile-form-wrap"]';
	var PROFILE_FORM = '[data-role="profile-form"]';
	var PROFILE_LINK_ID = '[data-role="profile-link-id"]';
	var PROFILE_SUCCESS = '[data-role="profile-success"]';
	var PROFILE_ERROR = '[data-role="profile-error"]';
	var PROFILE_SAVE = '[data-role="profile-save"]';
	var PROFILE_MOBILE_ROW = '[data-role="profile-mobile-row"]';
	var PROFILE_MOBILE = '[data-role="profile-login-mobile"]';

	var FIELD_ATTR = 'data-field';
	// ONLY ME_EDITABLE. mobile/role/clinic_id/patient_id/org_id intentionally absent.
	var PROFILE_FIELD_KEYS = [
		'first_name','last_name','national_id','birth_date','gender','address','phone',
		'emergency_contact_name','emergency_contact_phone'
	];

	var TEXT_PROFILE_SAVE = 'در حال ذخیره…';
	var TEXT_PROFILE_SAVED = 'اطلاعات با موفقیت ذخیره شد.';
	var TEXT_PROFILE_SAVE_FAILED = 'ذخیرهٔ اطلاعات انجام نشد. لطفاً دوباره تلاش کنید.';
	var TEXT_PROFILE_LOADING = 'در حال بارگذاری…';
	var TEXT_PROFILE_LOAD_FAILED = 'بارگذاری پرونده انجام نشد. لطفاً دوباره تلاش کنید.';

	function el(scope, selector) {
		return scope ? scope.querySelector(selector) : null;
	}
	function els(scope, selector) {
		return scope ? scope.querySelectorAll(selector) : [];
	}

	function clearNotices(section) {
		var s = el(section, PROFILE_SUCCESS); if (s) { s.hidden = true; s.textContent = ''; }
		var e = el(section, PROFILE_ERROR); if (e) { e.hidden = true; e.textContent = ''; }
	}
	function setNotice(section, kind, message) {
		clearNotices(section);
		var box = el(section, kind === 'error' ? PROFILE_ERROR : PROFILE_SUCCESS);
		if (!box) { return; }
		box.hidden = false;
		box.textContent = message;
	}

	function fieldInputs(section) {
		return section.querySelectorAll('[' + FIELD_ATTR + ']');
	}
	function findField(section, key) {
		return el(section, '[' + FIELD_ATTR + '="' + key + '"]');
	}

	function clearFormInputs(section) {
		var inputs = fieldInputs(section);
		for (var i = 0; i < inputs.length; i++) { inputs[i].value = ''; }
	}

	function populateForm(section, me, record) {
		var ctx = el(section, PROFILE_CONTEXT);
		if (ctx) {
			var clinicEl = el(ctx, PROFILE_CLINIC_LABEL);
			var mrnEl = el(ctx, PROFILE_MRN);
			if (clinicEl) { clinicEl.textContent = (record && record.clinic_name) || ''; }
			if (mrnEl) { mrnEl.textContent = record && record.mrn ? ('MRN: ' + record.mrn) : ''; }
			ctx.hidden = !(record && (record.clinic_name || record.mrn));
		}
		var hid = el(section, PROFILE_LINK_ID);
		if (hid && record) { hid.value = String(record.link_id || ''); }
		// Mobile read-only inside form
		var mrow = el(section, PROFILE_MOBILE_ROW);
		var mval = el(section, PROFILE_MOBILE);
		if (mval && me && me.mobile) {
			mval.textContent = String(me.mobile);
			if (mrow) { mrow.hidden = false; }
		} else if (mrow) {
			if (mval) { mval.textContent = ''; }
			mrow.hidden = true;
		}
		for (var i = 0; i < PROFILE_FIELD_KEYS.length; i++) {
			var key = PROFILE_FIELD_KEYS[i];
			var input = findField(section, key);
			if (!input) { continue; }
			var v = me && Object.prototype.hasOwnProperty.call(me, key) ? me[key] : null;
			input.value = v === null || v === undefined ? '' : String(v);
		}
		var wrap = el(section, PROFILE_FORM_WRAP);
		if (wrap) { wrap.hidden = false; }
	}

	function readProfileForm(section) {
		var hid = el(section, PROFILE_LINK_ID);
		var linkId = hid ? parseInt(hid.value || '', 10) : NaN;
		var body = { link_id: linkId };
		for (var i = 0; i < PROFILE_FIELD_KEYS.length; i++) {
			var key = PROFILE_FIELD_KEYS[i];
			var input = findField(section, key);
			var val = input ? (input.value || '') : '';
			if (typeof val === 'string') { val = val.trim(); }
			body[key] = val;
		}
		return body;
	}

	function findRecordById(records, linkId) {
		for (var i = 0; i < records.length; i++) {
			if (parseInt(records[i].link_id, 10) === linkId) { return records[i]; }
		}
		return null;
	}

	function loadMe(config, section, linkId, opt) {
		opt = opt || {};
		var wrap = el(section, PROFILE_FORM_WRAP);
		clearNotices(section);
		var mePath = config.profile_me_path || config.me_path;
		var url = apiUrl(config.rest_root, mePath + '?link_id=' + encodeURIComponent(String(linkId)));
		return requestJson(url, {
			method: 'GET',
			credentials: 'same-origin',
			headers: { Accept: 'application/json', 'X-WP-Nonce': config.nonce }
		}).then(function (result) {
			var payload = result.body && result.body.data ? result.body.data : null;
			if (result.ok && payload && typeof payload === 'object') {
				var record = findRecordById(config.profile_records || [], parseInt(linkId, 10));
				populateForm(section, payload, record);
				var firstInput = findField(section, PROFILE_FIELD_KEYS[0]);
				if (firstInput && firstInput.focus) { firstInput.focus(); }
				return payload;
			}
			if (wrap) { wrap.hidden = true; }
			setNotice(section, 'error', serverMessage(result) || TEXT_PROFILE_LOAD_FAILED);
			return null;
		}, function () {
			if (wrap) { wrap.hidden = true; }
			setNotice(section, 'error', TEXT_PROFILE_LOAD_FAILED);
			return null;
		});
	}

	function saveProfile(config, section, button) {
		if (button.disabled) { return; }
		var idleLabel = button.textContent;
		var hid = el(section, PROFILE_LINK_ID);
		var linkId = hid ? parseInt(hid.value || '', 10) : NaN;
		if (!(linkId > 0)) {
			setNotice(section, 'error', 'ابتدا پروندهٔ مورد نظر را انتخاب کنید.');
			return;
		}
		clearNotices(section);
		setBusy(button, true, idleLabel, TEXT_PROFILE_SAVE);
		var payload = readProfileForm(section);
		var body = { link_id: payload.link_id };
		for (var i = 0; i < PROFILE_FIELD_KEYS.length; i++) {
			body[PROFILE_FIELD_KEYS[i]] = payload[PROFILE_FIELD_KEYS[i]];
		}
		var mePath = config.profile_me_path || config.me_path;
		requestJson(apiUrl(config.rest_root, mePath), {
			method: 'PUT',
			credentials: 'same-origin',
			headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
			body: JSON.stringify(body)
		}).then(function (result) {
			var data = result.body && result.body.data ? result.body.data : null;
			setBusy(button, false, idleLabel);
			if (result.ok && data) {
				var record = findRecordById(config.profile_records || [], parseInt(body.link_id, 10));
				populateForm(section, data, record);
				setNotice(section, 'success', TEXT_PROFILE_SAVED);
				button.focus();
				return;
			}
			setNotice(section, 'error', serverMessage(result) || TEXT_PROFILE_SAVE_FAILED);
			button.focus();
		}, function () {
			setBusy(button, false, idleLabel);
			setNotice(section, 'error', TEXT_PROFILE_SAVE_FAILED);
			button.focus();
		});
	}

	function buildFormMarkup() {
		var fields = [
			{key:'first_name', label:'نام', type:'text', attrs:'autocomplete="given-name"'},
			{key:'last_name', label:'نام خانوادگی', type:'text', attrs:'autocomplete="family-name"'},
			{key:'national_id', label:'کد ملی', type:'text', attrs:'inputmode="numeric" autocomplete="off" dir="ltr"'},
			{key:'birth_date', label:'تاریخ تولد', type:'text', attrs:'placeholder="YYYY-MM-DD" dir="ltr"'},
			{key:'gender', label:'جنسیت', type:'select', options:{'':'انتخاب کنید','male':'مرد','female':'زن','other':'سایر'}},
			{key:'address', label:'آدرس', type:'textarea', full:true},
			{key:'phone', label:'تلفن ثابت', type:'tel', attrs:'autocomplete="tel" dir="ltr"'},
			{key:'emergency_contact_name', label:'نام تماس اضطراری', type:'text'},
			{key:'emergency_contact_phone', label:'تلفن تماس اضطراری', type:'tel', attrs:'dir="ltr"'}
		];
		var html = '<form class="cpms-pp-profile__form" data-role="profile-form" novalidate>'
			+ '<input type="hidden" name="link_id" data-role="profile-link-id" value="">'
			+ '<div class="notice inline cpms-pp-profile__notice cpms-pp-profile__notice--success" data-role="profile-success" hidden role="alert"></div>'
			+ '<div class="notice inline cpms-pp-profile__notice cpms-pp-profile__notice--error" data-role="profile-error" hidden role="alert"></div>'
			+ '<div class="cpms-pp-profile__mobile" data-role="profile-mobile-row" hidden>'
			+   '<span class="cpms-pp-profile__mobile-label">شماره موبایل ورود</span>'
			+   '<div data-role="profile-login-mobile" class="cpms-pp-profile__mobile-value" dir="ltr"></div>'
			+   '<p class="description">این شماره موبایل هویت ورود شماست و از این بخش قابل تغییر نیست. برای تغییر با مطب تماس بگیرید.</p>'
			+ '</div>'
			+ '<div class="cpms-pp-profile__grid">';
		for (var i = 0; i < fields.length; i++) {
			var f = fields[i];
			var cls = 'cpms-pp-field' + (f.full ? ' cpms-pp-field--full' : '');
			var ctrl = '';
			if (f.type === 'select') {
				ctrl = '<select id="cpms-pp-' + f.key + '" name="' + f.key + '" data-field="' + f.key + '">';
				for (var v in f.options) {
					if (Object.prototype.hasOwnProperty.call(f.options, v)) {
						ctrl += '<option value="' + v + '">' + f.options[v] + '</option>';
					}
				}
				ctrl += '</select>';
			} else if (f.type === 'textarea') {
				ctrl = '<textarea id="cpms-pp-' + f.key + '" name="' + f.key + '" data-field="' + f.key + '" rows="2"' + (f.attrs ? ' ' + f.attrs : '') + '></textarea>';
			} else {
				ctrl = '<input id="cpms-pp-' + f.key + '" name="' + f.key + '" type="' + f.type + '" data-field="' + f.key + '" value=""' + (f.attrs ? ' ' + f.attrs : '') + '>';
			}
			html += '<div class="' + cls + '">'
				+ '<label for="cpms-pp-' + f.key + '">' + f.label + '</label>'
				+ ctrl
				+ '</div>';
		}
		html += '</div>'
			+ '<div class="cpms-pp-profile__actions">'
			+ '<button type="submit" class="cpms-pp-btn cpms-pp-btn--primary" data-role="profile-save">ذخیرهٔ اطلاعات</button>'
			+ '</div>'
			+ '</form>';
		return html;
	}

	function bindProfile(config) {
		var section = document.querySelector(PROFILE_SECTION);
		if (!section) { return; }
		// No profile navigation/events unless the Profile JS surface exists.
		if (!isNonEmptyString(config.me_path)) { return; }
		if (!Array.isArray(config.profile_records)) { config.profile_records = []; }

		// Populate login mobile (already rendered by server for N=1/N>1; keep if present).

		// N=1 with server-provided initial ⇒ form already server-rendered; just sync context (already server-rendered) and bind handlers.

		// N>1 selector binding.
		var sel = el(section, PROFILE_SELECT);
		if (sel) {
			sel.addEventListener('change', function () {
				var linkId = parseInt(sel.value || '', 10);
				var wrap = el(section, PROFILE_FORM_WRAP);
				clearNotices(section);
				if (!(linkId > 0)) {
					if (wrap) { wrap.hidden = true; wrap.innerHTML = ''; }
					var ctx = el(section, PROFILE_CONTEXT);
					if (ctx) { ctx.hidden = true; }
					return;
				}
				// Ensure the form markup is in place (rendered empty server-side for N>1).
				if (wrap && !el(wrap, PROFILE_FORM)) {
					wrap.innerHTML = buildFormMarkup();
				}
				if (wrap) { wrap.hidden = false; }
				loadMe(config, section, linkId);
			});
		}

		var form = el(section, PROFILE_FORM);
		var saveBtn = el(section, PROFILE_SAVE);
		if (form && saveBtn) {
			form.addEventListener('submit', function (e) {
				e.preventDefault();
				saveProfile(config, section, saveBtn);
			});
		}
	}

	/**
	 * Anchor-based section nav (in-page, no SPA framework): toggle is-active + aria-current
	 * between appointments/profile links. Purely presentational; server-side render owns
	 * what is on the page.
	 */
	/** C5/C6: the server owns authorization. Selection and request generation are UI state only. */
	function bindVisits(config) {
		var section = document.querySelector('[data-role="visits-section"]');
		if (!section) { return; }
		var records = config.profile_records || [];
		var selector = section.querySelector('[data-role="visits-record-select"]');
		var context = section.querySelector('[data-role="visits-context"]');
		var list = section.querySelector('[data-role="visits-list"]');
		var detail = section.querySelector('[data-role="visits-detail"]');
		var loading = section.querySelector('[data-role="visits-loading"]');
		var error = section.querySelector('[data-role="visits-error"]');
		var selected = records.length === 1 ? records[0] : null;
		var generation = 0;

		function text(parent, tag, value) {
			var node = document.createElement(tag);
			node.textContent = value || '';
			parent.appendChild(node);
			return node;
		}
		function request(path, render) {
			if (!selected) { return; }
			var current = ++generation;
			loading.hidden = false;
			clearError(error);
			requestJson(apiUrl(config.rest_root, path + '?link_id=' + encodeURIComponent(selected.link_id)), {
				method: 'GET', credentials: 'same-origin', headers: { 'X-WP-Nonce': config.nonce }
			}).then(function (result) {
				if (current !== generation) { return; }
				if (!result.ok || !result.body || !result.body.data) {
					list.textContent = '';
					detail.textContent = '';
					showError(error, serverMessage(result) || 'دریافت ویزیت انجام نشد. دوباره تلاش کنید.', serverCode(result));
					return;
				}
				render(result.body.data);
			}).catch(function () {
				if (current !== generation) { return; }
				list.textContent = '';
				detail.textContent = '';
				showError(error, 'ارتباط برقرار نشد. دوباره تلاش کنید.', 'NETWORK');
			}).then(function () {
				if (current === generation) { loading.hidden = true; }
			});
		}
		function choose() {
			generation++;
			selected = null;
			list.textContent = '';
			detail.textContent = '';
			context.textContent = '';
			loading.hidden = true;
			clearError(error);
			records.forEach(function (record) {
				if (String(record.link_id) === selector.value) { selected = record; }
			});
			if (!selected) { return; }
			context.textContent = selected.clinic_name + ' — ' + selected.patient_display_name + ' — ' + selected.mrn;
			request(config.visits_path, function (data) {
				(data.visits || []).forEach(function (visit) {
					var button = text(text(list, 'li', ''), 'button', visit.visit_date + ' — ' + (visit.clinician_name || ''));
					button.type = 'button';
					button.className = 'cpms-pp-btn cpms-pp-btn--ghost';
					button.setAttribute('data-role', 'visit-open');
					button.setAttribute('data-visit-id', visit.id);
				});
				if (!list.children.length) { text(list, 'li', 'ویزیتی ثبت نشده است.'); }
			});
		}
		if (selector) { selector.addEventListener('change', choose); }
		list.addEventListener('click', function (event) {
			var button = event.target.closest('[data-role="visit-open"]');
			if (!button || !list.contains(button) || !selected) { return; }
			var id = button.getAttribute('data-visit-id');
			if (!/^[1-9][0-9]*$/.test(id)) { return; }
			detail.textContent = '';
			request(config.visit_detail_path.replace('{id}', id), function (data) {
				// Explicit display allowlist. Never render raw DTOs, actor IDs,
				// correction reasons, workflow enums, or other internal metadata.
				text(detail, 'h2', 'جزئیات ویزیت — ' + data.visit.visit_date);
				text(detail, 'h3', 'یادداشت‌ها');
				(data.notes || []).forEach(function (note) { text(detail, 'p', note.content_text); });
				text(detail, 'h3', 'توصیه‌ها');
				(data.recommendations || []).forEach(function (item) { text(detail, 'p', item.text); });
				if (!(data.notes || []).length && !(data.recommendations || []).length) {
					text(detail, 'p', 'محتوای قابل نمایش ثبت نشده است.');
				}
			});
		});
	}

	function bindSectionNav() {
		var navLinks = document.querySelectorAll('[data-role="patient-nav"] a');
		function apply() {
			var hash = (window.location.hash || '').replace(/^#/, '');
			var target = (hash === 'profile' || hash === 'visits') ? hash : 'appointments';
			Array.prototype.forEach.call(navLinks, function (a) {
				var role = a.getAttribute('data-role');
				var isActive = role === 'nav-' + target;
				if (isActive) {
					a.classList.add('is-active');
					a.setAttribute('aria-current', 'page');
				} else {
					a.classList.remove('is-active');
					a.removeAttribute('aria-current');
				}
			});
		}
		window.addEventListener('hashchange', apply);
		apply();
	}

	/**
	 * Accessible confirm dialog owned by the Patient Portal surface.
	 * (Same contract as cpms-admin.js modal — UX only; B4 remains authorization.)
	 * Lives here so the independent frontend shell does not depend on cpms-admin assets.
	 */
	function showConfirm(message) {
		return new Promise(function (resolve) {
			var old = document.querySelector('.cpms-modal-overlay');
			if (old && old.parentNode) {
				old.parentNode.removeChild(old);
			}
			var lastFocus = document.activeElement;
			var overlay = document.createElement('div');
			overlay.className = 'cpms-modal-overlay';
			overlay.setAttribute('role', 'presentation');
			var dialog = document.createElement('div');
			dialog.className = 'cpms-modal';
			dialog.setAttribute('role', 'dialog');
			dialog.setAttribute('aria-modal', 'true');
			dialog.setAttribute('dir', 'rtl');
			dialog.innerHTML =
				'<h3>تأیید عملیات</h3>' +
				'<p class="cpms-modal-message"></p>' +
				'<div class="cpms-modal-actions">' +
					'<button type="button" class="button button-primary cpms-modal-confirm">تأیید و ادامه</button>' +
					'<button type="button" class="button cpms-modal-cancel">انصراف</button>' +
				'</div>';
			dialog.querySelector('.cpms-modal-message').textContent = message;
			overlay.appendChild(dialog);
			document.body.appendChild(overlay);
			var confirmBtn = dialog.querySelector('.cpms-modal-confirm');
			var cancelBtn = dialog.querySelector('.cpms-modal-cancel');
			function done(ok) {
				document.removeEventListener('keydown', onKey, true);
				if (overlay.parentNode) {
					overlay.parentNode.removeChild(overlay);
				}
				resolve(ok);
				if (lastFocus && lastFocus.focus) {
					try {
						lastFocus.focus();
					} catch (e) {}
				}
			}
			function onKey(e) {
				if (e.key === 'Escape') {
					e.preventDefault();
					done(false);
				}
				if (e.key === 'Tab') {
					var focusables = [cancelBtn, confirmBtn];
					var i = focusables.indexOf(document.activeElement);
					if (e.shiftKey) {
						if (i <= 0) {
							e.preventDefault();
							confirmBtn.focus();
						}
					} else if (i === focusables.length - 1 || i === -1) {
						e.preventDefault();
						cancelBtn.focus();
					}
				}
			}
			confirmBtn.addEventListener('click', function () {
				done(true);
			});
			cancelBtn.addEventListener('click', function () {
				done(false);
			});
			document.addEventListener('keydown', onKey, true);
			confirmBtn.focus();
		});
	}

	function bind(config) {
		var buttons = document.querySelectorAll(BUTTON_SELECTOR);
		Array.prototype.forEach.call(buttons, function (button) {
			button.addEventListener('click', function () {
				if (button.hasAttribute(CONFIRM_ATTR)) {
					var message = button.getAttribute(CONFIRM_ATTR) || '';
					// First click: open portal-owned confirm. On accept, drop the attr
					// and re-click so cancelAppointment runs (same contract as cpms-admin).
					showConfirm(message).then(function (ok) {
						if (!ok) {
							return;
						}
						button.removeAttribute(CONFIRM_ATTR);
						button.click();
					});
					return;
				}
				cancelAppointment(config, button);
			});
		});
	}

	function init() {
		var config = readConfig();
		if (!config) {
			return;
		}
		bind(config);
		bindMarkAllRead(config);
		bindProfile(config);
		bindVisits(config);
		bindSectionNav();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
