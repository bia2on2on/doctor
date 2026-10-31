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

	function readOtpConfig() {
		var element = document.querySelector('script.cpms-patient-portal__auth-config');
		if (!element) { return null; }
		var parsed;
		try { parsed = JSON.parse(element.textContent || ''); }
		catch (e) { return null; }
		if (!parsed || typeof parsed !== 'object') { return null; }
		if (!isNonEmptyString(parsed.rest_root) || parsed.otp_request_path !== '/otp/request' || parsed.otp_verify_path !== '/otp/verify') { return null; }
		return parsed;
	}

	function otpDigits(value) {
		return String(value || '')
			.replace(/[\u06F0-\u06F9]/g, function (digit) { return String(digit.charCodeAt(0) - 0x06F0); })
			.replace(/[\u0660-\u0669]/g, function (digit) { return String(digit.charCodeAt(0) - 0x0660); })
			.replace(/[^0-9]/g, '');
	}

	function bindPatientOtpLogin() {
		var form = document.querySelector('[data-role="patient-otp-login"]');
		var config = readOtpConfig();
		if (!form || !config || typeof window.fetch !== 'function') { return; }

		var mobileInput = form.querySelector('[data-role="patient-otp-mobile"]');
		var codeStep = form.querySelector('[data-role="patient-otp-code-step"]');
		var codeInput = form.querySelector('[data-role="patient-otp-code"]');
		var requestButton = form.querySelector('[data-role="patient-otp-request"]');
		var verifyButton = form.querySelector('[data-role="patient-otp-verify"]');
		var status = form.querySelector('[data-role="patient-otp-status"]');
		if (!mobileInput || !codeStep || !codeInput || !requestButton || !verifyButton || !status) { return; }

		var busy = false;
		var codeRequested = false;
		function setStatus(message, state) {
			status.textContent = message || '';
			status.hidden = !message;
			if (state) { status.setAttribute('data-state', state); }
			else { status.removeAttribute('data-state'); }
		}
		function setOtpBusy(value) {
			busy = !!value;
			requestButton.disabled = busy;
			verifyButton.disabled = busy;
			form.setAttribute('aria-busy', busy ? 'true' : 'false');
		}
		function fail(result, fallback) {
			setStatus(serverMessage(result) || fallback, 'error');
		}
		function requestCode() {
			if (busy) { return; }
			var mobile = otpDigits(mobileInput.value);
			if (!mobile) {
				setStatus('شمارهٔ موبایل را وارد کنید.', 'error');
				mobileInput.focus();
				return;
			}
			setOtpBusy(true);
			setStatus('در حال ثبت درخواست کد…', 'busy');
			requestJson(apiUrl(config.rest_root, config.otp_request_path), {
				method: 'POST',
				credentials: 'same-origin',
				headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
				body: JSON.stringify({ mobile: mobile, purpose: 'login' })
			}).then(function (result) {
				setOtpBusy(false);
				if (!result.ok || !result.body || !result.body.data || result.body.data.sms_sent !== true) {
					fail(result, 'ارسال کد تأیید نشد. کمی بعد دوباره تلاش کنید.');
					return;
				}
				codeRequested = true;
				codeStep.hidden = false;
				requestButton.hidden = true;
				verifyButton.hidden = false;
				setStatus('درخواست کد ثبت شد. اگر پیامک دریافت نشد، کمی بعد دوباره تلاش کنید.', 'success');
				codeInput.focus();
			}, function () {
				setOtpBusy(false);
				setStatus('ارتباط برقرار نشد. اتصال خود را بررسی و دوباره تلاش کنید.', 'error');
			});
		}
		function verifyCode() {
			if (busy || !codeRequested) { return; }
			var mobile = otpDigits(mobileInput.value);
			var code = otpDigits(codeInput.value);
			if (!mobile || !/^\d{6}$/.test(code)) {
				setStatus('شمارهٔ موبایل و کد ۶ رقمی را بررسی کنید.', 'error');
				(!mobile ? mobileInput : codeInput).focus();
				return;
			}
			setOtpBusy(true);
			setStatus('در حال بررسی کد…', 'busy');
			requestJson(apiUrl(config.rest_root, config.otp_verify_path), {
				method: 'POST',
				credentials: 'same-origin',
				headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
				body: JSON.stringify({ mobile: mobile, code: code })
			}).then(function (result) {
				setOtpBusy(false);
				var payload = result.body && result.body.data ? result.body.data : null;
				if (result.ok && payload && payload.session_issued === true) {
					window.location.reload();
					return;
				}
				fail(result, 'کد معتبر نیست یا ورود برای این حساب مجاز نیست. دوباره تلاش کنید.');
				codeInput.focus();
			}, function () {
				setOtpBusy(false);
				setStatus('ارتباط برقرار نشد. اتصال خود را بررسی و دوباره تلاش کنید.', 'error');
			});
		}

		requestButton.addEventListener('click', requestCode);
		verifyButton.addEventListener('click', verifyCode);
		form.addEventListener('submit', function (event) {
			event.preventDefault();
			if (codeRequested) { verifyCode(); }
			else { requestCode(); }
		});
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
	 * between dashboard, booking, appointment, profile and clinical links. Purely
	 * presentational; server-side render owns what is on the page.
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
					var button = text(text(list, 'li', ''), 'button', visit.visit_jalali + ' — ' + (visit.clinician_name || ''));
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
				text(detail, 'h2', 'جزئیات ویزیت — ' + data.visit.visit_jalali);
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

	/** C7: the server owns authorization. Selection and request generation are UI state only. */
	function bindPrescriptions(config) {
		var section = document.querySelector('[data-role="prescriptions-section"]');
		if (!section) { return; }
		var records = config.profile_records || [];
		var selector = section.querySelector('[data-role="prescriptions-record-select"]');
		var context = section.querySelector('[data-role="prescriptions-context"]');
		var list = section.querySelector('[data-role="prescriptions-list"]');
		var loading = section.querySelector('[data-role="prescriptions-loading"]');
		var error = section.querySelector('[data-role="prescriptions-error"]');
		var selected = records.length === 1 ? records[0] : null;
		var generation = 0;

		function text(parent, tag, value) {
			var node = document.createElement(tag);
			node.textContent = value || '';
			parent.appendChild(node);
			return node;
		}
		function renderItems(rxList) {
			list.textContent = '';
			if (!rxList || !rxList.length) {
				text(list, 'li', 'نسخه‌ای ثبت نشده است.');
				return;
			}
			rxList.forEach(function (rx) {
				var item = text(list, 'li', '');
				item.setAttribute('data-role', 'prescription-item');
				var header = text(item, 'div', rx.prescription_number + ' — ' + (rx.created_at_jalali || ''));
				header.className = 'cpms-pp-prescription__header';
				if (rx.items && rx.items.length) {
					var itemsList = text(item, 'ul', '');
					itemsList.className = 'cpms-pp-prescription__items';
					rx.items.forEach(function (med) {
						var parts = [];
						if (med.generic_name) { parts.push(med.generic_name); }
						if (med.brand_name) { parts.push('(' + med.brand_name + ')'); }
						if (med.strength) { parts.push(med.strength); }
						if (med.form) { parts.push(med.form); }
						if (med.dose) { parts.push(med.dose); }
						if (med.frequency) { parts.push(med.frequency); }
						if (med.route) { parts.push(med.route); }
						if (med.duration_days) { parts.push(med.duration_days + ' روز'); }
						var line = parts.join(' — ');
						if (med.instructions) { line += ' — دستور: ' + med.instructions; }
						text(itemsList, 'li', line);
					});
				}
			});
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
					showError(error, serverMessage(result) || 'دریافت نسخه‌ها انجام نشد. دوباره تلاش کنید.', serverCode(result));
					return;
				}
				render(result.body.data);
			}).catch(function () {
				if (current !== generation) { return; }
				list.textContent = '';
				showError(error, 'ارتباط برقرار نشد. دوباره تلاش کنید.', 'NETWORK');
			}).then(function () {
				if (current === generation) { loading.hidden = true; }
			});
		}
		function choose() {
			generation++;
			selected = null;
			list.textContent = '';
			context.textContent = '';
			loading.hidden = true;
			clearError(error);
			records.forEach(function (record) {
				if (String(record.link_id) === selector.value) { selected = record; }
			});
			if (!selected) { return; }
			context.textContent = selected.clinic_name + ' — ' + selected.patient_display_name + ' — ' + selected.mrn;
			request(config.prescriptions_path || '/prescriptions', function (data) {
				renderItems(data.prescriptions || []);
			});
		}
		if (selector) { selector.addEventListener('change', choose); }
	}

	/**
	 * Slice 7 — «فایل‌های من»: فهرستِ بیمار + دانلودِ محافظتی + آپلود بیمار روی
	 * همان مسیرهای موجودِ C3/C4/E17. مجوز صددرصد سروری است (لینک پایدار فعال +
	 * `link_id` برای رکوردِ انتخابی); مرورگر فقط آنچه مسیرِ موجود ایجاب می‌کند
	 * می‌فرستد: `patient_id` مسیر، `link_id` رکورد و شناسهٔ فایل برای stream —
	 * هیچ‌کدام مجوز نیستند. نمایش فقط فهرستِ مجاز (نام/تاریخ جلالیِ موثق/حجم/دسته).
	 */
	function bindFiles(config) {
		var section = document.querySelector('[data-role="files-section"]');
		if (!section) { return; }
		if (!isNonEmptyString(config.files_path) || !isNonEmptyString(config.files_stream_path)) { return; }
		var records = Array.isArray(config.profile_records) ? config.profile_records : [];
		var selector = section.querySelector('[data-role="files-record-select"]');
		var context = section.querySelector('[data-role="files-context"]');
		var loading = section.querySelector('[data-role="files-loading"]');
		var listBox = section.querySelector('[data-role="files-error"]');
		var generation = 0;
		var selected = null;

		function findList() { return section.querySelector('[data-role="files-list"]'); }
		function text(parent, tag, value) {
			var node = document.createElement(tag);
			node.textContent = value || '';
			parent.appendChild(node);
			return node;
		}
		function recordLabel(record) {
			return (record.clinic_name || '') + ' — ' + (record.patient_display_name || '') + ' — ' + (record.mrn || '');
		}
		function fileSizeLabel(bytes) {
			var size = parseInt(bytes, 10) || 0;
			if (size >= 1048576) { return (Math.round((size / 1048576) * 10) / 10) + ' مگابایت'; }
			if (size >= 1024) { return Math.round(size / 1024) + ' کیلوبایت'; }
			return size + ' بایت';
		}
		function fileCategoryLabel(category) {
			var labels = { lab_result: 'نتیجه آزمایش', image: 'تصویر', scan: 'اسکن', document: 'مدارک', other: 'سایر' };
			return labels[category] || labels.other;
		}
		function filesUrl() {
			if (!selected) { return ''; }
			var path = config.files_path.replace('{patient_id}', encodeURIComponent(String(selected.patient_id)));
			return apiUrl(config.rest_root, path + '?link_id=' + encodeURIComponent(String(selected.link_id)));
		}
		function streamUrl(fileId) {
			return apiUrl(config.rest_root, config.files_stream_path.replace('{id}', encodeURIComponent(String(fileId))));
		}
		function renderItems(files) {
			var list = findList();
			if (!list) { return; }
			list.textContent = '';
			if (!files || !files.length) {
				text(list, 'li', 'فایلی ثبت نشده است.');
				return;
			}
			files.forEach(function (file) {
				var item = document.createElement('li');
				item.className = 'cpms-pp-file';
				item.setAttribute('data-role', 'file-item');
				var name = text(item, 'span', file.original_filename || '');
				name.className = 'cpms-pp-file__name';
				// تاریخ جلالی فقط وقتی سرور آن را منتشر کرده (مسیر موثق Location).
				if (isNonEmptyString(file.created_at_jalali)) {
					var date = text(item, 'span', file.created_at_jalali);
					date.className = 'cpms-pp-file__date';
					date.setAttribute('dir', 'ltr');
				}
				var meta = text(item, 'span', fileCategoryLabel(file.category) + ' — ' + fileSizeLabel(file.file_size));
				meta.className = 'cpms-pp-file__meta';
				var button = text(item, 'button', 'دریافت فایل');
				button.type = 'button';
				button.className = 'cpms-pp-btn cpms-pp-btn--ghost';
				button.setAttribute('data-role', 'file-download');
				button.setAttribute('data-file-id', String(file.id || ''));
				list.appendChild(item);
			});
		}
		function loadFiles() {
			var list = findList();
			if (!selected || !list) { return; }
			var current = ++generation;
			if (loading) { loading.hidden = false; }
			clearError(listBox);
			requestJson(filesUrl(), {
				method: 'GET', credentials: 'same-origin',
				headers: { Accept: 'application/json', 'X-WP-Nonce': config.nonce }
			}).then(function (result) {
				if (current !== generation) { return; }
				var payload = result.body && result.body.data ? result.body.data : null;
				if (!result.ok || !payload || !Array.isArray(payload.files)) {
					list.textContent = '';
					showError(listBox, serverMessage(result) || 'دریافت فایل‌ها انجام نشد. دوباره تلاش کنید.', serverCode(result));
					return;
				}
				renderItems(payload.files);
			}).catch(function () {
				if (current !== generation) { return; }
				list.textContent = '';
				showError(listBox, 'ارتباط برقرار نشد. دوباره تلاش کنید.', 'NETWORK');
			}).then(function () {
				if (current === generation && loading) { loading.hidden = true; }
			});
		}
		function downloadFile(fileId, button) {
			button.disabled = true;
			window.fetch(streamUrl(fileId), {
				method: 'GET', credentials: 'same-origin', headers: { 'X-WP-Nonce': config.nonce }
			}).then(function (response) {
				if (!response.ok) { throw new Error('download denied'); }
				return response.blob();
			}).then(function (blob) {
				var url = URL.createObjectURL(blob);
				var link = document.createElement('a');
				link.href = url;
				link.download = '';
				document.body.appendChild(link);
				link.click();
				link.parentNode.removeChild(link);
				window.setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
			}).catch(function () {
				showError(listBox, 'دریافت فایل انجام نشد. دوباره تلاش کنید.', 'DOWNLOAD_FAILED');
			}).then(function () {
				button.disabled = false;
			});
		}
		function uploadNotice(kind) { return section.querySelector('[data-role="files-upload-' + kind + '"]'); }
		function setUploadNotice(kind, message) {
			['progress', 'success', 'error'].forEach(function (k) {
				var box = uploadNotice(k);
				if (!box) { return; }
				if (k === kind) { box.textContent = message || ''; box.hidden = false; }
				else { box.textContent = ''; box.hidden = true; }
			});
		}
		function removeStaleUpload() {
			var input = section.querySelector('[data-role="files-upload-input"]');
			if (!input) { return; }
			var wrap = input.closest('.cpms-pp-files__upload');
			var node = wrap || input;
			if (node.parentNode) { node.parentNode.removeChild(node); }
		}
		function buildUploadMarkup(patientId) {
			var wrap = document.createElement('div');
			wrap.className = 'cpms-pp-files__upload';
			wrap.setAttribute('data-record-patient', String(patientId));
			wrap.innerHTML = '<label for="cpms-files-upload-input-dynamic">افزودن فایل (PDF / JPG / PNG / WEBP)</label>'
				+ '<input id="cpms-files-upload-input-dynamic" type="file" data-role="files-upload-input" accept=".pdf,.jpg,.jpeg,.png,.webp">'
				+ '<button type="button" class="cpms-pp-btn cpms-pp-btn--primary" data-role="files-upload-button">ارسال فایل</button>'
				+ '<p role="status" data-role="files-upload-progress" hidden>در حال ارسال فایل…</p>'
				+ '<div class="notice inline cpms-pp-files__notice cpms-pp-files__notice--success" role="status" data-role="files-upload-success" hidden></div>'
				+ '<div class="notice inline cpms-pp-files__notice cpms-pp-files__notice--error" role="alert" data-role="files-upload-error" hidden></div>';
			return wrap;
		}
		function uploadSelectedFile() {
			if (!selected) { return; }
			var input = section.querySelector('[data-role="files-upload-input"]');
			var button = section.querySelector('[data-role="files-upload-button"]');
			if (!input || !button || button.disabled) { return; }
			var file = input.files && input.files.length ? input.files[0] : null;
			if (!file) {
				setUploadNotice('error', 'ابتدا یک فایل انتخاب کنید.');
				return;
			}
			button.disabled = true;
			setUploadNotice('progress', 'در حال ارسال فایل…');
			var form = new FormData();
			form.append('file', file);
			window.fetch(filesUrl(), {
				method: 'POST', credentials: 'same-origin',
				headers: { Accept: 'application/json', 'X-WP-Nonce': config.nonce },
				body: form
			}).then(function (response) {
				return response.json().then(
					function (body) { return { ok: response.ok, status: response.status, body: body }; },
					function () { return { ok: false, status: response.status, body: null }; }
				);
			}).then(function (result) {
				button.disabled = false;
				if (result.ok && result.body && result.body.data) {
					input.value = '';
					setUploadNotice('success', 'فایل شما با موفقیت ارسال شد.');
					loadFiles();
					return;
				}
				setUploadNotice('error', serverMessage(result) || 'ارسال فایل انجام نشد. دوباره تلاش کنید.');
			}).catch(function () {
				button.disabled = false;
				setUploadNotice('error', 'ارتباط برقرار نشد. دوباره تلاش کنید.');
			});
		}

		// رکوردِ یگانه: فهرست سرور-رندر است؛ `selected` از همان حقیقتِ سروری.
		if (!selector && records.length === 1) {
			var soleWrap = section.querySelector('.cpms-pp-files__upload[data-record-patient]');
			var solePatient = soleWrap ? parseInt(soleWrap.getAttribute('data-record-patient') || '', 10) : 0;
			if (solePatient > 0) {
				selected = { link_id: records[0].link_id, patient_id: solePatient, label: recordLabel(records[0]) };
			}
		}

		if (selector) {
			selector.addEventListener('change', function () {
				generation++;
				selected = null;
				// تعویض رکورد: پاکسازی کامل فهرست/هدف آپلود/حالت‌های مانده.
				var staleList = findList();
				if (staleList && staleList.parentNode) { staleList.parentNode.removeChild(staleList); }
				removeStaleUpload();
				if (context) { context.textContent = ''; context.hidden = true; }
				if (loading) { loading.hidden = true; }
				clearError(listBox);
				var linkId = parseInt(selector.value || '', 10);
				if (!(linkId > 0)) { return; }
				var record = null;
				records.forEach(function (r) {
					if (parseInt(r.link_id, 10) === linkId) { record = r; }
				});
				var option = selector.querySelector('option[value="' + selector.value + '"]');
				var patientId = option ? parseInt(option.getAttribute('data-record-patient') || '', 10) : 0;
				if (!record || !(patientId > 0)) { return; }
				selected = { link_id: linkId, patient_id: patientId, label: recordLabel(record) };
				if (context) { context.textContent = selected.label; context.hidden = false; }
				var list = document.createElement('ul');
				list.className = 'cpms-pp-files__list';
				list.setAttribute('data-role', 'files-list');
				section.appendChild(list);
				section.appendChild(buildUploadMarkup(patientId));
				loadFiles();
			});
		}

		// دانلود و آپلود: رویدادها به کل بخش تفویض می‌شوند تا ردیف‌های سرور-رندر
		// و ردیف‌های ساخته‌شدهٔ کلاینت هر دو پوشش داده شوند.
		section.addEventListener('click', function (event) {
			var target = event.target;
			if (!target || !target.closest) { return; }
			var download = target.closest('[data-role="file-download"]');
			if (download && section.contains(download)) {
				var fileId = download.getAttribute('data-file-id');
				if (/^[1-9][0-9]*$/.test(fileId || '')) {
					downloadFile(parseInt(fileId, 10), download);
				}
				return;
			}
			var upload = target.closest('[data-role="files-upload-button"]');
			if (upload && section.contains(upload)) {
				uploadSelectedFile();
			}
		});
	}


	/**
	 * ثبت پرونده در مرکز دیگر — Classic Patient Portal.
	 *
	 * Server has already authorized the destination list; the browser only sends
	 * the explicitly selected id to the existing C0a endpoint with the existing
	 * wp_rest nonce. A malformed 2xx is never treated as success, repeated submits
	 * are blocked while a request is in flight, and no booking action exists.
	 */
	function enrollmentDigits(value) {
		return String(value || '')
			.replace(/[۰-۹]/g, function (d) { return String(d.charCodeAt(0) - 0x06F0); })
			.replace(/[٠-٩]/g, function (d) { return String(d.charCodeAt(0) - 0x0660); })
			.replace(/[^0-9]/g, '');
	}

	function enrollmentMobile(value) {
		var digits = enrollmentDigits(value);
		if (digits.indexOf('0098') === 0) { digits = digits.substring(4); }
		else if (digits.indexOf('98') === 0 && digits.length >= 12) { digits = digits.substring(2); }
		if (digits.length === 10 && digits.charAt(0) === '9') { digits = '0' + digits; }
		return /^09[0-9]{9}$/.test(digits) ? digits : '';
	}

	function enrollmentError(section, message) {
		var box = section.querySelector('[data-role="enrollment-error"]');
		if (!box) { return; }
		box.textContent = message;
		box.hidden = false;
		try { box.focus(); } catch (e) {}
	}

	function enrollmentClearError(section) {
		var box = section.querySelector('[data-role="enrollment-error"]');
		if (!box) { return; }
		box.textContent = '';
		box.hidden = true;
	}

	function enrollmentSetBusy(form, busy) {
		var button = form.querySelector('[data-role="enrollment-submit"]');
		form.setAttribute('aria-busy', busy ? 'true' : 'false');
		Array.prototype.forEach.call(form.querySelectorAll('input, select, button'), function (field) {
			field.disabled = busy;
		});
		if (button) {
			if (!button.getAttribute('data-enrollment-label')) {
				button.setAttribute('data-enrollment-label', button.textContent);
			}
			button.textContent = busy ? 'در حال ثبت…' : button.getAttribute('data-enrollment-label');
		}
	}

	function enrollmentFail(section, form, result, config) {
		var status = result && result.status ? result.status : 0;
		var code = serverCode(result);
		var message = serverMessage(result);
		enrollmentSetBusy(form, false);

		if (status === 401 || status === 403) {
			var box = section.querySelector('[data-role="enrollment-error"]');
			if (!box) { return; }
			box.textContent = 'نشست شما منقضی شده است. لطفاً دوباره وارد شوید. ';
			if (isNonEmptyString(config.login_url)) {
				var link = document.createElement('a');
				link.href = config.login_url;
				link.textContent = 'ورود دوباره';
				box.appendChild(link);
			}
			box.hidden = false;
			try { box.focus(); } catch (e) {}
			return;
		}

		if (status === 404) {
			var select = form.querySelector('[data-role="enrollment-clinic"]');
			if (select && select.selectedIndex >= 0) {
				select.options[select.selectedIndex].disabled = true;
				select.value = '';
			}
			enrollmentError(section, 'ثبت برای این مرکز در دسترس نیست. مرکز دیگری را انتخاب کنید.');
			return;
		}

		if (status === 503 || code === 'CLINIC_LICENSE_BLOCKED') {
			enrollmentError(section, 'ثبت آنلاین در حال حاضر برای این مرکز ممکن نیست. برای تشکیل پرونده با پذیرش مطب تماس بگیرید.');
			return;
		}

		if (status === 400 && message !== '') {
			enrollmentError(section, message);
			return;
		}

		enrollmentError(section, 'ثبت پرونده انجام نشد. اطلاعات واردشده حفظ شده است؛ دوباره تلاش کنید یا با مطب تماس بگیرید.');
	}

	function enrollmentSuccess(form, section, data) {
		var success = section.querySelector('[data-role="enrollment-success"]');
		var mrn = data && isNonEmptyString(data.mrn) ? data.mrn : '';
		if (!success || mrn === '') {
			enrollmentSetBusy(form, false);
			enrollmentError(section, 'پاسخ سرور کامل نبود؛ وضعیت پرونده را در «پروفایل» بررسی کنید یا با مطب تماس بگیرید.');
			return;
		}
		var mrnNode = success.querySelector('[data-role="enrollment-mrn"]');
		if (mrnNode) { mrnNode.textContent = mrn; }
		form.hidden = true;
		success.hidden = false;
		section.setAttribute('data-state', 'success');
		try { success.focus(); } catch (e) {}
	}

	function bindEnrollment(config) {
		var section = document.querySelector('[data-role="enrollment-section"]');
		var form = section ? section.querySelector('[data-role="patient-enrollment-form"]') : null;
		if (!section || !form || !isNonEmptyString(config.enroll_path)) { return; }

		form.addEventListener('submit', function (event) {
			event.preventDefault();
			if (form.getAttribute('aria-busy') === 'true') { return; }
			enrollmentClearError(section);

			var select = form.querySelector('[data-role="enrollment-clinic"]');
			var first = form.querySelector('[data-role="enrollment-first-name"]');
			var last = form.querySelector('[data-role="enrollment-last-name"]');
			var mobileField = form.querySelector('[data-role="enrollment-mobile"]');
			var clinicId = select ? String(select.value || '') : '';
			var allowed = false;
			if (select && clinicId !== '') {
				Array.prototype.forEach.call(select.options, function (option) {
					if (option.value === clinicId) { allowed = true; }
				});
			}
			if (!allowed) {
				enrollmentError(section, 'لطفاً یک مرکز را از فهرست انتخاب کنید.');
				if (select) { select.focus(); }
				return;
			}

			var firstName = first ? String(first.value || '').trim() : '';
			var lastName = last ? String(last.value || '').trim() : '';
			if (firstName === '' || lastName === '') {
				enrollmentError(section, 'نام و نام خانوادگی را کامل کنید.');
				if (firstName === '' && first) { first.focus(); }
				else if (last) { last.focus(); }
				return;
			}

			var mobile = mobileField ? enrollmentMobile(mobileField.value) : '';
			if (mobile === '') {
				enrollmentError(section, 'شمارهٔ موبایل معتبر نیست (نمونه: 09121234567).');
				if (mobileField) { mobileField.focus(); }
				return;
			}

			enrollmentSetBusy(form, true);
			requestJson(apiUrl(config.rest_root, config.enroll_path), {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': config.nonce
				},
				body: JSON.stringify({
					clinic_id: Number(clinicId),
					first_name: firstName,
					last_name: lastName,
					mobile: mobile
				})
			}).then(function (result) {
				if (!result.ok) {
					enrollmentFail(section, form, result, config);
					return;
				}
				enrollmentSuccess(form, section, result.body && result.body.data ? result.body.data : null);
			}).catch(function () {
				enrollmentSetBusy(form, false);
				enrollmentError(section, 'ارتباط با سرور برقرار نشد. اطلاعات واردشده حفظ شده است؛ دوباره تلاش کنید.');
			});
		});
	}

	function bindSectionNav() {
		var navLinks = document.querySelectorAll('[data-role="patient-nav"] a');
		function apply() {
			var hash = (window.location.hash || '').replace(/^#/, '');
			var sections = ['dashboard', 'booking', 'appointments', 'profile', 'enrollment', 'visits', 'prescriptions', 'files'];
			var target = sections.indexOf(hash) !== -1 ? hash : 'dashboard';
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

	function generateUuid4() {
		if (window.crypto && typeof window.crypto.randomUUID === 'function') {
			return window.crypto.randomUUID();
		}
		if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
			var buf = new Uint8Array(16);
			window.crypto.getRandomValues(buf);
			buf[6] = (buf[6] & 0x0f) | 0x40;
			buf[8] = (buf[8] & 0x3f) | 0x80;
			var hex = [];
			for (var i = 0; i < 16; i += 1) {
				hex.push((buf[i] + 0x100).toString(16).substring(1));
			}
			return hex.slice(0, 4).join('') + '-' +
				hex.slice(4, 6).join('') + '-' +
				hex.slice(6, 8).join('') + '-' +
				hex.slice(8, 10).join('') + '-' +
				hex.slice(10, 16).join('');
		}
		return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (ch) {
			var rnd = (Math.random() * 16) | 0;
			var val = ch === 'x' ? rnd : ((rnd & 0x3) | 0x8);
			return val.toString(16);
		});
	}

	function bindReschedule(config) {
		var rescheduleOptionsPath = isNonEmptyString(config.reschedule_options_path)
			? config.reschedule_options_path
			: '/appointments/{id}/reschedule-options';
		var reschedulePath = isNonEmptyString(config.reschedule_path)
			? config.reschedule_path
			: '/appointments/{id}/reschedule';
		var panel = document.querySelector('[data-role="reschedule-panel"]');
		var buttons = document.querySelectorAll('button[data-role="reschedule-appointment"]');
		if (!panel || !buttons.length) {
			return;
		}
		var summaryNode = panel.querySelector('[data-role="reschedule-summary"]');
		var loadingNode = panel.querySelector('[data-role="reschedule-loading"]');
		var errorNode = panel.querySelector('[data-role="reschedule-error"]');
		var successNode = panel.querySelector('[data-role="reschedule-success"]');
		var emptyNode = panel.querySelector('[data-role="reschedule-empty"]');
		var pickerNode = panel.querySelector('[data-role="reschedule-picker"]');
		var daysNode = panel.querySelector('[data-role="reschedule-days"]');
		var slotsNode = panel.querySelector('[data-role="reschedule-slots"]');
		var selectedNode = panel.querySelector('[data-role="reschedule-selected"]');
		var confirmBtn = panel.querySelector('[data-role="reschedule-confirm"]');
		var abortBtn = panel.querySelector('[data-role="reschedule-abort"]');

		var state = {
			appointmentId: 0,
			triggerBtn: null,
			days: [],
			selectedDayIndex: -1,
			selectedSlot: null,
			busy: false,
			requestSeq: 0
		};

		function resetMessages() {
			if (errorNode) {
				errorNode.textContent = '';
				errorNode.removeAttribute('data-error-code');
				errorNode.hidden = true;
			}
			if (successNode) {
				successNode.textContent = '';
				successNode.hidden = true;
			}
			if (emptyNode) {
				emptyNode.hidden = true;
			}
		}

		function showRescheduleError(message, code) {
			if (!errorNode) {
				return;
			}
			errorNode.textContent = message;
			if (code) {
				errorNode.setAttribute('data-error-code', code);
			}
			errorNode.hidden = false;
		}

		function closePanel() {
			state.requestSeq += 1;
			state.appointmentId = 0;
			state.days = [];
			state.selectedDayIndex = -1;
			state.selectedSlot = null;
			state.busy = false;
			panel.hidden = true;
			resetMessages();
			if (loadingNode) { loadingNode.hidden = true; }
			if (pickerNode) { pickerNode.hidden = true; }
			if (daysNode) { daysNode.innerHTML = ''; }
			if (slotsNode) { slotsNode.innerHTML = ''; }
			if (selectedNode) { selectedNode.textContent = ''; }
			if (confirmBtn) {
				confirmBtn.disabled = true;
				confirmBtn.setAttribute('aria-busy', 'false');
				confirmBtn.textContent = 'تأیید تغییر نوبت';
			}
			if (state.triggerBtn) {
				state.triggerBtn.focus();
				state.triggerBtn = null;
			}
		}

		function updateSelectionSummary() {
			if (!selectedNode || !confirmBtn) {
				return;
			}
			if (!state.selectedSlot || state.selectedDayIndex < 0 || !state.days[state.selectedDayIndex]) {
				selectedNode.textContent = '';
				confirmBtn.disabled = true;
				return;
			}
			var day = state.days[state.selectedDayIndex];
			var text = 'زمان جدید انتخابی: ' + String(day.jalali || day.date || '') + ' ساعت ' + String(state.selectedSlot.time || '');
			if (state.selectedSlot.location_name) {
				text += ' (' + String(state.selectedSlot.location_name) + ')';
			}
			selectedNode.textContent = text;
			confirmBtn.disabled = state.busy;
		}

		function renderSlotsForDay(dayIndex) {
			if (!slotsNode) {
				return;
			}
			slotsNode.innerHTML = '';
			state.selectedSlot = null;
			var day = state.days[dayIndex];
			if (!day || !Array.isArray(day.slots) || !day.slots.length) {
				updateSelectionSummary();
				return;
			}
			day.slots.forEach(function (slot) {
				var btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'cpms-pp-reschedule__slot';
				btn.setAttribute('data-role', 'reschedule-slot');
				btn.setAttribute('data-slot-id', String(slot.slot_id || ''));
				btn.setAttribute('data-slot-time', String(slot.time || ''));
				btn.setAttribute('aria-pressed', 'false');
				btn.textContent = String(slot.time || '');
				btn.addEventListener('click', function () {
					if (state.busy) {
						return;
					}
					var siblings = slotsNode.querySelectorAll('button[data-role="reschedule-slot"]');
					Array.prototype.forEach.call(siblings, function (sib) {
						sib.classList.remove('is-selected');
						sib.setAttribute('aria-pressed', 'false');
					});
					btn.classList.add('is-selected');
					btn.setAttribute('aria-pressed', 'true');
					state.selectedSlot = {
						slot_id: Number(slot.slot_id || 0),
						slot_date: String(day.date || ''),
						slot_time: String(slot.time || ''),
						time: String(slot.time || ''),
						location_name: String(slot.location_name || '')
					};
					resetMessages();
					updateSelectionSummary();
				});
				slotsNode.appendChild(btn);
			});
			updateSelectionSummary();
		}

		function renderDays() {
			if (!daysNode) {
				return;
			}
			daysNode.innerHTML = '';
			state.days.forEach(function (day, idx) {
				var btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'cpms-pp-reschedule__day' + (idx === state.selectedDayIndex ? ' is-selected' : '');
				btn.setAttribute('data-role', 'reschedule-day');
				btn.setAttribute('data-slot-date', String(day.date || ''));
				btn.setAttribute('aria-pressed', idx === state.selectedDayIndex ? 'true' : 'false');
				btn.textContent = String(day.jalali || day.date || '');
				btn.addEventListener('click', function () {
					if (state.busy) {
						return;
					}
					state.selectedDayIndex = idx;
					var siblings = daysNode.querySelectorAll('button[data-role="reschedule-day"]');
					Array.prototype.forEach.call(siblings, function (sib, sibIdx) {
						var active = sibIdx === idx;
						sib.classList.toggle('is-selected', active);
						sib.setAttribute('aria-pressed', active ? 'true' : 'false');
					});
					renderSlotsForDay(idx);
				});
				daysNode.appendChild(btn);
			});
			if (state.days.length > 0) {
				state.selectedDayIndex = 0;
				var firstBtn = daysNode.querySelector('button[data-role="reschedule-day"]');
				if (firstBtn) {
					firstBtn.classList.add('is-selected');
					firstBtn.setAttribute('aria-pressed', 'true');
				}
				renderSlotsForDay(0);
			}
		}

		function loadOptionsForAppointment(appointmentId, triggerBtn, preserveError) {
			var seq = state.requestSeq + 1;
			state.requestSeq = seq;
			state.appointmentId = appointmentId;
			state.triggerBtn = triggerBtn || null;
			state.days = [];
			state.selectedDayIndex = -1;
			state.selectedSlot = null;
			if (preserveError) {
				if (successNode) {
					successNode.textContent = '';
					successNode.hidden = true;
				}
				if (emptyNode) {
					emptyNode.hidden = true;
				}
			} else {
				resetMessages();
			}
			if (pickerNode) { pickerNode.hidden = true; }
			if (summaryNode) { summaryNode.textContent = ''; }
			if (selectedNode) { selectedNode.textContent = ''; }
			if (confirmBtn) { confirmBtn.disabled = true; }
			panel.hidden = false;
			if (loadingNode) { loadingNode.hidden = false; }

			var path = cancelPath(rescheduleOptionsPath, appointmentId);
			requestJson(apiUrl(config.rest_root, path), {
				method: 'GET',
				credentials: 'same-origin',
				headers: {
					'Accept': 'application/json',
					'X-WP-Nonce': config.nonce
				}
			}).then(function (result) {
				if (seq !== state.requestSeq) {
					return;
				}
				if (loadingNode) { loadingNode.hidden = true; }
				if (!result.ok) {
					showRescheduleError(
						serverMessage(result) || 'دریافت زمان‌های آزاد انجام نشد. لطفاً دوباره تلاش کنید.',
						serverCode(result)
					);
					return;
				}
				var payload = result.body && result.body.data ? result.body.data : {};
				var summaryParts = [];
				if (payload.clinician_name) {
					summaryParts.push('پزشک: ' + String(payload.clinician_name));
				}
				if (payload.current_jalali) {
					summaryParts.push('نوبت فعلی: ' + String(payload.current_jalali) + (payload.current_time ? ' ساعت ' + String(payload.current_time) : ''));
				}
				if (payload.reference_code) {
					summaryParts.push('کد رهگیری فعلی: ' + String(payload.reference_code));
				}
				if (summaryNode) {
					summaryNode.textContent = summaryParts.join(' — ');
				}
				state.days = Array.isArray(payload.days) ? payload.days : [];
				if (!state.days.length) {
					if (emptyNode) { emptyNode.hidden = false; }
					return;
				}
				if (pickerNode) { pickerNode.hidden = false; }
				renderDays();
			}).catch(function () {
				if (seq !== state.requestSeq) {
					return;
				}
				if (loadingNode) { loadingNode.hidden = true; }
				showRescheduleError('ارتباط با سرور برقرار نشد. لطفاً دوباره تلاش کنید.', 'NETWORK');
			});
		}

		if (abortBtn) {
			abortBtn.addEventListener('click', function () {
				if (state.busy) {
					return;
				}
				closePanel();
			});
		}

		if (confirmBtn) {
			confirmBtn.addEventListener('click', function () {
				if (state.busy || !state.appointmentId || !state.selectedSlot) {
					return;
				}
				state.busy = true;
				resetMessages();
				setBusy(confirmBtn, true, 'تأیید تغییر نوبت', 'در حال ثبت جابه‌جایی…');
				var path = cancelPath(reschedulePath, state.appointmentId);
				requestJson(apiUrl(config.rest_root, path), {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': config.nonce,
						'Idempotency-Key': generateUuid4()
					},
					body: JSON.stringify({
						slot_date: state.selectedSlot.slot_date,
						slot_time: state.selectedSlot.slot_time,
						slot_id: state.selectedSlot.slot_id
					})
				}).then(function (result) {
					if (!result.ok) {
						state.busy = false;
						setBusy(confirmBtn, false, 'تأیید تغییر نوبت', 'در حال ثبت جابه‌جایی…');
						var code = serverCode(result);
						showRescheduleError(
							serverMessage(result) || 'جابه‌جایی نوبت انجام نشد. لطفاً زمان دیگری را انتخاب کنید یا با مطب تماس بگیرید.',
							code
						);
						if (code === 'CLINIC_SLOT_TAKEN') {
							loadOptionsForAppointment(state.appointmentId, state.triggerBtn, true);
						} else {
							confirmBtn.focus();
						}
						return;
					}
					var data = result.body && result.body.data ? result.body.data : {};
					var newRef = data.reference_code ? (' — کد رهگیری جدید: ' + String(data.reference_code)) : '';
					if (successNode) {
						successNode.textContent = 'نوبت شما با موفقیت جابه‌جا شد' + newRef + '. در حال به‌روزرسانی فهرست…';
						successNode.hidden = false;
					}
					window.setTimeout(function () {
						window.location.reload();
					}, 500);
				}).catch(function () {
					state.busy = false;
					setBusy(confirmBtn, false, 'تأیید تغییر نوبت', 'در حال ثبت جابه‌جایی…');
					showRescheduleError('ارتباط با سرور برقرار نشد. نوبت فعلی شما بدون تغییر باقی مانده است؛ دوباره تلاش کنید.', 'NETWORK');
					confirmBtn.focus();
				});
			});
		}

		Array.prototype.forEach.call(buttons, function (button) {
			button.addEventListener('click', function () {
				var rawId = parseInt(button.getAttribute('data-appointment-id') || '0', 10);
				if (!rawId || rawId <= 0) {
					return;
				}
				loadOptionsForAppointment(rawId, button);
				if (typeof panel.scrollIntoView === 'function') {
					panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
				}
			});
		});
	}

	function init() {
		bindPatientOtpLogin();
		var config = readConfig();
		if (!config) {
			return;
		}
		bind(config);
		bindReschedule(config);
		bindMarkAllRead(config);
		bindProfile(config);
		bindVisits(config);
		bindPrescriptions(config);
		bindFiles(config);
		bindEnrollment(config);
		bindSectionNav();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
