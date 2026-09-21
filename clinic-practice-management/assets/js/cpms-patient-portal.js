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
		if (!isNonEmptyString(parsed.rest_root) || !isNonEmptyString(parsed.nonce)) {
			return null;
		}
		if (!isNonEmptyString(parsed.cancel_path) || parsed.cancel_path.indexOf('{id}') === -1) {
			return null;
		}
		return parsed;
	}

	/**
	 * ساختِ URL سازگار با هر دو حالتِ permalink (همان الگوی موجود apiUrl):
	 * اگر ریشه `?` دارد (Plain: `index.php?rest_route=/clinic/v1`) و مسیر هم query
	 * دارد، نخستین `?` مسیر به `&` تبدیل می‌شود؛ در غیر این صورت الحاقِ ساده.
	 */
	function apiUrl(restRoot, path) {
		if (restRoot.indexOf('?') !== -1 && path.indexOf('?') !== -1) {
			return restRoot + path.replace('?', '&');
		}
		return restRoot + path;
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

	function bind(config) {
		var buttons = document.querySelectorAll(BUTTON_SELECTOR);
		var adminAssets = !!(document.body && document.body.classList && document.body.classList.contains('cpms-admin'));
		Array.prototype.forEach.call(buttons, function (button) {
			// Modal تأیید متعلق به cpms-admin.js است و با همان گیتِ صفحه (body.cpms-admin)
			// بار می‌شود؛ بدون آن، صفت تأیید حذف می‌شود تا دکمه هرگز بی‌اثر نماند.
			if (!adminAssets) {
				button.removeAttribute(CONFIRM_ATTR);
			}
			button.addEventListener('click', function () {
				if (button.hasAttribute(CONFIRM_ATTR)) {
					// نوبتِ Modal تأیید است؛ پس از تأیید، cpms-admin.js صفت را برمی‌دارد
					// و همین دکمه را دوباره click می‌کند.
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
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
