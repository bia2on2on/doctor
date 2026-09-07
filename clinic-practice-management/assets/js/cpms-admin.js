/**
 * CPMS Admin — progressive enhancement (Chunk F)
 *
 * فقط در صفحات CPMS لود می‌شود. همهٔ رفتارها add-on هستند؛ اگر JS اجرا نشود،
 * فرم‌ها و لینک‌های خطرناک همچنان با onsubmit/confirm مرورگر و Nonce کار می‌کنند.
 * هیچ فراخوانی شبکه‌ای جدیدی ندارد (بدون API/تله‌متری).
 */
(function () {
  'use strict';

  // 1) Dangerous-action confirmations. فرم‌ها → submit؛ لینک/دکمه → click.
  //    پیام فارسی از data-cpms-confirm. اگر JS اجرا نشود، onsubmit/confirm مرورگر
  //    و Nonce همچنان به‌صورت پیش‌فرض کار می‌کنند (progressive enhancement).
  function confirmMessage(el) {
    return el.getAttribute('data-cpms-confirm') || 'آیا مطمئن هستید؟';
  }
  function bindConfirms() {
    var forms = document.querySelectorAll('form[data-cpms-confirm]');
    Array.prototype.forEach.call(forms, function (form) {
      form.addEventListener('submit', function (e) {
        if (!window.confirm(confirmMessage(form))) {
          e.preventDefault();
          e.stopPropagation();
          return false;
        }
      });
    });
    var nodes = document.querySelectorAll('a[data-cpms-confirm], button[data-cpms-confirm]');
    Array.prototype.forEach.call(nodes, function (el) {
      el.addEventListener('click', function (e) {
        if (!window.confirm(confirmMessage(el))) {
          e.preventDefault();
          e.stopPropagation();
          if (el.href) { return false; }
        }
      });
    });
  }

  // 2) Advanced Permissions search/filter (چند نقش): هر فیلد .cpms-cap-search
  //    فقط ردیف‌های [data-cap] با همان data-scope را فیلتر می‌کند.
  function bindCapSearch() {
    var searches = document.querySelectorAll('.cpms-cap-search');
    Array.prototype.forEach.call(searches, function (search) {
      var scope = search.getAttribute('data-scope') || '';
      var rows = document.querySelectorAll('[data-cap][data-scope="' + scope + '"]');
      search.addEventListener('input', function () {
        var q = search.value.trim().toLowerCase();
        Array.prototype.forEach.call(rows, function (row) {
          var text = (row.textContent || '').toLowerCase();
          var show = q === '' || text.indexOf(q) !== -1;
          row.style.display = show ? '' : 'none';
        });
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
