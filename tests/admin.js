'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const { mkdtempSync, mkdirSync, writeFileSync, readFileSync, existsSync, rmSync } = require('node:fs');
const { tmpdir } = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { JSDOM } = require('jsdom');
const source = readFileSync(path.join(__dirname, '../assets/admin.js'), 'utf8');
function fixture() {
  const root = mkdtempSync(path.join(tmpdir(), 'pv-cart-admin-'));
  const state = path.join(root, 'state.json'), log = path.join(root, 'log.jsonl');
  const bootstrap = path.join(root, 'bitrix/modules/main/include');
  mkdirSync(bootstrap, { recursive: true });
  for (const name of ['prolog_admin_before.php','prolog_admin_after.php','epilog_admin.php']) writeFileSync(path.join(bootstrap, name), '<?php');
  const row = {
    ID: 5, STATUS: 'active', FUSER_ID: 42, SITE_ID: 's1', CUSTOMER_NAME: '<script>bad()</script>',
    EMAIL: 'first@example.test', PHONE: '+7999', ITEMS_JSON: JSON.stringify([{ name: 'Товар', quantity: 2 }, { name: 'Второй товар', quantity: 1 }]),
    TOTAL: '200', CURRENCY: 'RUB', UPDATED_AT: '2026-11-12 16:50:00', ORDER_ID: null,
    DELIVERY_ADDRESS: 'Санкт-Петербург, Невский, 1', ORDER_COMMENT: 'Позвонить заранее', DELIVERY_METHOD: 'Курьер',
    PAYMENT_METHOD: 'Наличными', CHECKOUT_UPDATED_AT: '2026-11-12 16:50:01'
  };
  writeFileSync(state, JSON.stringify([row, { ...row, ID: 6, STATUS: 'abandoned' }, { ...row, ID: 7, STATUS: 'abandoned', TOTAL: '30', CURRENCY: 'USD' }, { ...row, ID: 8, STATUS: 'ordered', ORDER_ID: 123 }]));
  const doms = [];
  function render(mode = 'normal', options = {}) {
    const result = spawnSync(process.env.PHP_BIN || 'php', [path.join(__dirname, 'admin-fixture.php'), mode], {
      encoding: 'utf8', env: { ...process.env, PV_TEST_DOCUMENT_ROOT: root, PV_TEST_EMAIL: options.email || 'first@example.test',
        PV_TEST_STATE: state, PV_TEST_LOG: log, PV_TEST_POST: JSON.stringify(options.post || {}), PV_TEST_ADMIN: options.admin === false ? 'no' : 'yes', PV_TEST_STATUS: options.status || 'all' }
    });
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
  }
  function document(options = {}) {
    const dom = new JSDOM(render('normal', options), { runScripts: 'outside-only', pretendToBeVisual: true, url: 'https://example.test/bitrix/admin/pv_abandonedcart_list.php?status=all' });
    doms.push(dom);
    return dom.window;
  }
  return {
    row, render, document,
    rows: () => JSON.parse(readFileSync(state, 'utf8')),
    setRows: rows => writeFileSync(state, JSON.stringify(rows)),
    mutations: () => existsSync(log) ? readFileSync(log, 'utf8').trim().split('\n').filter(Boolean).map(JSON.parse) : [],
    close: () => { doms.forEach(dom => dom.window.close()); rmSync(root, { recursive: true, force: true }); }
  };
}
function start(window, fetch) {
  let refresh;
  window.setInterval = fn => { refresh = fn; };
  window.fetch = fetch;
  window.eval(source);
  return () => refresh();
}
function change(window, input, checked) { input.checked = checked; input.dispatchEvent(new window.Event('change', { bubbles: true })); }
function submit(window) { const event = new window.Event('submit', { bubbles: true, cancelable: true }); window.document.getElementById('pv-cart-bulk-form').dispatchEvent(event); return event; }

test('compact rows hide details and owner IDs; abandoned count is global and sums keep currencies separate', () => {
  const f = fixture();
  try {
    const w = f.document({ status: 'active' }), d = w.document;
    assert.equal(d.querySelectorAll('.pv-cart-row').length, 1);
    assert.equal(d.getElementById('pv-abandoned-count').textContent, '2');
    assert.deepEqual([...d.querySelectorAll('.pv-cart-currency')].map(el => el.textContent.trim()), ['200.00 RUB', '30.00 USD']);
    assert.ok(!d.body.textContent.includes('Владелец корзины'));
    assert.ok(!d.body.textContent.includes('FUSER'));
    assert.ok(d.getElementById('pv-cart-details-5').hidden);
    assert.ok(!d.querySelector('.pv-cart-row').textContent.includes('Невский'));
    assert.ok(!d.querySelector('.pv-cart-row').textContent.includes('Позвонить заранее'));
    assert.ok(d.getElementById('pv-cart-details-5').textContent.includes('Невский'));
    assert.ok(d.getElementById('pv-cart-details-5').textContent.includes('Второй товар'));
    assert.equal(d.querySelectorAll('#pv-cart-live script').length, 0, 'customer HTML escaped');
  } finally { f.close(); }
});
test('disclosure, select all and partial selection survive automatic refresh without selecting newly arrived rows or resetting settings', async () => {
  const f = fixture();
  try {
    const w = f.document(), d = w.document;
    let finish;
    const refresh = start(w, url => {
      const request = new URL(url);
      assert.equal(request.searchParams.get('pv_refresh'), 'Y');
      assert.equal(request.searchParams.get('status'), 'all');
      return new Promise(resolve => { finish = () => resolve({ ok: true, json: async () => JSON.parse(f.render('refresh', { email: 'updated@example.test' })) }); });
    });
    change(w, d.getElementById('pv-cart-select-all'), true);
    assert.equal(d.getElementById('pv-cart-selection-count').textContent, 'Выбрано: 4');
    change(w, d.getElementById('pv-cart-check-6'), false);
    assert.ok(d.getElementById('pv-cart-select-all').indeterminate);
    refresh();
    // These interactions happen while the request is pending.
    d.getElementById('pv-cart-toggle-5').click();
    assert.ok(!d.getElementById('pv-cart-details-5').hidden);
    change(w, d.getElementById('pv-cart-check-7'), false);
    const settings = d.querySelector('[name="timeout_minutes"]'); settings.value = '99';
    f.setRows([...f.rows(), { ...f.row, ID: 9 }]);
    finish(); await new Promise(setImmediate);
    assert.ok(d.getElementById('pv-cart-details-5').textContent.includes('updated@example.test'));
    assert.ok(!d.getElementById('pv-cart-details-5').hidden);
    assert.equal(d.getElementById('pv-cart-toggle-5').getAttribute('aria-expanded'), 'true');
    assert.deepEqual([...d.querySelectorAll('[data-cart-id]:checked')].map(el => el.value), ['5', '8']);
    assert.equal(d.getElementById('pv-cart-check-9').checked, false);
    assert.ok(d.getElementById('pv-cart-select-all').indeterminate);
    assert.equal(d.querySelector('[name="timeout_minutes"]'), settings); assert.equal(settings.value, '99');
    d.getElementById('pv-cart-toggle-5').click(); assert.ok(d.getElementById('pv-cart-details-5').hidden);
  } finally { f.close(); }
});
test('delete button requires a selection and confirmation; accepted deletion pauses refresh', () => {
  const f = fixture();
  try {
    const w = f.document(), d = w.document;
    let requests = 0;
    const refresh = start(w, () => { requests++; return Promise.resolve({ ok: true, json: async () => ({}) }); });
    assert.ok(d.getElementById('pv-cart-delete').disabled);
    w.confirm = () => { throw new Error('no prompt for empty selection'); };
    assert.ok(submit(w).defaultPrevented);
    change(w, d.getElementById('pv-cart-check-5'), true);
    assert.equal(d.getElementById('pv-cart-delete').disabled, false);
    w.confirm = () => false; assert.ok(submit(w).defaultPrevented);
    w.confirm = message => { assert.ok(message.includes('(1)')); return true; };
    assert.equal(submit(w).defaultPrevented, false);
    refresh(); assert.equal(requests, 0);
  } finally { f.close(); }
});
test('server deletes only selected module records, deduplicates IDs and updates totals without writing settings', () => {
  const f = fixture();
  try {
    const response = JSON.parse(f.render('post', { post: { action: 'delete_selected', sessid: 'test', cart_ids: ['5','6','5'] } }));
    assert.ok(response.redirect.includes('deleted=2'));
    assert.deepEqual(f.rows().map(row => row.ID), [7,8]);
    assert.deepEqual(f.mutations(), [{ sql: 'DELETE FROM pv_abandoned_cart WHERE ID IN (5,6)' }]);
    const w = f.document();
    assert.equal(w.document.getElementById('pv-abandoned-count').textContent, '1');
    assert.deepEqual([...w.document.querySelectorAll('.pv-cart-currency')].map(el => el.textContent.trim()), ['30.00 USD']);
  } finally { f.close(); }
});
test('server rejects CSRF, non-admin access, empty or malformed IDs and oversized deletion requests', () => {
  const f = fixture();
  try {
    const attempts = [
      { post: { action: 'delete_selected', sessid: 'bad', cart_ids: ['5'] } },
      { admin: false, post: { action: 'delete_selected', sessid: 'test', cart_ids: ['5'] } },
      { post: { action: 'delete_selected', sessid: 'test', cart_ids: [] } },
      { post: { action: 'delete_selected', sessid: 'test', cart_ids: ['5','6 OR 1=1'] } },
      { post: { action: 'delete_selected', sessid: 'test', cart_ids: [['5']] } },
      { post: { action: 'delete_selected', sessid: 'test', cart_ids: '5' } },
      { post: { action: 'delete_selected', sessid: 'test', cart_ids: ['0'] } },
      { post: { action: 'delete_selected', sessid: 'test', cart_ids: Array(201).fill('5') } }
    ];
    for (const options of attempts) {
      const result = f.render('post', options);
      assert.ok(!result.includes('"redirect"'));
      assert.equal(f.rows().length, 4);
      assert.deepEqual(f.mutations(), []);
    }
  } finally { f.close(); }
});
test('select all is limited to the 200 displayed records and leaves undisplayed records intact', () => {
  const f = fixture();
  try {
    f.setRows(Array.from({ length: 201 }, (_, i) => ({ ...f.row, ID: i + 1, STATUS: 'abandoned' })));
    const w = f.document(), d = w.document;
    start(w, () => Promise.resolve({ ok: true, json: async () => ({}) }));
    assert.equal(d.getElementById('pv-abandoned-count').textContent, '201');
    change(w, d.getElementById('pv-cart-select-all'), true);
    const selected = [...d.querySelectorAll('[data-cart-id]:checked')].map(el => el.value);
    assert.equal(selected.length, 200);
    f.render('post', { post: { action: 'delete_selected', sessid: 'test', cart_ids: selected } });
    assert.deepEqual(f.rows().map(row => row.ID), [201]);
  } finally { f.close(); }
});
test('empty list displays zero totals and disables selection and deletion', () => {
  const f = fixture();
  try {
    f.setRows([]);
    const w = f.document(), d = w.document;
    start(w, () => Promise.resolve({ ok: true, json: async () => ({}) }));
    assert.equal(d.getElementById('pv-abandoned-count').textContent, '0');
    assert.ok(d.querySelector('.pv-cart-empty'));
    assert.ok(d.getElementById('pv-cart-select-all').disabled);
    assert.ok(d.getElementById('pv-cart-delete').disabled);
  } finally { f.close(); }
});
