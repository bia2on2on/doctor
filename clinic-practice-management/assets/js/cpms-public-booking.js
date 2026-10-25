/**
 * cpms-public-booking.js — Phase 8 Slice 1 (FR-3.5 / UC-01)
 *
 * رفتارِ «سطح عمومی رزرو» که با shortcode زیر رندر می‌شود:
 *     [cpms_public_booking clinic_id="N"]
 *
 * ============================================================================
 * سه اصلِ حاکم بر این فایل
 * ============================================================================
 *
 *  ۱) سرور authoritative است — مرورگر هیچ سیاستی را پیاده یا تکرار نمی‌کند.
 *     ظرفیت، حداقل فاصلهٔ زمانی (min-lead)، افق نوبت‌دهی و سیاست tenant همه
 *     سمت سرور می‌مانند. این فایل فقط:
 *       - A1 موجود را صدا می‌زند و روزهای آزاد را نمایش می‌دهد؛
 *       - انتخاب کاربر را با همان `slot_id` دقیقِ A1 به A4 موجود می‌فرستد؛
 *       - **verdict** سرور را به یک `data-state` نگاشت می‌کند.
 *     هیچ مقایسهٔ زمانی، هیچ محاسبهٔ ظرفیت و هیچ حدسِ سیاستی این‌جا نیست.
 *
 *  ۲) هیچ بازمحاسبهٔ زمانی در مرورگر انجام نمی‌شود.
 *     برچسب Jalali (`days[].jalali`) و ساعت هر نوبت (`slots[].time`) دقیقاً
 *     همان wall-clock محلیِ Location است که سرور فرستاده و بدون تغییر نمایش
 *     داده می‌شود. هیچ `Date`، `toLocaleString` یا timezone ای این‌جا ساخته
 *     نمی‌شود — چون هر تبدیلِ سمت مرورگر می‌توانست ساعتِ درستِ سرور را خراب
 *     کند (Two-Clock: ذخیره UTC، نمایش محلیِ Location).
 *
 *  ۳) هیچ رشتهٔ فارسی و هیچ markup خامی در JS نیست.
 *     همهٔ متن‌ها و skeletonها سمت سرور رندر/escape شده‌اند: پیامِ هر وضعیت
 *     در `<p data-message="...">` و ساختار day/slot/detail در `<template
 *     data-template="...">`. این فایل فقط `cloneNode()` می‌کند و مقادیرِ
 *     داده‌ای را با `textContent` می‌گذارد (escape خودکار، بدون `innerHTML`).
 *
 * ============================================================================
 * مرزهای صریحِ این Slice (عمداً پیاده نشده‌اند)
 * ============================================================================
 *
 * login · ثبت‌نام · تحویل OTP · نگه‌داشتنِ انتخاب پس از login · hold ·
 * confirm · پورتال بیمار · هر endpoint جدید. دو مسیرِ استفاده‌شده همان
 * A1 و A4 موجودِ عمومی‌اند و از `rest_root` منتشرشده توسط سرور ساخته
 * می‌شوند (هرگز hardcode نشده‌اند).
 *
 * درخواست‌ها عمداً `credentials: 'omit'` هستند: این سطح «پیش از login» است،
 * پس نه cookie ای می‌فرستد و نه به وضعیت ورودِ کاربرِ جاری وابسته است — یعنی
 * برای همه دقیقاً یک رفتارِ آنونیم دارد و سطحِ حملهٔ CSRF هم صفر است
 * (A4 quote فقط read-only است و هیچ جهشی ایجاد نمی‌کند).
 *
 * وابستگی: صفر. بدون framework، بدون build step، بدون CDN، بدون jQuery.
 */

(function () {
	'use strict';

	/* ====================== سلکتورها (همان markerهای سرور) ====================== */

	var ROOT_SELECTOR = '.cpms-public-booking';
	var CONFIG_SELECTOR = '.cpms-public-booking__config';
	var CLINICIAN_SELECTOR = '.cpms-public-booking__clinician';
	var SLOT_SELECTOR = '.cpms-public-booking__slot';

	var ROLE_PANEL = '[data-role="panel"]';
	var ROLE_DAYS = '[data-role="days"]';
	var ROLE_DETAIL = '[data-role="detail"]';
	var ROLE_LOCATION_PICKER = '[data-role="location-picker"]';
	var ROLE_LOCATION_OPTIONS = '[data-role="location-options"]';
	var ROLE_LOCATION_OPTION = '[data-role="location-option"]';

	/* ====================== واژگانِ وضعیت (منتشرشده توسط سرور) ====================== */

	var STATE_IDLE = 'idle';
	var STATE_LOADING = 'loading';
	var STATE_SELECTABLE = 'selectable';
	var STATE_LOCATION_REQUIRED = 'location_required';
	var STATE_BOOKABLE = 'bookable';
	var STATE_POLICY_REJECTED = 'policy_rejected';
	var STATE_UNAVAILABLE = 'unavailable';
	var STATE_EMPTY = 'empty';
	var STATE_ERROR = 'error';

	/**
	 * کدِ خطای **موجود و پایدارِ** سرور برای نقضِ سیاستِ زمان‌بندی
	 * (BookingWindow → CLINIC_POLICY_VIOLATION، HTTP 409).
	 *
	 * مرورگر این کد را فقط «نگاشت» می‌کند؛ خودِ سیاست (min-lead و تقویم محلیِ
	 * Location) کاملاً سمت سرور ارزیابی می‌شود.
	 */
	var CODE_POLICY_VIOLATION = 'CLINIC_POLICY_VIOLATION';

	/* ====================== ابزارهای کوچک ====================== */

	function toInt(value) {
		var n = parseInt(value, 10);
		return isNaN(n) ? 0 : n;
	}

	function isNonEmptyString(value) {
		return typeof value === 'string' && value !== '';
	}

	/** خواندنِ ایمنِ JSONِ منتشرشده توسط سرور؛ در هر شکست `null`. */
	function readConfig(root) {
		var element = root.querySelector(CONFIG_SELECTOR);
		if (!element) {
			return null;
		}

		var parsed;
		try {
			parsed = JSON.parse(element.textContent || '');
		} catch (e) {
			return null;
		}

		if (!parsed || typeof parsed !== 'object') {
			return null;
		}

		// قراردادِ D-5: ریشهٔ REST موجود + دو مسیرِ موجودِ A1/A4 + Clinicِ
		// پیوندشده. اگر هرکدام نباشد، سطح «تعاملی» ساخته نمی‌شود (fail-closed).
		if (!isNonEmptyString(parsed.rest_root)) {
			return null;
		}
		if (!isNonEmptyString(parsed.availability_path) || !isNonEmptyString(parsed.quote_path)) {
			return null;
		}
		if (toInt(parsed.clinic_id) <= 0) {
			return null;
		}

		return parsed;
	}

	/**
	 * کلونِ یک `<template data-template="...">` سمت سرور.
	 *
	 * کلِ fragment کلون می‌شود و سپس نخستین عنصرِ آن برداشته می‌شود — الگوی
	 * استاندارد و امنِ template در مرورگر.
	 */
	function templateNode(root, name) {
		var template = root.querySelector('template[data-template="' + name + '"]');
		if (!template || !template.content) {
			return null;
		}

		var clone = template.content.cloneNode(true);
		return clone.firstElementChild || null;
	}

	/**
	 * جای‌گذاریِ متن با `textContent` — escape خودکار، بدون `innerHTML`.
	 *
	 * `matches()` هم بررسی می‌شود چون در بعضی قالب‌ها (detail-server) خودِ
	 * گرهِ کلون‌شده حامل `data-fill` است و `querySelector` فقط نوادگان را
	 * می‌بیند؛ بدون این بررسی، آن مقدار هرگز پر نمی‌شد.
	 */
	function fill(node, key, value) {
		if (!node) {
			return;
		}

		var selector = '[data-fill="' + key + '"]';
		var target = (typeof node.matches === 'function' && node.matches(selector))
			? node
			: node.querySelector(selector);

		if (target) {
			target.textContent = String(value);
		}
	}

	function setText(node, value) {
		if (node) {
			node.textContent = String(value);
		}
	}

	function setPressed(nodes, active) {
		for (var i = 0; i < nodes.length; i++) {
			nodes[i].setAttribute('aria-pressed', nodes[i] === active ? 'true' : 'false');
		}
	}

	function setDisabled(nodes, disabled) {
		for (var i = 0; i < nodes.length; i++) {
			nodes[i].disabled = !!disabled;
		}
	}

	/**
	 * ساختِ URLِ REST سازگار با **هر دو** حالتِ permalink — همان الگوی
	 * `apiUrl()` صفحاتِ مدیریتی (DoctorDashboardPage / SecretaryQueuePage / …).
	 *
	 * در Permalink ساده (Plain)، `rest_url('clinic/v1')` خودش شامل
	 * `index.php?rest_route=/clinic/v1` است؛ پس queryِ مسیر باید با `&` ضمیمه
	 * شود، نه `?`. با `?` دوم، `clinician_id` بخشی از **مقدارِ** `rest_route`
	 * می‌شود و وردپرس route را `/clinic/v1/availability?clinician_id=N`
	 * می‌بیند ⇒ هیچ route مطابقت نمی‌کند (`rest_no_route`) و A1 در یک وردپرسِ
	 * کاملاً پشتیبانی‌شده غیرقابل‌دسترس می‌شود.
	 *
	 * در Permalink زیبا ریشه `?` ندارد، پس همان `?` درست است. وردپرس
	 * `rest_route` را به‌عنوان query var عمومی ثبت می‌کند و بقیهٔ `$_GET` را با
	 * `set_query_params()` به Request می‌دهد؛ بنابراین در هر دو حالت دقیقاً
	 * route `/clinic/v1/availability` با پارامتر `clinician_id` دریافت می‌شود.
	 *
	 * `path` می‌تواند خودش query داشته باشد؛ فقط **نخستین** `?` آن به `&`
	 * تبدیل می‌شود (دقیقاً مثل الگوی موجود) تا `&`های بعدی دست‌نخورده بمانند.
	 * هیچ URL استقرارِ مشخصی hardcode نمی‌شود: ریشه همان چیزی است که سرور
	 * منتشر کرده است.
	 */
	function apiUrl(restRoot, path) {
		if (restRoot.indexOf('?') !== -1 && path.indexOf('?') !== -1) {
			return restRoot + path.replace('?', '&');
		}

		return restRoot + path;
	}

	/**
	 * فراخوانیِ JSON — همهٔ شکست‌ها (شبکه، JSON نامعتبر، HTTP غیرموفق) به یک
	 * شکلِ واحد `{ok, status, body}` تبدیل می‌شوند تا هیچ‌کدام به کنسول نشت
	 * نکند و نگاشتِ وضعیت قطعی بماند.
	 */
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

	/* ============ Phase 8 Slice 2 — ابزارهای ادامهٔ احراز/رزرو ============ */

	/**
	 * انتخابِ غیر-PHI برای عبور از مرزِ login (فقط شناسهٔ پزشک/نوبت و
	 * تاریخ/ساعتِ همان چیزی که خودِ سرور برگردانده) — در sessionStorage
	 * هم‌مبدأ؛ هیچ PHI و هیچ token در آن نیست.
	 */
	var SELECT_KEY = 'cpms-public-booking:selection:v1';

	function validSelection(value) {
		if (!value || typeof value !== 'object') {
			return null;
		}
		if (toInt(value.clinician_id) <= 0 || !isNonEmptyString(value.slot_date) || !isNonEmptyString(value.slot_time)) {
			return null;
		}
		return value;
	}

	/**
	 * Phase 8 Slice 4 — per-surface storage key.
	 *
	 * Two booking widgets on one page must never share a preserved selection
	 * (that would let one widget's Clinic/slot leak into another widget's OTP or
	 * hold). The key is therefore derived from the surface id the SERVER minted
	 * (`wp_unique_id`), falling back to the Clinic id for older markup.
	 */
	function selectionKeyFor(surfaceId, clinicId) {
		var suffix = isNonEmptyString(surfaceId) ? surfaceId : ('clinic-' + String(toInt(clinicId)));
		return SELECT_KEY + ':' + suffix;
	}

	function readStoredSelectionFor(key) {
		try {
			return validSelection(JSON.parse(window.sessionStorage.getItem(key || SELECT_KEY) || 'null'));
		} catch (e) {
			return null;
		}
	}

	function storeSelectionFor(selection, key) {
		try {
			if (validSelection(selection)) {
				window.sessionStorage.setItem(key || SELECT_KEY, JSON.stringify(selection));
			}
		} catch (e) {
			// بدون storage هم جریان ادامه می‌یابد — کاربر دوباره انتخاب می‌کند.
		}
	}

	function clearStoredSelectionFor(key) {
		try {
			window.sessionStorage.removeItem(key || SELECT_KEY);
		} catch (e) {
			// بی‌اثر.
		}
	}

	/** UUID برای هدر Idempotency-Keyِ confirm (قرارداد موجود B2). */
	function idempotencyKey() {
		if (window.crypto && typeof window.crypto.randomUUID === 'function') {
			return window.crypto.randomUUID();
		}
		return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
			var r = (Math.random() * 16) | 0;
			var v = c === 'x' ? r : ((r & 0x3) | 0x8);
			return v.toString(16);
		});
	}

	/* ====================== یک نمونهٔ سطح ====================== */

	/**
	 * راه‌اندازیِ یک نمونهٔ سطح. هر نمونه config/panel/قالب‌های خودش را دارد،
	 * پس چند shortcode در یک صفحه مستقل کار می‌کنند.
	 */
	function bootSurface(root) {
		// سطحِ بسته (fail-closed) هیچ قراردادی منتشر نمی‌کند ⇒ هیچ درخواستی.
		var config = readConfig(root);
		if (!config) {
			return;
		}

		// قابلیت‌های لازمِ مرورگر؛ در نبودِ آن‌ها وضعیتِ روشنِ خطا نشان داده
		// می‌شود (نه صفحهٔ بی‌پاسخ و نه خطای کنسول).
		if (typeof window.fetch !== 'function' || typeof window.Promise !== 'function') {
			var fallbackPanel = root.querySelector(ROLE_PANEL);
			if (fallbackPanel) {
				fallbackPanel.setAttribute('data-state', STATE_ERROR);
			}
			return;
		}

		var panel = root.querySelector(ROLE_PANEL);
		var daysHost = root.querySelector(ROLE_DAYS);
		var detailHost = root.querySelector(ROLE_DETAIL);
		var clinicianButtons = root.querySelectorAll(CLINICIAN_SELECTOR);
		var locationPicker = root.querySelector(ROLE_LOCATION_PICKER);
		var locationList = locationPicker ? locationPicker.querySelector(ROLE_LOCATION_OPTIONS) : null;
		var locationDetails = locationPicker ? locationPicker.querySelector('[data-role="location-details"]') : null;
		var locationName = locationPicker ? locationPicker.querySelector('[data-role="location-name"]') : null;
		var locationAddress = locationPicker ? locationPicker.querySelector('[data-role="location-address"]') : null;
		var locationAddressRow = locationPicker ? locationPicker.querySelector('[data-role="location-address-row"]') : null;
		var locationHint = locationPicker ? locationPicker.querySelector('[data-role="location-hint"]') : null;

		if (!panel || !daysHost) {
			return;
		}

		var busy = false;
		var selectedClinicianId = 0;
		var selectedLocationId = 0;
		// Per-surface (not module-global) selection storage: the key embeds the
		// server-minted surface id, so two widgets on one page never share state.
		var selectKey = selectionKeyFor(config.surface_id, config.clinic_id);
		function readStoredSelection() { return readStoredSelectionFor(selectKey); }
		function storeSelection(selection) { storeSelectionFor(selection, selectKey); }
		function clearStoredSelection() { clearStoredSelectionFor(selectKey); }

		var availableDays = [];
		var weekOffset = 0;
		var calendarScaffold = Array.isArray(config.calendar) ? config.calendar : [];
		var activeClinicId = toInt(root.getAttribute('data-clinic-id'));
		var clinicSelect = root.querySelector('[data-role="clinic-select"]');
		var weekLabel = root.querySelector('[data-role="week-label"]');
		var prevWeekBtn = root.querySelector('[data-role="prev-week"]');
		var nextWeekBtn = root.querySelector('[data-role="next-week"]');
		var firstAvailableBtn = root.querySelector('[data-role="first-available-week"]');
		var weekEmptyNotice = root.querySelector('[data-role="week-empty"]');
		var timezoneNode = root.querySelector('[data-role="clinic-timezone"]');
		var doctorSearch = root.querySelector('[data-role="doctor-search"]');
		var specialtyFilter = root.querySelector('[data-role="specialty-filter"]');
		var doctorReset = root.querySelector('[data-role="doctor-reset"]');
		var filterEmpty = root.querySelector('[data-role="filter-empty"]');

		/**
		 * Clinicِ هر پزشک — از `data-clinic-id` همان دکمه (سرور آن را نوشته),
		 * و در حالت تک‌Clinic از ریشه. هرگز حدس زده نمی‌شود.
		 */
		function clinicianClinicId(node) {
			var own = node ? toInt(node.getAttribute('data-clinic-id')) : 0;
			return own > 0 ? own : activeClinicId;
		}

		function currentClinicId() {
			return activeClinicId > 0 ? activeClinicId : 0;
		}

		/** افزودنِ `clinic_id` انتخاب‌شده به هر درخواستِ رزرو (وقتی معلوم است). */
		function withClinic(payload) {
			var clinicId = currentClinicId();
			if (clinicId > 0) { payload.clinic_id = clinicId; }
			return payload;
		}
		var availableLocations = [];

		/* -------------- Phase 8 Slice 2 — وضعیت continuation -------------- */
		/* فقط از قراردادِ منتشرشدهٔ سرور خوانده می‌شود؛ هیچ حدسی در کار نیست:
		 *  - anonymous: config حامل otp_request_path/otp_verify_path است؛
		 *  - patient: config حامل nonce + hold_path/confirm_path است؛
		 *  - none: هیچ continuation بیماری منتشر نشده. */
		var authMode = 'none';
		if (isNonEmptyString(config.otp_request_path) && isNonEmptyString(config.otp_verify_path)) {
			authMode = 'anonymous';
		} else if (isNonEmptyString(config.nonce) && isNonEmptyString(config.hold_path) && isNonEmptyString(config.confirm_path)) {
			authMode = 'patient';
		}

		var continueBox = root.querySelector('[data-role="booking-continue"]');
		var nearbyWrap = root.querySelector('[data-role="nearby"]');
		var nearbyList = root.querySelector('[data-role="nearby-list"]');
		var lastSelection = null;
		var holdToken = '';
		var countdownTimer = 0;

		/* ---------- Phase 8 Slice 3 — patient chooser (N>1) ---------- */
		var selectedPatientId = 0;
		var patientChooser = root.querySelector('[data-role="patient-chooser"]');
		var patientOptions = patientChooser ? patientChooser.querySelectorAll('[data-role="patient-option"]') : [];
		var PATIENT_STORAGE_KEY = 'cpms-patient-selection:' + String(config.surface_id || config.clinic_id);
		function getStoredPatientId() {
			try {
				var v = window.sessionStorage.getItem(PATIENT_STORAGE_KEY);
				return toInt(v);
			} catch (e) {
				return 0;
			}
		}
		function storePatientId(id) {
			try {
				if (toInt(id) > 0) {
					window.sessionStorage.setItem(PATIENT_STORAGE_KEY, String(toInt(id)));
				}
			} catch (e) {}
		}
		function clearStoredPatientId() {
			try {
				window.sessionStorage.removeItem(PATIENT_STORAGE_KEY);
			} catch (e) {}
		}
		function isPatientOptionValid(id) {
			var nid = toInt(id);
			if (nid <= 0 || !patientChooser || patientOptions.length === 0) {
				return false;
			}
			for (var _v = 0; _v < patientOptions.length; _v++) {
				if (toInt(patientOptions[_v].getAttribute('data-patient-id')) === nid) {
					return true;
				}
			}
			return false;
		}
		function getValidatedStoredPatientId() {
			var pid = getStoredPatientId();
			if (pid <= 0) {
				return 0;
			}
			if (!isPatientOptionValid(pid)) {
				clearStoredPatientId();
				return 0;
			}
			return pid;
		}
		function getPatientIdForB1() {
			// Gate: patient_id may be sent ONLY IF N>1 chooser exists, has options, and id is among current rendered options.
			if (!patientChooser || patientOptions.length === 0) {
				// 0 or 1 linked: no chooser — clear stale and do not send.
				if (getStoredPatientId() > 0) {
					clearStoredPatientId();
				}
				selectedPatientId = 0;
				return 0;
			}
			// N>1: validate selected and stored against current options.
			var cand = selectedPatientId;
			if (cand > 0 && isPatientOptionValid(cand)) {
				return cand;
			}
			if (cand > 0 && !isPatientOptionValid(cand)) {
				// Stale selected (not in current render) — clear.
				selectedPatientId = 0;
				clearStoredPatientId();
			}
			var stored = getValidatedStoredPatientId();
			if (stored > 0) {
				// Sync selected to validated stored for UI consistency.
				selectedPatientId = stored;
				for (var _si = 0; _si < patientOptions.length; _si++) {
					var optSi = patientOptions[_si];
					var pidSi = toInt(optSi.getAttribute('data-patient-id'));
					optSi.setAttribute('aria-pressed', pidSi === stored ? 'true' : 'false');
				}
				return stored;
			}
			// No valid selected/stored — check DOM aria-pressed but only if valid.
			for (var _ap = 0; _ap < patientOptions.length; _ap++) {
				if (patientOptions[_ap].getAttribute('aria-pressed') === 'true') {
					var domPid = toInt(patientOptions[_ap].getAttribute('data-patient-id'));
					if (isPatientOptionValid(domPid)) {
						selectedPatientId = domPid;
						storePatientId(domPid);
						return domPid;
					}
				}
			}
			return 0;
		}
		function setPatientSelection(id) {
			var nid = toInt(id);
			if (!isPatientOptionValid(nid)) {
				// Never store or select an id not in current options — clear stale.
				selectedPatientId = 0;
				clearStoredPatientId();
				for (var _clr = 0; _clr < patientOptions.length; _clr++) {
					patientOptions[_clr].setAttribute('aria-pressed', 'false');
				}
				return;
			}
			selectedPatientId = nid;
			storePatientId(selectedPatientId);
			for (var _i = 0; _i < patientOptions.length; _i++) {
				var opt = patientOptions[_i];
				var pid = toInt(opt.getAttribute('data-patient-id'));
				opt.setAttribute('aria-pressed', pid === selectedPatientId && selectedPatientId > 0 ? 'true' : 'false');
			}
		}
		// Initialization: handle storage lifetime and chooser presence.
		if (!patientChooser || patientOptions.length === 0) {
			// 0 or 1 linked: no chooser — clear any stale patient selection from same tab.
			if (getStoredPatientId() > 0) {
				clearStoredPatientId();
			}
			selectedPatientId = 0;
		} else if (patientOptions.length > 0) {
			// N>1: restore only if validated against current options; otherwise clear.
			var storedPid = getValidatedStoredPatientId();
			if (storedPid > 0) {
				setPatientSelection(storedPid);
			} else if (getStoredPatientId() > 0) {
				// getValidated already cleared if invalid, but handle edge.
				clearStoredPatientId();
			}
			// Also respect DOM pre-selected (aria-pressed) if any and valid, but do not auto-pick first row.
			var hasPressed = false;
			for (var _k = 0; _k < patientOptions.length; _k++) {
				if (patientOptions[_k].getAttribute('aria-pressed') === 'true') {
					hasPressed = true;
					break;
				}
			}
			if (hasPressed) {
				for (var _k2 = 0; _k2 < patientOptions.length; _k2++) {
					if (patientOptions[_k2].getAttribute('aria-pressed') === 'true') {
						var domPid2 = toInt(patientOptions[_k2].getAttribute('data-patient-id'));
						if (isPatientOptionValid(domPid2)) {
							setPatientSelection(domPid2);
						}
						break;
					}
				}
			}
		}

		function panelState(state) {
			panel.setAttribute('data-state', state);
		}

		function setBusy(value) {
			busy = !!value;
			panel.setAttribute('aria-busy', busy ? 'true' : 'false');
		}

		function slotButtons() {
			return daysHost.querySelectorAll(SLOT_SELECTOR);
		}

		function freeze() {
			setDisabled(clinicianButtons, true);
			setDisabled(slotButtons(), true);
			setDisabled(locationOptionButtons(), true);
		}

		function unfreeze() {
			setDisabled(clinicianButtons, false);
			setDisabled(slotButtons(), false);
			setDisabled(locationOptionButtons(), holdToken !== '');
		}

		function clearDetail() {
			if (!detailHost) {
				return;
			}
			setText(detailHost, '');
			detailHost.hidden = true;
		}

		function showDetail(templateName, fills) {
			if (!detailHost) {
				return;
			}
			var node = templateNode(root, templateName);
			if (!node) {
				clearDetail();
				return;
			}
			var keys = Object.keys(fills);
			for (var i = 0; i < keys.length; i++) {
				fill(node, keys[i], fills[keys[i]]);
			}
			setText(detailHost, '');
			detailHost.appendChild(node);
			detailHost.hidden = false;
		}

		/** پیامِ خودِ سرور از envelope پاسخ (بدون هیچ تفسیرِ سمت مرورگر). */
		function serverMessage(result) {
			if (result && result.body && isNonEmptyString(result.body.message)) {
				return result.body.message;
			}
			return '';
		}

		function showServerDetail(result) {
			var message = serverMessage(result);
			if (message === '') {
				clearDetail();
				return;
			}
			showDetail('detail-server', { message: message });
		}

		function collectLocations(days) {
			var locationsById = Object.create(null);
			for (var i = 0; i < days.length; i++) {
				var slots = days[i] && Array.isArray(days[i].slots) ? days[i].slots : [];
				for (var j = 0; j < slots.length; j++) {
					var slot = slots[j];
					var id = toInt(slot && slot.location_id);
					var name = slot && isNonEmptyString(slot.location_name) ? slot.location_name : '';
					if (id <= 0 || name === '') { continue; }
					if (!locationsById[id]) {
						locationsById[id] = {
							id: id,
							name: name,
							address: slot && isNonEmptyString(slot.location_address) ? slot.location_address : ''
						};
					}
				}
			}
			return Object.keys(locationsById).map(function (id) { return locationsById[id]; });
		}

		function locationOptionButtons() {
			return locationList ? locationList.querySelectorAll(ROLE_LOCATION_OPTION) : [];
		}

		function findAvailableLocation(locationId) {
			for (var i = 0; i < availableLocations.length; i++) {
				if (toInt(availableLocations[i].id) === toInt(locationId)) { return availableLocations[i]; }
			}
			return null;
		}

		function renderLocationOptions(locations) {
			if (!locationList) { return; }
			while (locationList.firstChild) { locationList.removeChild(locationList.firstChild); }
			for (var i = 0; i < locations.length; i++) {
				var location = locations[i];
				var node = templateNode(root, 'location-option');
				if (!node || !location || toInt(location.id) <= 0) { continue; }
				var button = node.querySelector(ROLE_LOCATION_OPTION);
				if (!button) { continue; }
				button.setAttribute('data-location-id', String(toInt(location.id)));
				fill(node, 'name', location.name);
				fill(node, 'address', location.address || '');
				var addressNode = node.querySelector('[data-fill="address"]');
				if (addressNode) { addressNode.hidden = !isNonEmptyString(location.address); }
				locationList.appendChild(node);
			}
		}

		function showLocationDetails(location) {
			if (!locationDetails) { return; }
			if (!location) {
				locationDetails.hidden = true;
				return;
			}
			if (locationName) { locationName.textContent = String(location.name || ''); }
			if (locationAddress) { locationAddress.textContent = String(location.address || ''); }
			if (locationAddressRow) { locationAddressRow.hidden = !isNonEmptyString(location.address); }
			locationDetails.hidden = false;
		}

		function filterDaysForLocation(days, locationId) {
			var filtered = [];
			for (var i = 0; i < days.length; i++) {
				var day = days[i];
				var slots = day && Array.isArray(day.slots) ? day.slots : [];
				var matching = slots.filter(function (slot) {
					return toInt(slot && slot.location_id) === toInt(locationId);
				});
				if (matching.length > 0) {
					filtered.push({ date: day.date, jalali: day.jalali, slots: matching });
				}
			}
			return filtered;
		}

		function setLocationChoice(locationId, invalidateSelection) {
			var location = findAvailableLocation(locationId);
			if (!location || !locationList || !locationPicker) { return false; }
			selectedLocationId = toInt(location.id);
			var buttons = locationOptionButtons();
			for (var i = 0; i < buttons.length; i++) {
				buttons[i].setAttribute('aria-pressed', toInt(buttons[i].getAttribute('data-location-id')) === selectedLocationId ? 'true' : 'false');
			}
			showLocationDetails(location);
			if (locationHint) { locationHint.hidden = true; }

			if (invalidateSelection !== false) {
				lastSelection = null;
				clearStoredSelection();
				holdToken = '';
				stopCountdown();
				if (continueBox) { continueBox.hidden = true; }
				var confirmButton = root.querySelector('[data-role="confirm-btn"]');
				var namesForm = root.querySelector('[data-role="names-form"]');
				var reasonWrap = root.querySelector('[data-role="reason-wrap"]');
				var receipt = root.querySelector('[data-role="receipt"]');
				if (confirmButton) { confirmButton.hidden = true; }
				if (namesForm) { namesForm.hidden = true; }
				if (reasonWrap) { reasonWrap.hidden = true; }
				if (receipt) { receipt.hidden = true; }
				hideNearby();
			}

			setText(daysHost, '');
			clearDetail();
			var count = renderDays(filterDaysForLocation(availableDays, selectedLocationId));
			panelState(count > 0 ? STATE_SELECTABLE : STATE_EMPTY);
			return true;
		}

		/* ---------------- A1: مرورِ نوبت‌های آزاد (مسیرِ موجود) ---------------- */

		function buildDay(day) {
			if (!day || typeof day !== 'object') {
				return null;
			}

			var slots = Array.isArray(day.slots) ? day.slots : [];
			if (slots.length === 0) {
				return null;
			}

			var dayNode = templateNode(root, 'day');
			if (!dayNode) {
				return null;
			}

			// برچسب Jalali **همان‌طور که سرور فرستاده** — بدون هیچ تبدیلی.
			var label = isNonEmptyString(day.jalali) ? day.jalali : (isNonEmptyString(day.date) ? day.date : '');
			fill(dayNode, 'jalali', label);

			var slotsHost = dayNode.querySelector('[data-host="slots"]');
			var rendered = 0;

			for (var i = 0; i < slots.length; i++) {
				var slotNode = buildSlot(day, slots[i]);
				if (slotNode && slotsHost) {
					slotsHost.appendChild(slotNode);
					rendered++;
				}
			}

			return rendered > 0 ? dayNode : null;
		}

		function buildSlot(day, slot) {
			if (!slot || typeof slot !== 'object') {
				return null;
			}

			var slotDate = isNonEmptyString(day.date) ? day.date : '';
			var slotTime = isNonEmptyString(slot.time) ? slot.time : '';
			var locationId = toInt(slot.location_id);
			if (slotDate === '' || slotTime === '' || locationId <= 0 || !findAvailableLocation(locationId)) {
				return null;
			}

			var node = templateNode(root, 'slot');
			if (!node) {
				return null;
			}

			// هویتِ دقیقِ اسلات از A1 — همان مقداری که عیناً به A4 برمی‌گردد.
			node.setAttribute('data-slot-id', String(toInt(slot.slot_id)));
			node.setAttribute('data-location-id', String(locationId));
			node.setAttribute('data-slot-date', slotDate);
			node.setAttribute('data-slot-time', slotTime);
			node.setAttribute('aria-pressed', 'false');

			// ساعتِ wall-clock محلیِ Location — بدون بازمحاسبه در مرورگر.
			fill(node, 'time', slotTime);
			fill(node, 'duration', toInt(slot.duration_min));
			fill(node, 'capacity', toInt(slot.capacity_left));

			return node;
		}

		/**
		 * رندرِ تقویم: اگر سرور اسکلتِ Jalali (`config.calendar`) را منتشر کرده
		 * باشد، هفتهٔ جاری (۷ روز) نمایش داده می‌شود و روزهای بدونِ نوبت هم
		 * دیده می‌شوند. بدونِ اسکلت، رفتارِ پیشین (فقط روزهای دارای نوبت)
		 * عیناً حفظ می‌شود.
		 */
		function renderDays(days) {
			setText(daysHost, '');

			var slotCount = 0;

			if (calendarScaffold.length === 0) {
				for (var plain = 0; plain < days.length; plain++) {
					var plainNode = buildDay(days[plain]);
					if (plainNode) {
						daysHost.appendChild(plainNode);
						slotCount += plainNode.querySelectorAll(SLOT_SELECTOR).length;
					}
				}
				updateWeekChrome(slotCount);
				return slotCount;
			}

			var byDate = {};
			for (var d = 0; d < days.length; d++) {
				if (days[d] && isNonEmptyString(days[d].date)) {
					byDate[days[d].date] = days[d];
				}
			}

			var start = weekStartIndex(weekOffset, calendarScaffold.length);
			var last = Math.min(start + 7, calendarScaffold.length);

			for (var i = start; i < last; i++) {
				var entry = calendarScaffold[i];
				if (!entry || !isNonEmptyString(entry.date)) { continue; }
				var cell = templateNode(root, 'week-day');
				if (!cell) { continue; }
				fill(cell, 'weekday', isNonEmptyString(entry.weekday) ? entry.weekday : '');
				fill(cell, 'jalali', isNonEmptyString(entry.jalali) ? entry.jalali : entry.date);

				var dayData = byDate[entry.date] || null;
				var slotsHost = cell.querySelector('[data-host="slots"]');
				var rendered = 0;
				var slotRows = dayData && Array.isArray(dayData.slots) ? dayData.slots : [];
				for (var k = 0; k < slotRows.length; k++) {
					var slotNode = buildSlot(dayData, slotRows[k]);
					if (slotNode && slotsHost) {
						slotsHost.appendChild(slotNode);
						rendered++;
					}
				}
				cell.setAttribute('data-date', entry.date);
				if (rendered === 0) {
					cell.setAttribute('data-empty', 'true');
				}
				daysHost.appendChild(cell);
				slotCount += rendered;
			}

			updateWeekChrome(slotCount);
			return slotCount;
		}

		/** برچسب/کرانِ هفته + پیامِ هفتهٔ خالی. */
		function updateWeekChrome(slotCount) {
			var total = calendarScaffold.length;
			if (weekLabel) {
				var first = calendarScaffold[weekStartIndex(weekOffset, total)];
				var lastIndex = Math.min(weekStartIndex(weekOffset, total) + 6, total - 1);
				var last = calendarScaffold[lastIndex];
				if (first && last) {
					weekLabel.textContent = (isNonEmptyString(first.jalali) ? first.jalali : first.date)
						+ ' — ' + (isNonEmptyString(last.jalali) ? last.jalali : last.date);
				} else {
					weekLabel.textContent = '';
				}
			}
			if (prevWeekBtn) {
				prevWeekBtn.disabled = !weekOffsetInBounds(weekOffset - 1, total);
			}
			if (nextWeekBtn) {
				nextWeekBtn.disabled = !weekOffsetInBounds(weekOffset + 1, total);
			}
			if (firstAvailableBtn) {
				var target = firstAvailableIndex(calendarScaffold, availableDays);
				firstAvailableBtn.hidden = target < 0 || Math.floor(target / 7) === weekOffset;
			}
			if (weekEmptyNotice) {
				weekEmptyNotice.hidden = !(calendarScaffold.length > 0 && slotCount === 0 && selectedClinicianId > 0);
			}
			return slotCount;
		}

		function gotoWeek(offset) {
			if (!weekOffsetInBounds(offset, calendarScaffold.length)) { return; }
			weekOffset = offset;
			var count = renderDays(filterDaysForLocation(availableDays, selectedLocationId));
			if (count === 0) {
				showDetail('week-empty-detail', {});
			}
			return count;
		}

		/** فیلترِ پزشکان: Clinicِ فعال + عبارتِ نام + تخصص. */
		function applyDoctorFilters() {
			var query = doctorSearch ? normalizeSearchText(doctorSearch.value || '') : '';
			var specialty = specialtyFilter ? String(specialtyFilter.value || '') : '';
			var items = root.querySelectorAll('[data-role="clinicians"] .cpms-public-booking__clinician-item');
			var visible = 0;
			for (var i = 0; i < items.length; i++) {
				var item = items[i];
				var clinicMatch = activeClinicId <= 0 || toInt(item.getAttribute('data-clinic-id')) === activeClinicId;
				var haystack = String(item.getAttribute('data-search') || '');
				var nameMatch = query === '' || haystack.indexOf(query) !== -1;
				var specialtyMatch = specialty === '' || String(item.getAttribute('data-specialty') || '') === specialty;
				var show = clinicMatch && nameMatch && specialtyMatch;
				item.hidden = !show;
				if (show) { visible++; }
			}
			if (filterEmpty) {
				filterEmpty.hidden = visible > 0 || activeClinicId <= 0;
			}
			return visible;
		}

		function loadAvailability(clinicianId, restoreSelection) {
			clearDetail();
			setText(daysHost, '');
			availableDays = [];
			availableLocations = [];
			selectedLocationId = 0;
			if (locationPicker) { locationPicker.hidden = true; }
			if (locationList) { setText(locationList, ''); }
			showLocationDetails(null);
			if (locationHint) { locationHint.hidden = true; }
			if (!restoreSelection) {
				lastSelection = null;
				clearStoredSelection();
			}
			panelState(STATE_LOADING);
			setBusy(true);
			setDisabled(clinicianButtons, true);

			// A1 — joinِ سازگار با permalink (Plain: `&`، Pretty: `?`). مسیر و
			// ریشه هر دو از قراردادِ منتشرشدهٔ سرور می‌آیند؛ هیچ hardcode ای نیست.
			var availabilityQuery = config.availability_path + '?clinician_id=' + encodeURIComponent(String(clinicianId));
			if (currentClinicId() > 0) {
				availabilityQuery += '&clinic_id=' + encodeURIComponent(String(currentClinicId()));
			}
			var url = apiUrl(config.rest_root, availabilityQuery);

			requestJson(url, {
				method: 'GET',
				credentials: 'omit',
				headers: { Accept: 'application/json' }
			}).then(function (result) {
				setBusy(false);
				setDisabled(clinicianButtons, false);

				var payload = result.body && result.body.data ? result.body.data : null;
				var days = payload && Array.isArray(payload.days) ? payload.days : null;

				if (!result.ok || days === null) {
					panelState(STATE_ERROR);
					showServerDetail(result);
					return;
				}

				availableDays = days;
				availableLocations = collectLocations(days);
				renderLocationOptions(availableLocations);
				if (availableLocations.length === 0) {
					panelState(STATE_EMPTY);
					clearDetail();
					return;
				}
				if (locationPicker) { locationPicker.hidden = false; }

				if (restoreSelection) {
					var matches = [];
					for (var dayIndex = 0; dayIndex < days.length; dayIndex++) {
						var slotRows = Array.isArray(days[dayIndex].slots) ? days[dayIndex].slots : [];
						for (var slotIndex = 0; slotIndex < slotRows.length; slotIndex++) {
							var candidate = slotRows[slotIndex];
							var exactId = toInt(restoreSelection.slot_id);
							var sameTime = days[dayIndex].date === restoreSelection.slot_date && candidate.time === restoreSelection.slot_time;
							var sameId = exactId > 0 && toInt(candidate.slot_id) === exactId;
							if ((sameId && sameTime) || (exactId <= 0 && sameTime)) { matches.push(candidate); }
						}
					}
					if (matches.length !== 1) {
						clearStoredSelection();
						panelState(STATE_EMPTY);
						return;
					}
					var restoredLocationId = toInt(matches[0].location_id);
					if (toInt(restoreSelection.location_id) > 0 && toInt(restoreSelection.location_id) !== restoredLocationId) {
						clearStoredSelection();
						panelState(STATE_ERROR);
						return;
					}
					if (!setLocationChoice(restoredLocationId, false)) {
						clearStoredSelection();
						panelState(STATE_ERROR);
						return;
					}
					var buttons = slotButtons();
					for (var buttonIndex = 0; buttonIndex < buttons.length; buttonIndex++) {
						if (
							toInt(buttons[buttonIndex].getAttribute('data-slot-id')) === toInt(matches[0].slot_id)
							&& buttons[buttonIndex].getAttribute('data-slot-date') === restoreSelection.slot_date
							&& buttons[buttonIndex].getAttribute('data-slot-time') === restoreSelection.slot_time
						) {
							quoteSlot(buttons[buttonIndex]);
							return;
						}
					}
					clearStoredSelection();
					panelState(STATE_EMPTY);
					return;
				}

				if (availableLocations.length === 1) {
					setLocationChoice(availableLocations[0].id, false);
					return;
				}
				if (availableLocations.length > 1) {
					if (locationHint) { locationHint.hidden = false; }
					panelState(STATE_LOCATION_REQUIRED);
					clearDetail();
					return;
				}

				panelState(STATE_EMPTY);
				clearDetail();
			}, function () {
				setBusy(false);
				setDisabled(clinicianButtons, false);
				panelState(STATE_ERROR);
				clearDetail();
			});
		}

		/* ---------------- A4: سنجشِ انتخاب (مسیرِ موجود) ---------------- */

		function applyVerdict(result) {
			var payload = result.body && result.body.data ? result.body.data : null;

			if (result.ok && payload && payload.available === true) {
				var quotedLocationId = toInt(payload.location_id);
				if (quotedLocationId <= 0 || quotedLocationId !== selectedLocationId) {
					panelState(STATE_ERROR);
					clearDetail();
					return;
				}
				if (lastSelection) { lastSelection.location_id = quotedLocationId; }
				panelState(STATE_BOOKABLE);
				showDetail('detail-capacity', { capacity: toInt(payload.capacity_left) });
				// Phase 8 Slice 2 — انتخابِ غیر-PHI برای عبور از مرز login.
				if (lastSelection) {
					storeSelection(lastSelection);
					if (authMode === 'patient') {
						// بیمارِ واردشده: ادامه با B1 موجود — Hold فقط اینجاست.
						beginHold(lastSelection);
					}
				}
				return;
			}

			if (result.ok && payload && payload.available === false) {
				panelState(STATE_UNAVAILABLE);
				showDetail('detail-capacity', { capacity: toInt(payload.capacity_left) });
				return;
			}

			// سیاستِ زمان‌بندیِ سرور (min-lead / تقویم محلیِ Location) ⇒ 409 با
			// کدِ پایدار. مرورگر فقط نگاشت می‌کند و پیامِ خودِ سرور را نشان
			// می‌دهد؛ هیچ محاسبهٔ زمانی این‌جا انجام نمی‌شود.
			var code = result.body && isNonEmptyString(result.body.code) ? result.body.code : '';
			if (code === CODE_POLICY_VIOLATION) {
				panelState(STATE_POLICY_REJECTED);
				showServerDetail(result);
				return;
			}

			panelState(STATE_ERROR);
			showServerDetail(result);
		}

		function quoteSlot(button) {
			var slotDate = button.getAttribute('data-slot-date') || '';
			var slotTime = button.getAttribute('data-slot-time') || '';
			var slotId = toInt(button.getAttribute('data-slot-id'));
			var locationId = toInt(button.getAttribute('data-location-id'));

			if (selectedClinicianId <= 0 || selectedLocationId <= 0 || locationId !== selectedLocationId || slotDate === '' || slotTime === '') {
				return;
			}

			setPressed(slotButtons(), button);
			setBusy(true);
			freeze();

			var payload = {
				clinician_id: selectedClinicianId,
				slot_date: slotDate,
				slot_time: slotTime
			};
			// `slot_id` دقیقِ A1 — هویتِ صریحِ اسلات. وقتی سرور id نداده باشد
			// (۰) فرستاده نمی‌شود تا A4 با همان tuple موجود حل کند.
			if (slotId > 0) {
				payload.slot_id = slotId;
			}
			payload.location_id = selectedLocationId;

			// Phase 8 Slice 2 — انتخابِ جاری (غیر-PHI) برای ادامهٔ احراز/رزرو.
			lastSelection = {
				clinician_id: selectedClinicianId,
				slot_id: slotId > 0 ? slotId : 0,
				location_id: selectedLocationId,
				slot_date: slotDate,
				slot_time: slotTime
			};

			// A4 — POST بدون query در URL (همهٔ ورودی در بدنهٔ JSON است)، پس
			// الحاقِ ساده در هر دو حالتِ permalink درست است و route سالم می‌ماند.
			// اگر روزی پارامترِ query به این URL اضافه شد، باید از `apiUrl()`
			// بگذرد — دقیقاً مثل A1.
			requestJson(config.rest_root + config.quote_path, {
				method: 'POST',
				credentials: 'omit',
				headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
				body: JSON.stringify(payload)
			}).then(function (result) {
				setBusy(false);
				unfreeze();
				applyVerdict(result);
			}, function () {
				setBusy(false);
				unfreeze();
				panelState(STATE_ERROR);
				clearDetail();
			});
		}

		/* ============ Phase 8 Slice 2 — جریان‌های continuation ============ */

		function setNodeMessage(node, message) {
			if (!node) {
				return;
			}
			if (!isNonEmptyString(message)) {
				setText(node, '');
				node.hidden = true;
				return;
			}
			// فقط متنِ خودِ سرور (envelope) — بدون هیچ تفسیرِ سمت مرورگر.
			setText(node, message);
			node.hidden = false;
		}

		function showAuthStep(name) {
			var steps = root.querySelectorAll('[data-auth-step]');
			for (var i = 0; i < steps.length; i++) {
				steps[i].hidden = steps[i].getAttribute('data-auth-step') !== name;
			}
		}

		/** راحتیِ ورود: ارقام فارسی/عربی به ASCII — سیاست و اعتبارسنجی همچنان سرور. */
		function digitsOnly(value) {
			var normalized = String(value || '')
				.replace(/[\u06F0-\u06F9]/g, function (d) {
					return String(d.charCodeAt(0) - 0x06F0);
				})
				.replace(/[\u0660-\u0669]/g, function (d) {
					return String(d.charCodeAt(0) - 0x0660);
				});
			return normalized.replace(/[^0-9]/g, '');
		}

		/* ---------- anonymous: ورود با OTP روی مسیرهای موجود A2/A3 ---------- */

		function otpRequest() {
			var input = root.querySelector('[data-role="otp-mobile"]');
			var status = root.querySelector('[data-role="auth-status"]');
			if (!input || busy) {
				return;
			}
			var mobile = digitsOnly(input.value);
			if (!/^09\d{9}$/.test(mobile)) {
				input.setAttribute('aria-invalid', 'true');
				setNodeMessage(status, 'شماره موبایل معتبر ۱۱ رقمی با پیش‌شماره ۰۹ وارد کنید.');
				input.focus();
				return;
			}
			input.removeAttribute('aria-invalid');

			var payload = { mobile: mobile };
			// Phase 8 Slice 2 — Clinicِ چالش از انتخابِ واقعیِ صفحه مشتق می‌شود؛
			// سرور tuple را فقط وقتی کامل می‌پذیرد (پزشک+اسلات+تاریخ+ساعت).
			// در نصب با بیش از یک Clinic بدون همین انتخاب، A2 با
			// CLINIC_SCOPE_REQUIRED مسدود می‌شود (class A — یافتهٔ مرورگر واقعی).
			var a2Selection = validSelection(lastSelection) || readStoredSelection();
			if (a2Selection && toInt(a2Selection.slot_id) > 0) {
				payload.clinician_id = toInt(a2Selection.clinician_id);
				payload.slot_id = toInt(a2Selection.slot_id);
				payload.slot_date = a2Selection.slot_date;
				payload.slot_time = a2Selection.slot_time;
				// Phase 8 Slice 4 — the challenge must be bound to the Clinic the
				// visitor actually selected; the server only accepts it when the
				// persisted slot and an active participation agree.
				if (toInt(a2Selection.clinic_id) > 0) {
					payload.clinic_id = toInt(a2Selection.clinic_id);
				} else {
					withClinic(payload);
				}
			}

			setBusy(true);
			requestJson(config.rest_root + config.otp_request_path, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
				body: JSON.stringify(payload)
			}).then(function (result) {
				setBusy(false);
				if (result.ok) {
					setNodeMessage(status, '');
					showAuthStep('otp-code');
					var codeInput = root.querySelector('[data-role="otp-code"]');
					if (codeInput) {
						codeInput.removeAttribute('aria-invalid');
						codeInput.focus();
					}
					return;
				}
				input.setAttribute('aria-invalid', 'true');
				setNodeMessage(status, serverMessage(result) || 'ارسال کد تأیید انجام نشد. لطفاً دوباره تلاش کنید.');
			}, function () {
				setBusy(false);
				setNodeMessage(status, 'ارتباط با سرور برقرار نشد. لطفاً اتصال اینترنت را بررسی و دوباره تلاش کنید.');
			});
		}

		function otpVerify() {
			var input = root.querySelector('[data-role="otp-mobile"]');
			var codeInput = root.querySelector('[data-role="otp-code"]');
			var status = root.querySelector('[data-role="auth-status"]');
			if (!input || !codeInput || busy) {
				return;
			}
			var mobile = digitsOnly(input.value);
			var code = digitsOnly(codeInput.value);
			if (mobile === '' || code.length < 4) {
				codeInput.setAttribute('aria-invalid', 'true');
				setNodeMessage(status, 'کد تأیید پیامک‌شده را کامل وارد کنید.');
				codeInput.focus();
				return;
			}
			codeInput.removeAttribute('aria-invalid');

			setBusy(true);
			requestJson(config.rest_root + config.otp_verify_path, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
				body: JSON.stringify({ mobile: mobile, code: code })
			}).then(function (result) {
				setBusy(false);
				var payload = result.body && result.body.data ? result.body.data : null;
				if (result.ok && payload && payload.session_issued === true) {
					// session کوکی هم‌مبدأ است؛ رندرِ تازه نقش/nonce/B1/B2 را
					// منتشر می‌کند و انتخابِ حفظ‌شده باز-می‌نشیند.
					if (lastSelection) {
						storeSelection(lastSelection);
					}
					window.location.reload();
					return;
				}
				codeInput.setAttribute('aria-invalid', 'true');
				setNodeMessage(status, result.ok ? '' : (serverMessage(result) || 'کد تأیید معتبر نیست یا منقضی شده است.'));
			}, function () {
				setBusy(false);
				setNodeMessage(status, 'ارتباط با سرور برقرار نشد. لطفاً دوباره تلاش کنید.');
			});
		}

		/* ---------- patient: Hold → Confirm روی مسیرهای موجود B1/B2 ---------- */

		function continueMessageNode() {
			return root.querySelector('[data-role="continue-status"]');
		}

		function setContinueMessage(message) {
			setNodeMessage(continueMessageNode(), message);
		}

		function stopCountdown() {
			if (countdownTimer) {
				window.clearInterval(countdownTimer);
				countdownTimer = 0;
			}
			var box = root.querySelector('[data-role="hold-countdown"]');
			if (box) {
				box.hidden = true;
			}
		}

		/**
		 * شمارشِ معکوس TTL — فقط «مدت» تا expires_atِ سرور محاسبه می‌شود؛
		 * هیچ بازمحاسبهٔ wall-clock نوبت در کار نیست (قرارداد Slice 1).
		 */
		function startCountdown(expiresAt) {
			var box = root.querySelector('[data-role="hold-countdown"]');
			var value = root.querySelector('[data-role="countdown-value"]');
			if (!box || !value || !isNonEmptyString(expiresAt)) {
				return;
			}
			stopCountdown();
			var deadline = Date.parse(expiresAt.replace(' ', 'T').replace(/\.\d+$/, '') + 'Z');
			if (isNaN(deadline)) {
				return;
			}

			function tick() {
				var left = Math.floor((deadline - Date.now()) / 1000);
				if (left <= 0) {
					stopCountdown();
					return;
				}
				var mm = Math.floor(left / 60);
				var ss = left % 60;
				value.textContent = (mm < 10 ? '0' : '') + mm + ':' + (ss < 10 ? '0' : '') + ss;
				box.hidden = false;
			}
			tick();
			countdownTimer = window.setInterval(tick, 1000);
		}

		function hideNearby() {
			if (nearbyWrap) {
				nearbyWrap.hidden = true;
			}
			if (nearbyList) {
				setText(nearbyList, '');
			}
		}

		/** پیشنهادهای نزدیک — فقط از دادهٔ خودِ سرور (nearby_slots B1). */
		function renderNearby(entries) {
			if (!nearbyWrap || !nearbyList) {
				return;
			}
			setText(nearbyList, '');
			if (!Array.isArray(entries) || entries.length === 0) {
				nearbyWrap.hidden = true;
				return;
			}
			var added = 0;
			for (var i = 0; i < entries.length && added < 5; i++) {
				var entry = entries[i];
				if (!entry || typeof entry !== 'object') {
					continue;
				}
				var entryLocationId = toInt(entry.location_id);
				if (entryLocationId <= 0 || entryLocationId !== selectedLocationId || !findAvailableLocation(entryLocationId)) {
					continue;
				}
				var node = templateNode(root, 'slot');
				if (!node) {
					continue;
				}
				node.setAttribute('data-slot-id', String(toInt(entry.slot_id)));
				node.setAttribute('data-location-id', String(entryLocationId));
				node.setAttribute('data-slot-date', isNonEmptyString(entry.date) ? entry.date : '');
				node.setAttribute('data-slot-time', isNonEmptyString(entry.time) ? entry.time : '');
				fill(node, 'time', isNonEmptyString(entry.time) ? entry.time : '');
				fill(node, 'duration', toInt(entry.duration_min));
				fill(node, 'capacity', toInt(entry.capacity_left));
				nearbyList.appendChild(node);
				added++;
			}
			nearbyWrap.hidden = added === 0;
		}

		function beginHold(selection) {
			if (!continueBox || busy || !validSelection(selection) || toInt(selection.location_id) <= 0 || toInt(selection.location_id) !== selectedLocationId) {
				return;
			}
			continueBox.hidden = false;
			hideNearby();
			setBusy(true);
			freeze();

			var body = {
				clinician_id: toInt(selection.clinician_id),
				slot_date: selection.slot_date,
				slot_time: selection.slot_time
			};
			if (toInt(selection.slot_id) > 0) {
				body.slot_id = toInt(selection.slot_id);
			}
			body.location_id = toInt(selection.location_id);
			// Phase 8 Slice 3 — B1 only: strict send gate. patient_id may be sent ONLY IF N>1 chooser exists,
			// has options, and id is exactly among current rendered data-patient-id values. Otherwise clear stale and send nothing.
			var pidToSend = getPatientIdForB1();
			if (pidToSend > 0) {
				body.patient_id = pidToSend;
			}

			requestJson(config.rest_root + config.hold_path, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
				body: JSON.stringify(body)
			}).then(function (result) {
				setBusy(false);
				unfreeze();
				var payload = result.body && result.body.data ? result.body.data : null;
				if (result.ok && payload && isNonEmptyString(payload.hold_token)) {
					holdToken = payload.hold_token;
					setDisabled(clinicianButtons, true);
					setDisabled(slotButtons(), true);
					setDisabled(locationOptionButtons(), true);
					startCountdown(isNonEmptyString(payload.expires_at) ? payload.expires_at : '');
					var namesForm = root.querySelector('[data-role="names-form"]');
					if (namesForm && namesForm.getAttribute('data-first-time') === '1') {
						namesForm.hidden = false;
					}
					var reasonWrap = root.querySelector('[data-role="reason-wrap"]');
					if (reasonWrap) {
						reasonWrap.hidden = false;
					}
					var confirmBtn = root.querySelector('[data-role="confirm-btn"]');
					if (confirmBtn) {
						confirmBtn.hidden = false;
					}
					return;
				}
				handleBookingFailure(result);
			}, function () {
				setBusy(false);
				unfreeze();
				setContinueMessage('ارتباط با سرور برای نگه‌داشتن نوبت برقرار نشد. لطفاً دوباره تلاش کنید.');
			});
		}

		function handleBookingFailure(result) {
			var code = result.body && isNonEmptyString(result.body.code) ? result.body.code : '';
			if (code === 'CLINIC_SLOT_TAKEN') {
				var payload = result.body && result.body.data ? result.body.data : null;
				renderNearby(payload && Array.isArray(payload.nearby_slots) ? payload.nearby_slots : null);
				clearStoredSelection();
			}
			setContinueMessage(serverMessage(result));
		}

		function confirmHold() {
			if (!continueBox || busy || holdToken === '') {
				return;
			}
			var firstInput = root.querySelector('[data-role="patient-first-name"]');
			var lastInput = root.querySelector('[data-role="patient-last-name"]');
			var reasonInput = root.querySelector('[data-role="booking-reason"]');
			if (firstInput) { firstInput.removeAttribute('aria-invalid'); }
			if (lastInput) { lastInput.removeAttribute('aria-invalid'); }
			var body = { hold_token: holdToken };
			if (firstInput && isNonEmptyString(firstInput.value.trim())) {
				body.first_name = firstInput.value.trim();
			}
			if (lastInput && isNonEmptyString(lastInput.value.trim())) {
				body.last_name = lastInput.value.trim();
			}
			if (reasonInput && isNonEmptyString(reasonInput.value.trim())) {
				body.reason = reasonInput.value.trim().slice(0, 255);
			}

			setBusy(true);
			freeze();
			requestJson(config.rest_root + config.confirm_path, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					Accept: 'application/json',
					'Content-Type': 'application/json',
					'X-WP-Nonce': config.nonce,
					'Idempotency-Key': idempotencyKey()
				},
				body: JSON.stringify(body)
			}).then(function (result) {
				setBusy(false);
				unfreeze();
				var payload = result.body && result.body.data ? result.body.data : null;
				if (result.ok && payload && isNonEmptyString(payload.reference_code)) {
					stopCountdown();
					hideNearby();
					var namesForm = root.querySelector('[data-role="names-form"]');
					if (namesForm) {
						namesForm.hidden = true;
					}
					var reasonWrap = root.querySelector('[data-role="reason-wrap"]');
					if (reasonWrap) {
						reasonWrap.hidden = true;
					}
					var confirmBtn = root.querySelector('[data-role="confirm-btn"]');
					if (confirmBtn) {
						confirmBtn.hidden = true;
					}
					setContinueMessage('');
					var receipt = root.querySelector('[data-role="receipt"]');
					var referenceNode = receipt ? receipt.querySelector('[data-role="reference-code"]') : null;
					if (referenceNode) {
						referenceNode.textContent = String(payload.reference_code);
					}
					var jalaliNode = receipt ? receipt.querySelector('[data-role="slot-jalali"]') : null;
					if (jalaliNode) {
						jalaliNode.textContent = isNonEmptyString(payload.jalali)
							? String(payload.jalali)
							: (payload.slot && isNonEmptyString(payload.slot.jalali) ? String(payload.slot.jalali) : '');
					}
					var timeNode = receipt ? receipt.querySelector('[data-role="slot-time"]') : null;
					if (timeNode) {
						timeNode.textContent = payload.slot && isNonEmptyString(payload.slot.time)
							? String(payload.slot.time)
							: (isNonEmptyString(payload.time) ? String(payload.time) : '');
					}
					if (receipt) {
						receipt.hidden = false;
					}
					clearStoredSelection();
					clearStoredPatientId();
					return;
				}
				if (!result.ok) {
					var code = result.body && isNonEmptyString(result.body.code) ? result.body.code : '';
					if (code === 'CLINIC_VALIDATION_FAILED') {
						var form = root.querySelector('[data-role="names-form"]');
						if (form) {
							form.hidden = false;
						}
						if (firstInput && !isNonEmptyString(firstInput.value.trim())) {
							firstInput.setAttribute('aria-invalid', 'true');
						}
						if (lastInput && !isNonEmptyString(lastInput.value.trim())) {
							lastInput.setAttribute('aria-invalid', 'true');
						}
						if (firstInput && !isNonEmptyString(firstInput.value.trim())) {
							firstInput.focus();
						} else if (lastInput) {
							lastInput.focus();
						}
					}
					handleBookingFailure(result);
					return;
				}
				setContinueMessage('');
			}, function () {
				setBusy(false);
				unfreeze();
				setContinueMessage('ارتباط با سرور برای تأیید نهایی نوبت برقرار نشد. نوبت شما همچنان نگه داشته شده است؛ دوباره تلاش کنید.');
			});
		}

		/**
		 * باز-نشانیِ انتخابِ حفظ‌شده پس از reload — همان A4 موجود، سپس B1.
		 * اگر انتخاب از دست رفته باشد، کاربر در همان سطح دوباره انتخاب می‌کند؛
		 * resume (B6) عمداً استفاده نمی‌شود.
		 */
		function quoteSelection(selection, autoHold) {
			if (busy || !validSelection(selection)) { return; }
			var locationId = toInt(selection.location_id);
			var clinicianId = toInt(selection.clinician_id);
			var clinicianButton = null;
			for (var i = 0; i < clinicianButtons.length; i++) {
				if (toInt(clinicianButtons[i].getAttribute('data-clinician-id')) === clinicianId) {
					clinicianButton = clinicianButtons[i];
					break;
				}
			}
			if (locationId <= 0 || !clinicianButton || !findAvailableLocation(locationId)) { return; }
			if (locationId !== selectedLocationId && !setLocationChoice(locationId, false)) { return; }
			if (selectedLocationId !== locationId) { return; }
			setPressed(clinicianButtons, clinicianButton);
			selectedClinicianId = clinicianId;

			lastSelection = {
				clinic_id: currentClinicId(),
				clinician_id: clinicianId,
				slot_id: toInt(selection.slot_id),
				location_id: locationId,
				slot_date: selection.slot_date,
				slot_time: selection.slot_time
			};

			var payload = {
				clinician_id: clinicianId,
				slot_date: selection.slot_date,
				slot_time: selection.slot_time,
				location_id: locationId
			};
			if (toInt(selection.slot_id) > 0) { payload.slot_id = toInt(selection.slot_id); }

			panelState(STATE_LOADING);
			setBusy(true);
			freeze();
			requestJson(config.rest_root + config.quote_path, {
				method: 'POST',
				credentials: 'omit',
				headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
				body: JSON.stringify(payload)
			}).then(function (result) {
				setBusy(false);
				unfreeze();
				var p = result.body && result.body.data ? result.body.data : null;
				if (result.ok && p && p.available === true && toInt(p.location_id) === locationId) {
					panelState(STATE_BOOKABLE);
					showDetail('detail-capacity', { capacity: toInt(p.capacity_left) });
					storeSelection(lastSelection);
					if (autoHold) { beginHold(lastSelection); }
					return;
				}
				applyVerdict(result);
				clearStoredSelection();
			}, function () {
				setBusy(false);
				unfreeze();
				clearStoredSelection();
			});
		}

		/* ---------- راه‌اندازیِ continuation در بارگذاری ---------- */

		if (authMode === 'patient') {
			var restored = readStoredSelection();
			if (restored && toInt(restored.clinician_id) > 0) {
				selectedClinicianId = toInt(restored.clinician_id);
				for (var restoreIndex = 0; restoreIndex < clinicianButtons.length; restoreIndex++) {
					if (toInt(clinicianButtons[restoreIndex].getAttribute('data-clinician-id')) === selectedClinicianId) {
						setPressed(clinicianButtons, clinicianButtons[restoreIndex]);
						break;
					}
				}
				loadAvailability(selectedClinicianId, restored);
			}
		}

		/* ---------------- رویدادها (delegation) ---------------- */

		root.addEventListener('click', function (event) {
			if (busy) {
				return;
			}

			var target = event.target;
			if (!target || typeof target.closest !== 'function') {
				return;
			}

			// Phase 8 Slice 4 — ناوبری هفته (presentation-only؛ bookability سمت سرور).
			var prevWeek = target.closest('[data-role="prev-week"]');
			if (prevWeek && root.contains(prevWeek) && !prevWeek.disabled) {
				gotoWeek(weekOffset - 1);
				return;
			}
			var nextWeek = target.closest('[data-role="next-week"]');
			if (nextWeek && root.contains(nextWeek) && !nextWeek.disabled) {
				gotoWeek(weekOffset + 1);
				return;
			}
			var jumpWeek = target.closest('[data-role="first-available-week"]');
			if (jumpWeek && root.contains(jumpWeek)) {
				var firstIndex = firstAvailableIndex(calendarScaffold, availableDays);
				if (firstIndex >= 0) { gotoWeek(Math.floor(firstIndex / 7)); }
				return;
			}
			var resetFilters = target.closest('[data-role="doctor-reset"]');
			if (resetFilters && root.contains(resetFilters)) {
				if (doctorSearch) { doctorSearch.value = ''; }
				if (specialtyFilter) { specialtyFilter.value = ''; }
				applyDoctorFilters();
				return;
			}

			var clinicianButton = target.closest(CLINICIAN_SELECTOR);
			if (clinicianButton && root.contains(clinicianButton)) {
				var clinicianId = toInt(clinicianButton.getAttribute('data-clinician-id'));
				if (clinicianId <= 0) {
					return;
				}
				setPressed(clinicianButtons, clinicianButton);
				selectedClinicianId = clinicianId;
				loadAvailability(clinicianId);
				return;
			}

			var locationOption = target.closest(ROLE_LOCATION_OPTION);
			if (locationOption && locationList && locationList.contains(locationOption) && !locationOption.disabled) {
				setLocationChoice(toInt(locationOption.getAttribute('data-location-id')), true);
				return;
			}

			var slotButton = target.closest(SLOT_SELECTOR);
			if (slotButton && daysHost.contains(slotButton) && !slotButton.disabled) {
				quoteSlot(slotButton);
				return;
			}

			/* -------- Phase 8 Slice 2 — رویدادهای continuation -------- */

			var continueButton = target.closest('[data-role="continue-auth"]');
			if (continueButton && root.contains(continueButton)) {
				continueButton.hidden = true;
				showAuthStep('otp-mobile');
				var mobileInput = root.querySelector('[data-role="otp-mobile"]');
				if (mobileInput) {
					mobileInput.focus();
				}
				return;
			}

			var authAction = target.closest('[data-auth-action]');
			if (authAction && root.contains(authAction)) {
				var action = authAction.getAttribute('data-auth-action');
				if (action === 'otp-request') {
					otpRequest();
				} else if (action === 'otp-verify') {
					otpVerify();
				} else if (action === 'otp-change-mobile') {
					var authStatus = root.querySelector('[data-role="auth-status"]');
					setNodeMessage(authStatus, '');
					showAuthStep('otp-mobile');
					var mobileNode = root.querySelector('[data-role="otp-mobile"]');
					if (mobileNode) {
						mobileNode.focus();
					}
				}
				return;
			}

			/* -------- Phase 8 Slice 3 — patient chooser (N>1) -------- */
			var patientOption = target.closest('[data-role="patient-option"]');
			if (patientOption && patientChooser && patientChooser.contains(patientOption) && !patientOption.disabled) {
				var pid = toInt(patientOption.getAttribute('data-patient-id'));
				setPatientSelection(pid);
				// If a slot was already selected, retry Hold with the newly selected patient (B1 only).
				if (lastSelection && validSelection(lastSelection)) {
					beginHold(lastSelection);
				}
				return;
			}

			var confirmButton = target.closest('[data-role="confirm-btn"]');
			if (confirmButton && root.contains(confirmButton) && !confirmButton.disabled) {
				confirmHold();
				return;
			}

			if (slotButton && nearbyList && nearbyList.contains(slotButton) && !slotButton.disabled) {
				// پیشنهادِ نزدیک پس از CLINIC_SLOT_TAKEN — همان جریانِ A4→B1.
				quoteSelection({
					clinician_id: selectedClinicianId,
					slot_id: toInt(slotButton.getAttribute('data-slot-id')),
					location_id: toInt(slotButton.getAttribute('data-location-id')),
					slot_date: slotButton.getAttribute('data-slot-date') || '',
					slot_time: slotButton.getAttribute('data-slot-time') || ''
				}, true);
			}
		});

		/* ---------- Phase 8 Slice 4: filters + week init ---------- */

		if (doctorSearch) {
			doctorSearch.addEventListener('input', applyDoctorFilters);
		}
		if (specialtyFilter) {
			specialtyFilter.addEventListener('change', applyDoctorFilters);
		}
		if (clinicSelect) {
			// The first option is the server's own first listed Clinic; adopting it
			// here means the very first A1 request already carries clinic_id.
			if (activeClinicId <= 0) {
				activeClinicId = toInt(clinicSelect.value);
				root.setAttribute('data-clinic-id', String(activeClinicId));
			}
			clinicSelect.addEventListener('change', function () {
				var next = toInt(clinicSelect.value);
				activeClinicId = next > 0 ? next : 0;
				root.setAttribute('data-clinic-id', String(activeClinicId));
				// انتخابِ Clinicِ دیگر، انتخابِ پزشک/نوبتِ قبلی را بی‌اعتبار می‌کند
				// (هیچ نوبتی از Clinic دیگر حفظ نمی‌شود).
				selectedClinicianId = 0;
				lastSelection = null;
				weekOffset = 0;
				availableDays = [];
				clearStoredSelection();
				setText(daysHost, '');
				setPressed(clinicianButtons, null);
				panelState(STATE_IDLE);
				updateTimezoneLabel();
				applyDoctorFilters();
			});
		}
		updateTimezoneLabel();
		applyDoctorFilters();
		updateWeekChrome(0);

		/**
		 * منطقهٔ زمانیِ Clinicِ انتخاب‌شده — مقدار از `data-timezone` گزینهٔ
		 * خودِ سرور و برچسب از `data-label` همان گره؛ هیچ رشتهٔ فارسیِ تازه و
		 * هیچ محاسبهٔ timezone در JS ساخته نمی‌شود.
		 */
		function updateTimezoneLabel() {
			if (!timezoneNode) { return; }
			var tz = '';
			if (clinicSelect && clinicSelect.selectedIndex >= 0 && clinicSelect.options[clinicSelect.selectedIndex]) {
				tz = String(clinicSelect.options[clinicSelect.selectedIndex].getAttribute('data-timezone') || '');
			}
			if (tz === '') {
				timezoneNode.hidden = true;
				timezoneNode.textContent = '';
				return;
			}
			var label = String(timezoneNode.getAttribute('data-label') || '');
			timezoneNode.textContent = label === '' ? tz : label + ' ' + tz;
			timezoneNode.hidden = false;
		}
	}

	/* ====================== Phase 8 Slice 4 helpers ====================== */

	/**
	 * نرمال‌سازیِ متنِ جست‌وجوی فارسی/عربی — هم‌ارزِ سرور
	 * (PublicBookingShortcode::normalize_search_text). فقط عبارتِ **کاربر**
	 * نرمال می‌شود؛ دادهٔ پزشکان از پیش در `data-search` نرمال‌شده آمده است.
	 */
	function normalizeSearchText(value) {
		var text = typeof value === 'string' ? value : '';
		text = text.trim();
		if (text === '') {
			return '';
		}
		text = text
			.replace(/[يىئ]/g, 'ی')
			.replace(/ك/g, 'ک')
			.replace(/[ةۀ]/g, 'ه')
			.replace(/[أإآٱ]/g, 'ا')
			.replace(/ؤ/g, 'و')
			.replace(/[\u064B-\u065F\u0670\u0640\u200B-\u200F]/g, '')
			.replace(/[\u0660-\u0669]/g, function (d) { return String(d.charCodeAt(0) - 0x0660); })
			.replace(/[\u06F0-\u06F9]/g, function (d) { return String(d.charCodeAt(0) - 0x06F0); });
		return text.toLowerCase();
	}

	/** ایندکسِ شروعِ هفتهٔ `offset` روی تقویمِ منتشرشده (bounded). */
	function weekStartIndex(offset, total) {
		var start = Math.floor(offset) * 7;
		if (!isFinite(start) || start < 0) { start = 0; }
		if (total > 0 && start > total - 1) { start = Math.floor((total - 1) / 7) * 7; }
		return start;
	}

	/** آیا هفتهٔ `offset` قابلِ نمایش است؟ (کرانِ جلو/عقب) */
	function weekOffsetInBounds(offset, total) {
		if (!isFinite(offset) || offset < 0) { return false; }
		if (total <= 0) { return offset === 0; }
		return offset * 7 < total;
	}

	/** ایندکسِ اولین روزِ تقویم که در A1 نوبت دارد (یا -1). */
	function firstAvailableIndex(calendar, days) {
		if (!Array.isArray(calendar) || !Array.isArray(days)) { return -1; }
		var dates = {};
		for (var i = 0; i < days.length; i++) {
			var d = days[i] && typeof days[i] === 'object' ? days[i].date : null;
			if (isNonEmptyString(d) && Array.isArray(days[i].slots) && days[i].slots.length > 0) {
				dates[d] = true;
			}
		}
		for (var j = 0; j < calendar.length; j++) {
			if (calendar[j] && dates[calendar[j].date] === true) { return j; }
		}
		return -1;
	}

	// Test seam (pure logic only — no DOM, no network, no bookability).
	window.CpmsPublicBookingDiscovery = {
		normalizeSearchText: normalizeSearchText,
		weekStartIndex: weekStartIndex,
		weekOffsetInBounds: weekOffsetInBounds,
		firstAvailableIndex: firstAvailableIndex
	};

	/* ====================== راه‌اندازی ====================== */

	function init() {
		var roots = document.querySelectorAll(ROOT_SELECTOR);
		for (var i = 0; i < roots.length; i++) {
			try {
				bootSurface(roots[i]);
			} catch (e) {
				// یک نمونهٔ خراب هرگز نباید نمونهٔ دیگر را از کار بیندازد یا
				// خطای نگرفته به کنسول برساند.
				var panel = roots[i].querySelector(ROLE_PANEL);
				if (panel) {
					panel.setAttribute('data-state', STATE_ERROR);
				}
			}
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		// script در footer چاپ می‌شود، پس DOM پیش از این آماده است.
		init();
	}
})();
