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

  // 1) Dangerous-action confirmations — با یک Modal قابل‌دسترس و قابل‌رندر (نه confirm مرورگر).
  //    پیام فارسی از data-cpms-confirm. اگر JS اجرا نشود، nonce + capability سمت سرور
  //    همچنان مرجع authorize هستند (توضیح در گزارش: confirmation = UX، نه authorization).
  function showConfirm(message) {
    return new Promise(function (resolve) {
      // حذف هر modal قبلی
      var old = document.querySelector('.cpms-modal-overlay');
      if (old) { old.parentNode.removeChild(old); }
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
        if (overlay.parentNode) { overlay.parentNode.removeChild(overlay); }
        resolve(ok);
        if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) {} }
      }
      function onKey(e) {
        if (e.key === 'Escape') { e.preventDefault(); done(false); }
        if (e.key === 'Tab') {
          var focusables = [cancelBtn, confirmBtn];
          var i = focusables.indexOf(document.activeElement);
          if (e.shiftKey) {
            if (i <= 0) { e.preventDefault(); confirmBtn.focus(); }
          } else if (i === focusables.length - 1 || i === -1) {
            e.preventDefault(); cancelBtn.focus();
          }
        }
      }
      confirmBtn.addEventListener('click', function () { done(true); });
      cancelBtn.addEventListener('click', function () { done(false); });
      document.addEventListener('keydown', onKey, true);
      confirmBtn.focus();
    });
  }
  function bindConfirms() {
    // فرم‌ها
    Array.prototype.forEach.call(document.querySelectorAll('form[data-cpms-confirm]'), function (form) {
      form.addEventListener('submit', function (e) {
        if (!form.hasAttribute('data-cpms-confirm')) { return; } // already confirmed → real submit
        e.preventDefault();
        e.stopPropagation();
        showConfirm(form.getAttribute('data-cpms-confirm')).then(function (ok) {
          if (!ok) { return; }
          form.removeAttribute('data-cpms-confirm');
          try { form.requestSubmit(); } catch (err) { form.submit(); }
        });
      });
    });
    // لینک/دکمه‌ها
    Array.prototype.forEach.call(document.querySelectorAll('a[data-cpms-confirm], button[data-cpms-confirm]:not([data-cpms-schedule-delete])'), function (el) {
      el.addEventListener('click', function (e) {
        if (!el.hasAttribute('data-cpms-confirm')) { return; }
        e.preventDefault();
        e.stopPropagation();
        showConfirm(el.getAttribute('data-cpms-confirm')).then(function (ok) {
          if (!ok) { return; }
          el.removeAttribute('data-cpms-confirm');
          if (el.tagName === 'A' && el.href) {
            window.location.href = el.href;
          } else {
            el.click();
          }
        });
      });
    });
    // حذف برنامه (دکمه‌ای که فرم hidden را پر و submit می‌کند)
    Array.prototype.forEach.call(document.querySelectorAll('[data-cpms-schedule-delete]'), function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        showConfirm(btn.getAttribute('data-cpms-confirm') || 'حذف برنامه این روز؟').then(function (ok) {
          if (!ok) { return; }
          var id = btn.getAttribute('data-cpms-schedule-delete');
          var f = document.getElementById('cpms-sched-del');
          if (f && id) {
            f.schedule_id.value = id;
            f.submit();
          }
        });
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

  function init() {
    bindConfirms();
    bindCapSearch();
    autofocusHost();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
