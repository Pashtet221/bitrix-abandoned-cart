'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { JSDOM } = require('jsdom');
const source = readFileSync(require('node:path').join(__dirname, '../assets/checkout.js'), 'utf8');
function setup(consent = true, ok = true) {
  const dom = new JSDOM(`<div id="bx-soa-order">
    <div class="bx-soa-customer-field"><label>ФИО</label><input name="ORDER_PROP_1" value="Иван Иванов"></div>
    <div class="bx-soa-customer-field"><label>E-mail</label><input name="ORDER_PROP_2" value="test@example.test"></div>
    <input name="ORDER_PROP_3" value="+79991234567">
    <input type="hidden" name="ORDER_PROP_4" value="0000073738">
    <input name="ORDER_PROP_5" value="190000">
    <div class="form-group"><label>CVV карты</label><input name="ORDER_PROP_8" value="123"></div>
    <input type="password" name="password" value="secret">
    <input type="radio" name="DELIVERY_ID" value="3" checked>
    <input type="radio" name="PAY_SYSTEM_ID" value="2" checked>
    <input type="checkbox" name="USER_CONSENT" ${consent ? 'checked' : ''}>
  </div>`, { runScripts: 'outside-only', url: 'https://example.test/checkout' });
  const window = dom.window, calls = [], callbacks = {};
  let scheduled;
  window.setTimeout = fn => { scheduled = fn; return 1; };
  window.clearTimeout = () => { scheduled = null; };
  window.BX = { bitrix_sessid: () => 'session', addCustomEvent: (name, fn) => { callbacks[name] = fn; } };
  window.fetch = async (url, options) => { calls.push({ url, data: Object.fromEntries(options.body) }); return { ok: true, json: async () => ({ ok }) }; };
  window.eval(source);
  return { window, calls, callbacks, flush: async () => { if (scheduled) scheduled(); await new Promise(setImmediate); }, close: () => window.close() };
}
test('standard numeric fields, hidden location, service IDs and prefilled form captured; card data excluded', async () => {
  const app = setup();
  try {
    await app.flush();
    const data = app.calls[0].data;
    assert.deepEqual(JSON.parse(data.properties), { 1: 'Иван Иванов', 2: 'test@example.test', 3: '+79991234567', 4: '0000073738', 5: '190000' });
    assert.equal(data.email, 'test@example.test');
    assert.equal(data.delivery, '3'); assert.equal(data.payment, '2');
    assert.equal(data.sessid, 'session');
    assert.ok(!JSON.stringify(data).includes('secret'));
    app.callbacks.onAjaxSuccess(); await app.flush();
    assert.equal(app.calls.length, 1, 'successful payload deduplicated');
    app.window.document.querySelector('[name="ORDER_PROP_2"]').value = 'new@example.test';
    app.callbacks.onAjaxSuccess(); await app.flush();
    assert.equal(app.calls[1].data.email, 'new@example.test');
  } finally { app.close(); }
});
test('unchecked consent blocks capture', async () => {
  const app = setup(false);
  try { await app.flush(); assert.equal(app.calls.length, 0); } finally { app.close(); }
});
test('HTTP 200 with ok:false is retried after checkout update', async () => {
  const app = setup(true, false);
  try {
    await app.flush(); app.callbacks.onAjaxSuccess(); await app.flush();
    assert.equal(app.calls.length, 2);
  } finally { app.close(); }
});
