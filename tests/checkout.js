'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { JSDOM } = require('jsdom');
const source = readFileSync(require('node:path').join(__dirname, '../assets/checkout.js'), 'utf8');
function setup(options = {}) {
  const dom = new JSDOM(`<form id="bx-soa-order-form"><div id="bx-soa-order">
    <div class="bx-soa-customer-field"><label>ФИО</label><input name="ORDER_PROP_1" value="Иван Иванов"></div>
    <div class="bx-soa-customer-field"><label>E-mail</label><input name="ORDER_PROP_2" value="test@example.test"></div>
    <input name="ORDER_PROP_3" value="+79991234567">
    <input type="hidden" name="ORDER_PROP_4" value="0000073738">
    <input name="ORDER_PROP_5" value="190000">
    <div class="form-group"><label>CVV карты</label><input name="ORDER_PROP_8" value="123"></div>
    <input type="password" name="password" value="secret">
    <input type="radio" name="DELIVERY_ID" value="3" checked>
    <input type="radio" name="PAY_SYSTEM_ID" value="2" checked>
    <input type="checkbox" name="USER_CONSENT" ${options.consent === false ? '' : 'checked'}>
  </div></form>`, { runScripts: 'outside-only', pretendToBeVisual: true, url: 'https://example.test/checkout' });
  const window = dom.window, calls = [], callbacks = {}, timers = new Map();
  let clock = 0, serial = 0;
  window.Date.now = () => clock;
  window.setTimeout = (fn, delay) => { const id = ++serial; timers.set(id, { fn, due: clock + delay }); return id; };
  window.setInterval = (fn, interval) => { const id = ++serial; timers.set(id, { fn, due: clock + interval, interval }); return id; };
  window.clearTimeout = window.clearInterval = id => timers.delete(id);
  window.BX = { bitrix_sessid: () => 'session', addCustomEvent: (name, fn) => { (callbacks[name] ||= []).push(fn); } };
  window.PVAbandonedCartConfig = { captureUrl: '/bitrix/modules/pv.abandonedcart/tools/capture.php', siteId: 's2', contactCapture: true, allowedProperties: ['1','2','3','4','5','6'] };
  // Reproduce a script executed out of Bitrix's combined cache bundle.
  Object.defineProperty(window.document, 'currentScript', { value: { src: 'https://example.test/bitrix/cache/js/s2/template/bundle.js' } });
  window.fetch = async (url, request) => {
    calls.push({ url, data: Object.fromEntries(request.body) });
    if (options.fetch) return options.fetch(url, request, calls.length);
    return { ok: true, json: async () => ({ ok: options.ok !== false }) };
  };
  window.eval(source);
  return {
    window, calls,
    event: name => (callbacks[name] || []).forEach(fn => fn()),
    tick: async delay => {
      const until = clock + delay;
      for (;;) {
        const next = [...timers].filter(([, timer]) => timer.due <= until).sort((a, b) => a[1].due - b[1].due)[0];
        if (!next) break;
        const [id, timer] = next;
        clock = timer.due;
        timers.delete(id);
        if (timer.interval) timers.set(id, { ...timer, due: clock + timer.interval });
        timer.fn();
        await new Promise(setImmediate);
      }
      clock = until;
      await new Promise(setImmediate);
    },
    close: () => window.close()
  };
}
test('combined script uses configured endpoint and site; numeric properties, contacts, location and service IDs captured', async () => {
  const app = setup();
  try {
    await app.tick(900);
    const data = app.calls[0].data;
    assert.equal(app.calls[0].url, '/bitrix/modules/pv.abandonedcart/tools/capture.php');
    assert.equal(data.site_id, 's2');
    assert.deepEqual(JSON.parse(data.properties), { 1: 'Иван Иванов', 2: 'test@example.test', 3: '+79991234567', 4: '0000073738', 5: '190000' });
    assert.equal(data.email, 'test@example.test');
    assert.equal(data.delivery, '3'); assert.equal(data.payment, '2');
    assert.equal(data.sessid, 'session');
    assert.ok(!JSON.stringify(data).includes('secret'));
    app.event('onAjaxSuccess'); await app.tick(900);
    assert.equal(app.calls.length, 1, 'successful payload deduplicated');
    app.window.document.querySelector('[name="ORDER_PROP_2"]').value = 'new@example.test';
    app.event('onSaleOrderAjaxSuccess'); await app.tick(900);
    assert.equal(app.calls[1].data.email, 'new@example.test');
  } finally { app.close(); }
});
test('unchecked consent blocks both initial capture and background synchronization', async () => {
  const app = setup({ consent: false });
  try { await app.tick(30000); assert.equal(app.calls.length, 0); } finally { app.close(); }
});
test('HTTP 200 ok:false is retried automatically without another input event', async () => {
  const app = setup({ ok: false });
  try { await app.tick(10000); assert.equal(app.calls.length, 2); } finally { app.close(); }
});
test('network failure recovers automatically', async () => {
  const app = setup({ fetch: async (url, request, count) => {
    if (count === 1) throw new Error('offline');
    return { ok: true, json: async () => ({ ok: true }) };
  } });
  try { await app.tick(20000); assert.equal(app.calls.length, 2); } finally { app.close(); }
});
test('autofill, DOM replacement and clearing a field synchronize without input events', async () => {
  const app = setup();
  try {
    await app.tick(900);
    app.window.document.querySelector('[name="ORDER_PROP_2"]').value = 'autofill@example.test';
    await app.tick(5000);
    assert.equal(app.calls[1].data.email, 'autofill@example.test');
    app.window.document.querySelector('[name="ORDER_PROP_3"]').outerHTML = '<input id="soa-property-3" value="+79990000000">';
    await app.tick(5000);
    assert.equal(JSON.parse(app.calls[2].data.properties)[3], '+79990000000');
    app.window.document.querySelector('[name="ORDER_PROP_2"]').value = '';
    await app.tick(5000);
    assert.equal(JSON.parse(app.calls[3].data.properties)[2], '');
    assert.equal(app.calls[3].data.email, '');
  } finally { app.close(); }
});
test('slow request queues latest form snapshot instead of sending concurrent stale copies', async () => {
  let resolveFirst;
  const app = setup({ fetch: (url, request, count) => count === 1
    ? new Promise(resolve => { resolveFirst = resolve; })
    : Promise.resolve({ ok: true, json: async () => ({ ok: true }) }) });
  try {
    await app.tick(900);
    app.window.document.querySelector('[name="ORDER_PROP_2"]').value = 'latest@example.test';
    app.event('onAjaxSuccess'); await app.tick(6000);
    assert.equal(app.calls.length, 1);
    resolveFirst({ ok: true, json: async () => ({ ok: true }) });
    await new Promise(setImmediate);
    await app.tick(900);
    assert.equal(app.calls.length, 2);
    assert.equal(app.calls[1].data.email, 'latest@example.test');
  } finally { app.close(); }
});
test('duplicate script execution registers only one synchronization loop', async () => {
  const app = setup();
  try { app.window.eval(source); await app.tick(20000); assert.equal(app.calls.length, 1); } finally { app.close(); }
});

test('switching from checkout to the admin tab flushes pending edits', async () => {
  const app = setup();
  try {
    await app.tick(900);
    const email = app.window.document.querySelector('[name="ORDER_PROP_2"]');
    email.value = 'before-switch@example.test';
    email.dispatchEvent(new app.window.Event('input', { bubbles: true }));
    Object.defineProperty(app.window.document, 'hidden', { value: true });
    app.window.document.dispatchEvent(new app.window.Event('visibilitychange'));
    await new Promise(setImmediate);
    assert.equal(app.calls.length, 2);
    assert.equal(app.calls[1].data.email, 'before-switch@example.test');
  } finally { app.close(); }
});
test('hung request is aborted and synchronization can resume', async () => {
  const app = setup({ fetch: (url, request, count) => count === 1
    ? new Promise((resolve, reject) => request.signal.addEventListener('abort', () => reject(new Error('aborted'))))
    : Promise.resolve({ ok: true, json: async () => ({ ok: true }) }) });
  try { await app.tick(30000); assert.equal(app.calls.length, 2); } finally { app.close(); }
});

test('contact capture is opt-in and requires an explicit consent control', async () => {
  const app = setup();
  try {
    app.window.PVAbandonedCartConfig.contactCapture = false;
    await app.tick(10000); assert.equal(app.calls.length, 0);
    app.window.PVAbandonedCartConfig.contactCapture = true;
    app.window.document.querySelector('[name="USER_CONSENT"]').remove();
    await app.tick(10000); assert.equal(app.calls.length, 0);
  } finally { app.close(); }
});
test('unapproved numeric checkout properties are never transmitted, even without a descriptive label', async () => {
  const app = setup();
  try {
    const input = app.window.document.querySelector('[name="ORDER_PROP_8"]');
    input.closest('.form-group').querySelector('label').textContent = 'Дополнительное поле';
    await app.tick(900);
    assert.ok(!Object.hasOwn(JSON.parse(app.calls[0].data.properties), '8'));
    assert.equal(app.calls[0].data.consent, 'Y');
  } finally { app.close(); }
});
