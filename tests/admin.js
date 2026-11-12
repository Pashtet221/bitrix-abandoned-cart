'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const { mkdtempSync, mkdirSync, writeFileSync, rmSync } = require('node:fs');
const { tmpdir } = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { JSDOM } = require('jsdom');
test('admin renders meaningful labels and refreshes cart data without resetting edited settings', async () => {
  const root = mkdtempSync(path.join(tmpdir(), 'pv-cart-admin-'));
  let dom;
  try {
    const bootstrap = path.join(root, 'bitrix/modules/main/include');
    mkdirSync(bootstrap, { recursive: true });
    for (const name of ['prolog_admin_before.php','prolog_admin_after.php','epilog_admin.php']) writeFileSync(path.join(bootstrap, name), '<?php');
    function render(mode, email) {
      const result = spawnSync(process.env.PHP_BIN || 'php', [path.join(__dirname, 'admin-fixture.php'), mode], {
        encoding: 'utf8', env: { ...process.env, PV_TEST_DOCUMENT_ROOT: root, PV_TEST_EMAIL: email }
      });
      assert.equal(result.status, 0, result.stderr || result.stdout);
      return result.stdout;
    }
    const html = render('normal', 'first@example.test');
    dom = new JSDOM(html, { runScripts: 'outside-only', pretendToBeVisual: true, url: 'https://example.test/bitrix/admin/pv_abandonedcart_list.php?status=all' });
    const { window } = dom;
    assert.ok(window.document.body.textContent.includes('Владелец корзины №42'));
    assert.ok(window.document.body.textContent.includes('Ещё не оформлен'));
    assert.ok(window.document.body.textContent.includes('Данные формы обновлены:'));
    assert.equal(window.document.querySelectorAll('#pv-cart-live script').length, 0, 'Customer HTML escaped');
    const settings = window.document.querySelector('[name="timeout_minutes"]');
    settings.value = '99';
    let refresh;
    window.setInterval = fn => { refresh = fn; };
    window.fetch = async url => {
      const request = new URL(url);
      assert.equal(request.searchParams.get('pv_refresh'), 'Y');
      assert.equal(request.searchParams.get('status'), 'all');
      return { ok: true, json: async () => JSON.parse(render('refresh', 'updated@example.test')) };
    };
    window.eval(window.document.querySelector('script').textContent);
    refresh(); await new Promise(setImmediate);
    assert.ok(window.document.getElementById('pv-cart-live').textContent.includes('updated@example.test'));
    assert.ok(!window.document.getElementById('pv-cart-live').textContent.includes('first@example.test'));
    assert.equal(window.document.querySelector('[name="timeout_minutes"]'), settings);
    assert.equal(settings.value, '99');
  } finally {
    if (dom) dom.window.close();
    rmSync(root, { recursive: true, force: true });
  }
});
