/**
 * CPMS Classic — offline catalogue field enhancement (province / city / specialty).
 *
 * Scope: progressive enhancement ONLY.
 *  - Offline: everything it filters is already server-rendered from the in-repository
 *    catalogues (IranProvinceCityCatalog / MedicalSpecialtyCatalog). No network call,
 *    no external service, no data leaves the page.
 *  - Never a gate: the server re-validates every submission
 *    (resolve_submitted_address / MedicalSpecialtyCatalog::resolve_submitted). With
 *    JavaScript disabled the native <select> elements still work and the manual
 *    "Other / سایر" inputs stay visible, so no flow depends on this file.
 *  - Never a default: an empty query shows every option; nothing is auto-selected and
 *    Tehran is never implied.
 *
 * It complements (does not replace) the existing province→city cascade shipped in
 * cpms-admin.js and cpms-management-portal.js: this file re-applies the province
 * scoping together with the typed query, so a filter can never reveal a city that
 * belongs to another province, and the "Other / سایر" option always stays reachable.
 */
(function () {
  'use strict';

  function normalize(value) {
    // Same folding contract as the server: Arabic Yeh/Kaf -> Persian, ZWNJ kept,
    // whitespace collapsed, case-insensitive for Latin aliases.
    return String(value || '')
      .replace(/[\u064A\u0649]/g, '\u06CC')
      .replace(/[\u0643]/g, '\u06A9')
      .replace(/\s+/g, ' ')
      .trim()
      .toLowerCase();
  }

  function optionsOf(select) {
    return Array.prototype.slice.call(select.querySelectorAll('option'));
  }

  function setHidden(option, hidden) {
    option.hidden = hidden;
    option.disabled = hidden;
    if (hidden) {
      option.setAttribute('hidden', 'hidden');
      option.setAttribute('disabled', 'disabled');
    } else {
      option.removeAttribute('hidden');
      option.removeAttribute('disabled');
    }
  }

  function setGroupHidden(group, hidden) {
    group.hidden = hidden;
    group.disabled = hidden;
    if (hidden) {
      group.setAttribute('hidden', 'hidden');
      group.setAttribute('disabled', 'disabled');
    } else {
      group.removeAttribute('hidden');
      group.removeAttribute('disabled');
    }
  }

  function matches(option, query) {
    if (query === '') { return true; }
    var text = normalize(option.textContent) + ' ' + normalize(option.getAttribute('data-en'));
    return text.indexOf(query) !== -1;
  }

  /* ---------------------------------------------------------------- address */

  function bindAddressGeography(box) {
    var provinceSelect = box.querySelector('[data-cpms-province-select="1"]');
    var citySelect = box.querySelector('[data-cpms-city-select="1"]');
    if (!provinceSelect || !citySelect) { return; }

    var provinceSearch = box.querySelector('[data-cpms-province-search="1"]');
    var citySearch = box.querySelector('[data-cpms-city-search="1"]');
    var customWrap = box.querySelector('[data-cpms-city-custom-wrap="1"]');
    var customInput = box.querySelector('[data-cpms-city-custom-input="1"]');
    var otherOption = citySelect.querySelector('[data-cpms-city-other="1"]');
    var otherSentinel = box.getAttribute('data-cpms-other-sentinel') || '__other__';

    function isOtherSelected() {
      return citySelect.value === otherSentinel;
    }

    function syncCustomVisibility() {
      if (!customWrap) { return; }
      // No-JS baseline is "visible"; with script the manual field is shown only on
      // the "Other / سایر" path (or when it already holds a persisted custom city).
      var needed = isOtherSelected() || String(customInput && customInput.value || '').trim() !== '';
      customWrap.hidden = !needed;
      customWrap.style.display = needed ? '' : 'none';
      if (needed && isOtherSelected() && customInput) {
        customInput.setAttribute('aria-required', 'true');
      } else if (customInput) {
        customInput.removeAttribute('aria-required');
      }
    }

    function applyCityFilter() {
      var query = normalize(citySearch && citySearch.value);
      var province = provinceSelect.value || '';
      var visibleInGroup = {};

      Array.prototype.forEach.call(citySelect.querySelectorAll('optgroup[data-province]'), function (group) {
        var groupProvince = group.getAttribute('data-province') || '';
        var provinceMismatch = province !== '' && groupProvince !== province;
        var visible = 0;

        optionsOf(group).forEach(function (option) {
          var hide = provinceMismatch || !matches(option, query);
          setHidden(option, hide);
          if (!hide) { visible += 1; }
        });

        visibleInGroup[groupProvince] = visible;
        setGroupHidden(group, provinceMismatch || visible === 0);
      });

      // The manual-entry option is never filtered away: a missing city must always
      // remain reachable, including when a search matches nothing.
      if (otherOption) { setHidden(otherOption, false); }

      // A selection that the filter just hid (and that is not the manual path) is
      // cleared so the browser can never submit an invisible, mismatched city.
      var current = citySelect.options[citySelect.selectedIndex];
      if (current && current.value !== '' && current !== otherOption && current.hidden) {
        citySelect.value = '';
      }
    }

    function applyProvinceFilter() {
      if (!provinceSearch) { return; }
      var query = normalize(provinceSearch.value);
      var selected = provinceSelect.value;
      optionsOf(provinceSelect).forEach(function (option) {
        if (option.value === '') { setHidden(option, false); return; }
        // The already-persisted province is never filtered out of its own form.
        setHidden(option, option.value !== selected && !matches(option, query));
      });
    }

    provinceSelect.addEventListener('change', function () {
      applyCityFilter();
      syncCustomVisibility();
    });
    if (provinceSearch) {
      provinceSearch.addEventListener('input', applyProvinceFilter);
    }
    if (citySearch) {
      citySearch.addEventListener('input', applyCityFilter);
    }
    citySelect.addEventListener('change', function () {
      syncCustomVisibility();
      if (isOtherSelected() && customInput) {
        customInput.focus();
      }
    });
    if (customInput) {
      customInput.addEventListener('input', syncCustomVisibility);
    }

    applyProvinceFilter();
    applyCityFilter();
    syncCustomVisibility();
  }

  /* -------------------------------------------------------------- specialty */

  function bindSpecialtyField(box) {
    var select = box.querySelector('[data-cpms-specialty-select="1"]');
    if (!select) { return; }

    var search = box.querySelector('[data-cpms-specialty-search="1"]');
    var customWrap = box.querySelector('[data-cpms-specialty-custom-wrap="1"]');
    var customInput = box.querySelector('[data-cpms-specialty-custom-input="1"]');
    var otherOption = select.querySelector('[data-cpms-specialty-other="1"]');
    var otherSentinel = box.getAttribute('data-cpms-other-sentinel') || '__other__';

    function isOtherSelected() {
      return select.value === otherSentinel;
    }

    function syncCustomVisibility() {
      if (!customWrap) { return; }
      var needed = isOtherSelected() || String(customInput && customInput.value || '').trim() !== '';
      customWrap.hidden = !needed;
      customWrap.style.display = needed ? '' : 'none';
      if (customInput) {
        if (needed && isOtherSelected()) {
          customInput.setAttribute('aria-required', 'true');
        } else {
          customInput.removeAttribute('aria-required');
        }
      }
    }

    function applyFilter() {
      if (!search) { return; }
      var query = normalize(search.value);
      var selected = select.value;

      Array.prototype.forEach.call(select.querySelectorAll('optgroup'), function (group) {
        var visible = 0;
        optionsOf(group).forEach(function (option) {
          var hide = option.value !== selected && !matches(option, query);
          setHidden(option, hide);
          if (!hide) { visible += 1; }
        });
        setGroupHidden(group, visible === 0);
      });

      if (otherOption) { setHidden(otherOption, false); }

      var current = select.options[select.selectedIndex];
      if (current && current.value !== '' && current !== otherOption && current.hidden) {
        select.value = '';
      }
    }

    select.addEventListener('change', function () {
      syncCustomVisibility();
      if (isOtherSelected() && customInput) {
        customInput.focus();
      }
    });
    if (search) {
      search.addEventListener('input', applyFilter);
    }
    if (customInput) {
      customInput.addEventListener('input', syncCustomVisibility);
    }

    applyFilter();
    syncCustomVisibility();
  }

  /* ------------------------------------------------------------------- init */

  function init() {
    Array.prototype.forEach.call(
      document.querySelectorAll('[data-cpms-address-geography="1"]'),
      bindAddressGeography
    );
    Array.prototype.forEach.call(
      document.querySelectorAll('[data-cpms-specialty-field="1"]'),
      bindSpecialtyField
    );
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
