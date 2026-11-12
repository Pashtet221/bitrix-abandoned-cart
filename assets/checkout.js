(function () {
  'use strict';
  if (window.PVAbandonedCartStarted) return;
  window.PVAbandonedCartStarted = true;
  var timer, last = '', inFlight = false, retryAt = 0, failures = 0;
  function config() { return window.PVAbandonedCartConfig || {}; }
  function checkout() {
    return document.querySelector('#bx-soa-order-form, form[name="ORDER_FORM"], #bx-soa-order, [data-entity="order"], form[action*="order"]');
  }
  function text(el) { return el && typeof el.value === 'string' ? el.value.trim() : ''; }
  function fields(root) {
    var result = {}, properties = {};
    var inputs = root.querySelectorAll('input:not([type="password"]):not([type="file"]), textarea, select');
    var addresses = [];
    inputs.forEach(function (el) {
      if (el.disabled || (el.type === 'radio' || el.type === 'checkbox') && !el.checked) return;
      var property = (el.name || '').match(/^ORDER_PROP_(\d+)(?:\[\])?$/) || (el.id || '').match(/^soa-property-(\d+)$/);
      var label = el.closest('.bx-soa-customer-field, .form-group');
      var caption = label && label.querySelector('label, .bx-soa-custom-label');
      var name = ((caption ? caption.textContent : '') + ' ' + (el.name || '') + ' ' + (el.id || '') + ' ' + (el.getAttribute('placeholder') || '')).toLowerCase();
      var value = text(el);
      // Exclude payment card details, CVV, tokens and password fields.
      if (/card|cvv|cvc|pan\b|expiry|expire|password|парол|карт|срок.действ/.test(name)) return;
      if (property) properties[property[1]] = value;
      if (el.type === 'hidden') return;
      if (/email|e-mail|почт/.test(name)) result.email = value;
      else if (/phone|телефон|mobile/.test(name)) result.phone = value;
      else if (/fio|full.?name|контактное.лицо|фамил|имя|отчеств|(^|\W)name(\W|$)/.test(name)) result.name = result.name ? result.name + ' ' + value : value;
      else if (/comment|комментар|примечан/.test(name)) result.comment = value;
      else if (/address|адрес|street|улиц|квартир|дом\b|город|city|zip|индекс/.test(name)) addresses.push(value);
    });
    if (Object.keys(properties).length) result.properties = JSON.stringify(properties);
    if (addresses.length) result.address = addresses.filter(function (value) { return value !== ''; }).join(', ');
    var delivery = root.querySelector('input[name="DELIVERY_ID"]:checked, input[name*="DELIVERY"]:checked, select[name="DELIVERY_ID"], input[type="hidden"][name="DELIVERY_ID"]');
    var payment = root.querySelector('input[name="PAY_SYSTEM_ID"]:checked, input[name*="PAY_SYSTEM"]:checked, select[name="PAY_SYSTEM_ID"], input[type="hidden"][name="PAY_SYSTEM_ID"]');
    if (delivery) result.delivery = delivery.value;
    if (payment) result.payment = payment.value;
    return result;
  }
  function consentOk(root) {
    // Respect explicit consent controls when present. No capture before consent.
    var consents = root.querySelectorAll('input[type="checkbox"][name*="AGREEMENT"], input[type="checkbox"][name*="CONSENT"], input[type="checkbox"][name*="agreement"], input[type="checkbox"][name*="consent"]');
    return Array.prototype.every.call(consents, function (consent) { return consent.checked; });
  }
  function send(onLeave) {
    var root = checkout();
    if (inFlight || (Date.now() < retryAt && !onLeave) || !root || !consentOk(root) || !window.BX || !BX.bitrix_sessid) return;
    var data = fields(root);
    if (!Object.keys(data).length) return;
    // The site can differ from the default site bootstrapped by a /local/modules URL.
    data.site_id = config().siteId || '';
    var serialized = JSON.stringify(data);
    if (serialized === last) return;
    var form = new FormData();
    form.append('sessid', BX.bitrix_sessid());
    Object.keys(data).forEach(function (key) { form.append(key, data[key]); });
    inFlight = true;
    var controller = new AbortController();
    var timeout = setTimeout(function () { controller.abort(); }, 15000);
    // Never infer this URL from document.currentScript: Bitrix combines scripts into cache bundles.
    fetch(config().captureUrl || '/local/modules/pv.abandonedcart/tools/capture.php', {
      method: 'POST', body: form, credentials: 'same-origin', keepalive: true, signal: controller.signal
    }).then(function (r) { return r.ok ? r.json() : null; }).then(function (response) {
      if (!response || !response.ok) throw new Error('Capture unavailable');
      last = serialized;
      failures = 0;
      retryAt = 0;
    }).catch(function () {
      failures++;
      retryAt = Date.now() + Math.min(60000, 5000 * Math.pow(2, failures - 1));
    }).finally(function () {
      clearTimeout(timeout);
      inFlight = false;
      // Rescan the current form, rather than submitting an outdated queued copy.
      schedule();
    });
  }
  function schedule() { clearTimeout(timer); timer = setTimeout(send, 900); }
  document.addEventListener('input', function (event) { if (checkout() && checkout().contains(event.target)) schedule(); }, true);
  document.addEventListener('change', function (event) { if (checkout() && checkout().contains(event.target)) schedule(); }, true);
  document.addEventListener('focusout', function (event) { if (checkout() && checkout().contains(event.target)) schedule(); }, true);
  if (window.BX && BX.addCustomEvent) {
    BX.addCustomEvent('onAjaxSuccess', schedule);
    BX.addCustomEvent('onSaleOrderAjaxSuccess', schedule);
  }
  // Also catch autofill and DOM updates that emit neither input nor Bitrix events.
  setInterval(send, 5000);
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) { clearTimeout(timer); send(true); } else { schedule(); }
  });
  schedule();
  window.addEventListener('pagehide', function () { clearTimeout(timer); send(true); });
})();
