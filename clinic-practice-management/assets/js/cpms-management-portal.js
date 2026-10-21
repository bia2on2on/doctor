/**
 * CPMS Management Portal — province → city cascading (offline, no network).
 *
 * Offline-only: uses the server-rendered <select> markup from
 * AddressSelectorField (31 official provinces + cities grouped in <optgroup>).
 * Never defaults to Tehran; empty placeholder "" is the unselected state.
 *
 * Progressive enhancement: without JS all city groups remain visible and the
 * form stays usable; server-side IranProvinceCityCatalog re-validates every
 * submission (city without province or city not in province → InvalidArgument).
 *
 * Each address box is independent, so multiple repeated Clinic forms on the
 * same Organization overview page each cascade correctly.
 */
(function () {
  'use strict';

  function bindAddressGeography() {
    var boxes = document.querySelectorAll('[data-cpms-address-geography="1"]');
    Array.prototype.forEach.call(boxes, function (box) {
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
    bindAddressGeography();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
