(function () {
  'use strict';
  var timer, last = '';
  var script = document.currentScript;
  var captureUrl = script && script.src ? new URL('../tools/capture.php', script.src).pathname : '/local/modules/pv.abandonedcart/tools/capture.php';
  function checkout() {
    return document.querySelector('#bx-soa-order, [data-entity="order"], form[action*="order"]');
  }
  function text(el) { return el && typeof el.value === 'string' ? el.value.trim() : ''; }
  function fields(root) {
    var result = {}, properties = {};
    var inputs = root.querySelectorAll('input:not([type="password"]):not([type="file"]), textarea, select');
    var addresses = [];
    inputs.forEach(function (el) {
      if (el.disabled || (el.type === 'radio' || el.type === 'checkbox') && !el.checked) return;
      var property = (el.name || '').match(/^ORDER_PROP_(\d+)(?:\[\])?$/);
      var label = el.closest('.bx-soa-customer-field, .form-group');
      var caption = label && label.querySelector('label, .bx-soa-custom-label');
      var name = ((caption ? caption.textContent : '') + ' ' + (el.name || '') + ' ' + (el.id || '') + ' ' + (el.getAttribute('placeholder') || '')).toLowerCase();
      var value = text(el);
      if (!value) return;
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
    if (addresses.length) result.address = addresses.join(', ');
    var delivery = root.querySelector('input[name="DELIVERY_ID"]:checked, input[name*="DELIVERY"]:checked, select[name="DELIVERY_ID"], input[type="hidden"][name="DELIVERY_ID"]');
    var payment = root.querySelector('input[name="PAY_SYSTEM_ID"]:checked, input[name*="PAY_SYSTEM"]:checked, select[name="PAY_SYSTEM_ID"], input[type="hidden"][name="PAY_SYSTEM_ID"]');
    if (delivery) result.delivery = delivery.value;
    if (payment) result.payment = payment.value;
    return result;
  }
  function consentOk(root) {
    // Respect explicit consent controls when present. No capture before consent.
    var consent = root.querySelector('input[type="checkbox"][name*="AGREEMENT"], input[type="checkbox"][name*="CONSENT"], input[type="checkbox"][name*="agreement"], input[type="checkbox"][name*="consent"]');
    return !consent || consent.checked;
  }
  function send() {
    var root = checkout();
    if (!root || !consentOk(root) || !window.BX || !BX.bitrix_sessid) return;
    var data = fields(root);
    if (!Object.keys(data).length) return;
    var serialized = JSON.stringify(data);
    if (serialized === last) return;
    var form = new FormData();
    form.append('sessid', BX.bitrix_sessid());
    Object.keys(data).forEach(function (key) { form.append(key, data[key]); });
    fetch(captureUrl, {
      method: 'POST', body: form, credentials: 'same-origin', keepalive: true
    }).then(function (r) { return r.ok ? r.json() : null; }).then(function (response) { if (response && response.ok) last = serialized; }).catch(function () {});
  }
  function schedule() { clearTimeout(timer); timer = setTimeout(send, 900); }
  document.addEventListener('input', function (event) { if (checkout() && checkout().contains(event.target)) schedule(); }, true);
  document.addEventListener('change', function (event) { if (checkout() && checkout().contains(event.target)) schedule(); }, true);
  document.addEventListener('focusout', function (event) { if (checkout() && checkout().contains(event.target)) schedule(); }, true);
  if (window.BX && BX.addCustomEvent) {
    BX.addCustomEvent('onAjaxSuccess', schedule);
    BX.addCustomEvent('onSaleOrderAjaxSuccess', schedule);
  }
  schedule();
  window.addEventListener('pagehide', function () { clearTimeout(timer); send(); });
})();
