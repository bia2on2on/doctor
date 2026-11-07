/** Patient visit date display: execute the shipped portal's visit binding in a DOM fixture. */
'use strict';

var assert = require('assert');
var fs = require('fs');
var path = require('path');
var vm = require('vm');

var source = fs.readFileSync(path.join(__dirname, '../../assets/js/cpms-patient-portal.js'), 'utf8');
var marker = '\n\tfunction init() {';
assert.ok(source.includes(marker), 'portal init marker exists');
source = source.replace(marker, '\n\twindow.testBindVisits = bindVisits;' + marker);

function Element() {
  this.children = [];
  this.listeners = {};
  this.attributes = {};
  this.hidden = false;
  this._text = '';
}
Object.defineProperty(Element.prototype, 'textContent', {
  get: function () { return this._text; },
  set: function (value) { this._text = String(value); this.children = []; }
});
Element.prototype.appendChild = function (child) { this.children.push(child); return child; };
Element.prototype.addEventListener = function (name, fn) { this.listeners[name] = fn; };
Element.prototype.setAttribute = function (name, value) { this.attributes[name] = String(value); };
Element.prototype.getAttribute = function (name) { return this.attributes[name]; };
Element.prototype.removeAttribute = function (name) { delete this.attributes[name]; };
Element.prototype.querySelector = function () { return null; };
Element.prototype.contains = function (child) {
  return this.children.some(function (node) { return node === child || node.contains(child); });
};
Element.prototype.closest = function () { return this; };

async function run() {
  var nodes = {};
  ['visits-record-select', 'visits-context', 'visits-list', 'visits-detail', 'visits-loading', 'visits-error']
    .forEach(function (role) { nodes[role] = new Element(); });
  nodes['visits-record-select'].value = '12';
  var section = { querySelector: function (selector) {
    var match = selector.match(/data-role="([^"]+)"/);
    return match ? nodes[match[1]] || null : null;
  } };
  var requests = [];
  var listPayload = { visits: [
    { id: 31, visit_date: '2026-03-21', visit_jalali: '1405/01/01', clinician_name: 'Dr 9' },
    { id: 32, visit_date: '2026-03-22', visit_jalali: '۱۴۰۵/۰۱/۰۲', clinician_name: 'آزمون' }
  ] };
  var detailPayload = { visit: { id: 31, visit_date: '2026-03-21', visit_jalali: '1405/01/01' }, notes: [], recommendations: [] };
  var beforeList = JSON.stringify(listPayload);
  var beforeDetail = JSON.stringify(detailPayload);
  var window = { fetch: function (url, options) {
    requests.push({ url: url, options: options });
    var payload = url.indexOf('/visits/31?') !== -1 ? detailPayload : listPayload;
    return Promise.resolve({ ok: true, status: 200, json: function () { return Promise.resolve({ data: payload }); } });
  } };
  var document = {
    readyState: 'loading',
    addEventListener: function () {},
    createElement: function () { return new Element(); },
    querySelector: function (selector) { return selector === '[data-role="visits-section"]' ? section : null; }
  };
  var sandbox = { window: window, document: document, Promise: Promise, console: console };
  vm.createContext(sandbox);
  vm.runInContext(source, sandbox, { filename: 'cpms-patient-portal.js' });
  window.testBindVisits({
    rest_root: '/wp-json/clinic/v1', nonce: 'test-nonce', visits_path: '/visits',
    visit_detail_path: '/visits/{id}',
    profile_records: [{ link_id: 12, clinic_name: 'آزمایشی', patient_display_name: 'آزمودنی', mrn: 'MR-12' }]
  });
  nodes['visits-record-select'].listeners.change();
  await new Promise(function (resolve) { setImmediate(resolve); });
  var list = nodes['visits-list'];
  var first = list.children[0].children[0];
  var second = list.children[1].children[0];
  assert.strictEqual(first.textContent, '۱۴۰۵/۰۱/۰۱ — Dr 9');
  assert.strictEqual(second.textContent, '۱۴۰۵/۰۱/۰۲ — آزمون', 'already Persian remains unchanged');
  assert.strictEqual(first.getAttribute('data-visit-id'), '31', 'selector stays ASCII');
  assert.strictEqual(requests[0].url, '/wp-json/clinic/v1/visits?link_id=12');
  list.listeners.click({ target: first });
  await new Promise(function (resolve) { setImmediate(resolve); });
  assert.strictEqual(nodes['visits-detail'].children[0].textContent, 'جزئیات ویزیت — ۱۴۰۵/۰۱/۰۱');
  assert.strictEqual(requests[1].url, '/wp-json/clinic/v1/visits/31?link_id=12');
  detailPayload.visit.visit_jalali = '۱۴۰۵/۰۱/۰۱';
  list.listeners.click({ target: first });
  await new Promise(function (resolve) { setImmediate(resolve); });
  assert.strictEqual(nodes['visits-detail'].children[0].textContent, 'جزئیات ویزیت — ۱۴۰۵/۰۱/۰۱', 'already Persian detail stays unchanged');
  detailPayload.visit.visit_jalali = '1405/01/01';
  first.setAttribute('data-visit-id', '۳۱');
  list.listeners.click({ target: first });
  assert.strictEqual(requests.length, 3, 'non-ASCII selector is rejected, not used as a URL');
  nodes['visits-record-select'].value = '999';
  nodes['visits-record-select'].listeners.change();
  list.listeners.click({ target: first });
  assert.strictEqual(requests.length, 3, 'unknown selection does not initiate a request');
  assert.strictEqual(JSON.stringify(listPayload), beforeList, 'list API payload unchanged');
  assert.strictEqual(JSON.stringify(detailPayload), beforeDetail, 'detail API payload unchanged');
  assert.strictEqual(requests[0].options.headers['X-WP-Nonce'], 'test-nonce');
  console.log('OK: patient visit dates display Persian digits without changing API values or selectors');
}
run().catch(function (error) { console.error(error); process.exitCode = 1; });
