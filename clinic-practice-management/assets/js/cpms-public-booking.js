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

	/* ====================== واژگانِ وضعیت (منتشرشده توسط سرور) ====================== */

	var STATE_LOADING = 'loading';
	var STATE_SELECTABLE = 'selectable';
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

	function readStoredSelection() {
		try {
			return validSelection(JSON.parse(window.sessionStorage.getItem(SELECT_KEY) || 'null'));
		} catch (e) {
			return null;
		}
	}

	function storeSelection(selection) {
		try {
			if (validSelection(selection)) {
				window.sessionStorage.setItem(SELECT_KEY, JSON.stringify(selection));
			}
		} catch (e) {
			// بدون storage هم جریان ادامه می‌یابد — کاربر دوباره انتخاب می‌کند.
		}
	}

	function clearStoredSelection() {
		try {
			window.sessionStorage.removeItem(SELECT_KEY);
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

		if (!panel || !daysHost) {
			return;
		}

		var busy = false;
		var selectedClinicianId = 0;

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
		function getStoredPatientId() {
			try {
				var v = window.localStorage.getItem('cpms-patient-selection:' + String(config.clinic_id));
				return toInt(v);
			} catch (e) {
				return 0;
			}
		}
		function storePatientId(id) {
			try {
				if (toInt(id) > 0) {
					window.localStorage.setItem('cpms-patient-selection:' + String(config.clinic_id), String(toInt(id)));
				}
			} catch (e) {}
		}
		function setPatientSelection(id) {
			selectedPatientId = toInt(id);
			storePatientId(selectedPatientId);
			for (var _i = 0; _i < patientOptions.length; _i++) {
				var opt = patientOptions[_i];
				var pid = toInt(opt.getAttribute('data-patient-id'));
				opt.setAttribute('aria-pressed', pid === selectedPatientId && selectedPatientId > 0 ? 'true' : 'false');
			}
		}
		// Restore from localStorage if chooser exists; do not auto-pick first row.
		if (patientOptions.length > 0) {
			var storedPid = getStoredPatientId();
			if (storedPid > 0) {
				// Validate stored id is among options; if not, keep 0.
				var found = false;
				for (var _j = 0; _j < patientOptions.length; _j++) {
					if (toInt(patientOptions[_j].getAttribute('data-patient-id')) === storedPid) {
						found = true;
						break;
					}
				}
				if (found) {
					setPatientSelection(storedPid);
				}
			}
			// Also respect DOM pre-selected (aria-pressed) if any.
			for (var _k = 0; _k < patientOptions.length; _k++) {
				if (patientOptions[_k].getAttribute('aria-pressed') === 'true') {
					setPatientSelection(toInt(patientOptions[_k].getAttribute('data-patient-id')));
					break;
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
		}

		function unfreeze() {
			setDisabled(clinicianButtons, false);
			setDisabled(slotButtons(), false);
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
			if (slotDate === '' || slotTime === '') {
				return null;
			}

			var node = templateNode(root, 'slot');
			if (!node) {
				return null;
			}

			// هویتِ دقیقِ اسلات از A1 — همان مقداری که عیناً به A4 برمی‌گردد.
			node.setAttribute('data-slot-id', String(toInt(slot.slot_id)));
			node.setAttribute('data-slot-date', slotDate);
			node.setAttribute('data-slot-time', slotTime);
			node.setAttribute('aria-pressed', 'false');

			// ساعتِ wall-clock محلیِ Location — بدون بازمحاسبه در مرورگر.
			fill(node, 'time', slotTime);
			fill(node, 'duration', toInt(slot.duration_min));
			fill(node, 'capacity', toInt(slot.capacity_left));

			return node;
		}

		function renderDays(days) {
			setText(daysHost, '');

			var slotCount = 0;
			for (var i = 0; i < days.length; i++) {
				var dayNode = buildDay(days[i]);
				if (dayNode) {
					daysHost.appendChild(dayNode);
					slotCount += dayNode.querySelectorAll(SLOT_SELECTOR).length;
				}
			}

			return slotCount;
		}

		function loadAvailability(clinicianId) {
			clearDetail();
			setText(daysHost, '');
			panelState(STATE_LOADING);
			setBusy(true);
			setDisabled(clinicianButtons, true);

			// A1 — joinِ سازگار با permalink (Plain: `&`، Pretty: `?`). مسیر و
			// ریشه هر دو از قراردادِ منتشرشدهٔ سرور می‌آیند؛ هیچ hardcode ای نیست.
			var url = apiUrl(
				config.rest_root,
				config.availability_path + '?clinician_id=' + encodeURIComponent(String(clinicianId))
			);

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

				// `{days: []}` یعنی همین پزشک در بازهٔ پیش‌فرضِ سرور نوبتِ
				// آزادی ندارد ⇒ وضعیتِ روشنِ `empty` (نه خطا).
				if (days.length === 0) {
					panelState(STATE_EMPTY);
					clearDetail();
					return;
				}

				panelState(renderDays(days) > 0 ? STATE_SELECTABLE : STATE_EMPTY);
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

			if (selectedClinicianId <= 0 || slotDate === '' || slotTime === '') {
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

			// Phase 8 Slice 2 — انتخابِ جاری (غیر-PHI) برای ادامهٔ احراز/رزرو.
			lastSelection = {
				clinician_id: selectedClinicianId,
				slot_id: slotId > 0 ? slotId : 0,
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
			if (mobile === '') {
				setNodeMessage(status, '');
				input.focus();
				return;
			}

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
						codeInput.focus();
					}
					return;
				}
				setNodeMessage(status, serverMessage(result));
			}, function () {
				setBusy(false);
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
			if (mobile === '' || code === '') {
				setNodeMessage(status, '');
				return;
			}

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
				setNodeMessage(status, result.ok ? '' : serverMessage(result));
			}, function () {
				setBusy(false);
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
				var node = templateNode(root, 'slot');
				if (!node) {
					continue;
				}
				node.setAttribute('data-slot-id', String(toInt(entry.slot_id)));
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
			if (!continueBox || busy || !validSelection(selection)) {
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
			// Phase 8 Slice 3 — B1 only: include linked patient selection when chooser is active.
			// Reads from DOM >0 / localStorage when available; if missing, send nothing (server 0/1/N handles).
			var pidToSend = selectedPatientId;
			if (pidToSend <= 0) {
				pidToSend = getStoredPatientId();
			}
			if (pidToSend <= 0 && patientOptions.length > 0) {
				for (var _ps = 0; _ps < patientOptions.length; _ps++) {
					if (patientOptions[_ps].getAttribute('aria-pressed') === 'true') {
						pidToSend = toInt(patientOptions[_ps].getAttribute('data-patient-id'));
						break;
					}
				}
			}
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
					startCountdown(isNonEmptyString(payload.expires_at) ? payload.expires_at : '');
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
			var body = { hold_token: holdToken };
			if (firstInput && isNonEmptyString(firstInput.value.trim())) {
				body.first_name = firstInput.value.trim();
			}
			if (lastInput && isNonEmptyString(lastInput.value.trim())) {
				body.last_name = lastInput.value.trim();
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
					return;
				}
				if (!result.ok) {
					var code = result.body && isNonEmptyString(result.body.code) ? result.body.code : '';
					if (code === 'CLINIC_VALIDATION_FAILED') {
						var form = root.querySelector('[data-role="names-form"]');
						if (form) {
							form.hidden = false;
						}
						if (firstInput) {
							firstInput.focus();
						}
					}
					handleBookingFailure(result);
					return;
				}
				setContinueMessage('');
			}, function () {
				setBusy(false);
				unfreeze();
			});
		}

		/**
		 * باز-نشانیِ انتخابِ حفظ‌شده پس از reload — همان A4 موجود، سپس B1.
		 * اگر انتخاب از دست رفته باشد، کاربر در همان سطح دوباره انتخاب می‌کند؛
		 * resume (B6) عمداً استفاده نمی‌شود.
		 */
		function quoteSelection(selection, autoHold) {
			if (busy || !validSelection(selection)) {
				return;
			}
			for (var i = 0; i < clinicianButtons.length; i++) {
				if (toInt(clinicianButtons[i].getAttribute('data-clinician-id')) === toInt(selection.clinician_id)) {
					setPressed(clinicianButtons, clinicianButtons[i]);
					selectedClinicianId = toInt(selection.clinician_id);
					break;
				}
			}

			lastSelection = {
				clinician_id: toInt(selection.clinician_id),
				slot_id: toInt(selection.slot_id),
				slot_date: selection.slot_date,
				slot_time: selection.slot_time
			};

			var payload = {
				clinician_id: toInt(selection.clinician_id),
				slot_date: selection.slot_date,
				slot_time: selection.slot_time
			};
			if (toInt(selection.slot_id) > 0) {
				payload.slot_id = toInt(selection.slot_id);
			}

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
				if (result.ok && p && p.available === true) {
					panelState(STATE_BOOKABLE);
					showDetail('detail-capacity', { capacity: toInt(p.capacity_left) });
					if (autoHold) {
						beginHold(lastSelection);
					}
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
				quoteSelection(restored, true);
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
					slot_date: slotButton.getAttribute('data-slot-date') || '',
					slot_time: slotButton.getAttribute('data-slot-time') || ''
				}, true);
			}
		});
	}

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
