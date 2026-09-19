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

			var url = config.rest_root + config.availability_path +
				'?clinician_id=' + encodeURIComponent(String(clinicianId));

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
