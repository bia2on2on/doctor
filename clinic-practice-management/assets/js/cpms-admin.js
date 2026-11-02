/**
 * CPMS Admin — progressive enhancement (Chunk F)
 *
 * فقط در صفحات CPMS لود می‌شود. همهٔ رفتارها add-on هستند؛ اگر JS اجرا نشود،
 * اکشن‌های خطرناک بدون تأییدِ بصری مرورگر انجام می‌شوند، اما مرز authorize سمت سرور
 * (Nonce + Capability + Audit) دست‌نخورده می‌ماند — confirmation = UX، نه authorization.
 * هیچ فراخوانی شبکه‌ای جدیدی ندارد (بدون API/تله‌متری).
 */
(function () {
  'use strict';

  // 1) Dangerous-action confirmations — Persian, accessible, in-page (assets/js/cpms-dialog.js).
  //    D2 (fail-closed): if the shared dialog asset is missing, we fall back to the
  //    NATIVE window.confirm. There is no code path that proceeds without a
  //    confirmation of some kind. Confirmation stays UX only — nonce + capability
  //    on the server are the authorization boundary.
  function dialogAvailable() {
    return !!(window.CpmsDialog && typeof window.CpmsDialog.confirm === 'function');
  }

  function nativeConfirm(message) {
    // window.confirm is synchronous; a thrown/blocked dialog counts as CANCEL.
    try {
      return window.confirm(message) === true;
    } catch (e) {
      return false;
    }
  }

  function showConfirm(message) {
    if (!dialogAvailable()) {
      return Promise.resolve(nativeConfirm(message));
    }
    return window.CpmsDialog.confirm(message, { title: 'تأیید عملیات' });
  }

  // D3: a second activation of the SAME trigger while its dialog is pending is
  // ignored (no second dialog, no second action).
  var pendingTriggers = [];
  function isPending(trigger) {
    return pendingTriggers.indexOf(trigger) !== -1;
  }
  function markPending(trigger) {
    pendingTriggers.push(trigger);
  }
  function clearPending(trigger) {
    var i = pendingTriggers.indexOf(trigger);
    if (i !== -1) {
      pendingTriggers.splice(i, 1);
    }
  }

  /**
   * One confirmation, then run `act`. Cancel does nothing.
   * The synchronous native branch has already decided the outcome before any
   * preventDefault happens; the async branch intercepts the event first.
   *
   * `nativeAct` — only for controls whose own default action does NOT do the
   * work (data-cpms-schedule-delete is a `type="button"`). For a form submit,
   * a submit button and a link, the browser performs the action itself once the
   * event is not cancelled: calling `act()` there too would run it twice
   * (requestSubmit() fires another submit event → two POSTs).
   */
  function confirmThen(event, trigger, message, act, nativeAct) {
    if (isPending(trigger)) {
      event.preventDefault();
      event.stopPropagation();
      return;
    }
    if (!dialogAvailable()) {
      // Synchronous: decide BEFORE touching the event.
      if (!nativeConfirm(message)) {
        event.preventDefault();
        event.stopPropagation();
        return;
      }
      if (typeof nativeAct === 'function') {
        nativeAct();
      }
      return;
    }
    event.preventDefault();
    event.stopPropagation();
    markPending(trigger);
    showConfirm(message).then(function (ok) {
      clearPending(trigger);
      if (!ok) {
        return;
      }
      act();
    }, function () {
      clearPending(trigger);
    });
  }

  function bindConfirms() {
    // فرم‌ها
    Array.prototype.forEach.call(document.querySelectorAll('form[data-cpms-confirm]'), function (form) {
      form.addEventListener('submit', function (e) {
        if (!form.hasAttribute('data-cpms-confirm')) { return; } // already confirmed → real submit
        confirmThen(e, form, form.getAttribute('data-cpms-confirm'), function () {
          form.removeAttribute('data-cpms-confirm');
          try { form.requestSubmit(); } catch (err) { form.submit(); }
        });
      });
    });
    // لینک/دکمه‌ها
    Array.prototype.forEach.call(document.querySelectorAll('a[data-cpms-confirm], button[data-cpms-confirm]:not([data-cpms-schedule-delete])'), function (el) {
      el.addEventListener('click', function (e) {
        if (!el.hasAttribute('data-cpms-confirm')) { return; }
        confirmThen(e, el, el.getAttribute('data-cpms-confirm'), function () {
          el.removeAttribute('data-cpms-confirm');
          if (el.tagName === 'A' && el.href) {
            window.location.href = el.href;
          } else {
            el.click();
          }
        });
      });
    });
    // حذف برنامه (دکمه‌ای که فرم hidden را پر و submit می‌کند).
    // D2: preventDefault only AFTER the confirmation path is decided.
    Array.prototype.forEach.call(document.querySelectorAll('[data-cpms-schedule-delete]'), function (btn) {
      btn.addEventListener('click', function (e) {
        var message = btn.getAttribute('data-cpms-confirm') || 'حذف برنامه این روز؟';
        var performDelete = function () {
          var id = btn.getAttribute('data-cpms-schedule-delete');
          var f = document.getElementById('cpms-sched-del');
          if (f && id) {
            f.schedule_id.value = id;
            f.submit();
          }
        };
        // `type="button"` has no default action → the native branch runs it too.
        confirmThen(e, btn, message, performDelete, performDelete);
      });
    });
  }

  // 2) Advanced Permissions search/filter (چند نقش) — presentation only، هرگز state
  //    چک‌باکس را تغییر نمی‌دهد (display:none فقط پنهان می‌کند؛ unchecked نشده و submit
  //    همان مقادیر checked را می‌فرستد). رفتار:
  //    - گروهی که هیچ matching ندارد کاملاً hidden می‌شود (نه فقط بسته).
  //    - فقط گروه‌های matching auto-open می‌شوند؛ بقیه closed/hidden.
  //    - درون گروه matching فقط ردیف‌های matching visible می‌مانند.
  //    - query خالی → همهٔ گروه‌ها visible و closed؛ همهٔ ردیف‌ها visible؛ پیام empty/status مخفی.
  function bindCapSearch() {
    function norm(s) { return (s || '').toLowerCase(); }
    var searches = document.querySelectorAll('.cpms-cap-search');
    Array.prototype.forEach.call(searches, function (search) {
      var scope = search.getAttribute('data-scope') || '';
      var sel = '[data-scope="' + scope + '"]';
      var labels = document.querySelectorAll('.cpms-cap-list label[data-cap]' + sel);
      var groups = document.querySelectorAll('details.cpms-cap-group' + sel);
      var empty = document.querySelector('.cpms-cap-search-empty' + sel);
      var status = document.querySelector('.cpms-cap-search-status' + sel);
      search.addEventListener('input', function () {
        var q = norm(search.value.trim());
        var anyMatch = false;
        // الف) فیلتر ردیف‌ها (display فقط؛ checked دست نخورده)
        Array.prototype.forEach.call(labels, function (label) {
          var show = q === '' || norm(label.textContent).indexOf(q) !== -1;
          label.style.display = show ? '' : 'none';
          if (q !== '' && show) { anyMatch = true; }
        });
        // ب) فیلتر گروه‌ها: hidden/auto-open فقط برای گروه‌های matching
        Array.prototype.forEach.call(groups, function (group) {
          if (q === '') { group.style.display = ''; group.open = false; return; }
          var has = false;
          Array.prototype.forEach.call(group.querySelectorAll('.cpms-cap-list label[data-cap]'), function (l) {
            if (norm(l.textContent).indexOf(q) !== -1) { has = true; }
          });
          group.style.display = has ? '' : 'none';
          group.open = has; // فقط گروه‌های matching باز می‌شوند
        });
        // ج) پیام empty + شمارندهٔ نتیجه
        if (status) {
          var n = 0;
          Array.prototype.forEach.call(labels, function (l) {
            if (l.style.display !== 'none') { n++; }
          });
          status.textContent = q === '' ? '' : ('نتیجه: ' + n + ' مورد');
          status.style.display = q === '' ? 'none' : 'block';
        }
        if (empty) { empty.style.display = (q !== '' && !anyMatch) ? 'block' : 'none'; }
      });
    });
  }

  // 3) Autofocus شکل معتبر (بدون jump ناخواسته؛ فقط وقتی فیلد active وجود ندارد).
  function autofocusHost() {
    var host = document.querySelector('.cpms-autofocus input:not([type=hidden]):not([type=checkbox]):not([type=radio]), .cpms-autofocus select, .cpms-autofocus textarea');
    if (host) { host.focus(); }
  }

  // 4) انتخابگر آفلاین استان/شهر ایران (بدون هیچ فراخوانی شبکه‌ای یا وابستگی خارجی).
  function bindAddressGeography() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-cpms-address-geography="1"]'), function (box) {
      var provinceSelect = box.querySelector('[data-cpms-province-select="1"]');
      var citySelect = box.querySelector('[data-cpms-city-select="1"]');
      if (!provinceSelect || !citySelect) { return; }

      function syncCityOptions(resetOnMismatch) {
        var selectedProvince = provinceSelect.value || '';
        Array.prototype.forEach.call(citySelect.querySelectorAll('optgroup[data-province]'), function (group) {
          var groupProvince = group.getAttribute('data-province') || '';
          var mismatch = selectedProvince !== '' && groupProvince !== selectedProvince;
          group.hidden = mismatch;
          group.disabled = mismatch;
          if (!mismatch) {
            group.removeAttribute('hidden');
            group.removeAttribute('disabled');
          }
          Array.prototype.forEach.call(group.querySelectorAll('option'), function (opt) {
            opt.hidden = mismatch;
            opt.disabled = mismatch;
            if (!mismatch) {
              opt.removeAttribute('hidden');
              opt.removeAttribute('disabled');
            }
          });
        });
        if (resetOnMismatch) {
          var currentOption = citySelect.options[citySelect.selectedIndex];
          var optionProvince = currentOption ? (currentOption.getAttribute('data-province') || '') : '';
          if (selectedProvince === '' || (optionProvince !== '' && optionProvince !== selectedProvince)) {
            citySelect.value = '';
          }
        }
      }

      provinceSelect.addEventListener('change', function () {
        syncCityOptions(true);
      });
      syncCityOptions(false);
    });
  }

  function init() {
    bindConfirms();
    bindCapSearch();
    bindAddressGeography();
    autofocusHost();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
