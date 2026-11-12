(function () {
  'use strict';
  var timer, last = '';
  function checkout() {
    return document.querySelector('#bx-soa-order, [data-entity="order"], form[action*="order"]');
  }
  function text(el) { return el && typeof el.value === 'string' ? el.value.trim() : ''; }
  function fields(root) {
    var result = {};
    var inputs = root.querySelectorAll('input:not([type="hidden"]):not([type="password"]):not([type="file"]), textarea, select');
    var addresses = [];
    inputs.forEach(function (el) {
      if (el.disabled || (el.type === 'radio' || el.type === 'checkbox') && !el.checked) return;
      var name = ((el.name || '') + ' ' + (el.id || '') + ' ' + (el.getAttribute('placeholder') || '')).toLowerCase();
      var value = text(el);
      if (!value) return;
      // Exclude payment card details, CVV, tokens and password fields.
      if (/card|cvv|cvc|pan\b|expiry|expire|password|парол|карт|срок.действ/.test(name)) return;
      if (/email|e-mail|почт/.test(name)) result.email = value;
      else if (/phone|телефон|mobile/.test(name)) result.phone = value;
      else if (/fio|full.?name|контактное.лицо|фамил|имя|отчеств|(^|\W)name(\W|$)/.test(name)) result.name = result.name ? result.name + ' ' + value : value;
      else if (/comment|комментар|примечан/.test(name)) result.comment = value;
      else if (/address|адрес|street|улиц|квартир|дом\b|город|city|zip|индекс/.test(name)) addresses.push(value);
    });
    if (addresses.length) result.address = addresses.join(', ');
    var delivery = root.querySelector('input[name="DELIVERY_ID"]:checked, input[name*="DELIVERY"]:checked');
    var payment = root.querySelector('input[name="PAY_SYSTEM_ID"]:checked, input[name*="PAY_SYSTEM"]:checked');
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
    fetch('/local/modules/pv.abandonedcart/tools/capture.php', {
      method: 'POST', body: form, credentials: 'same-origin', keepalive: true
    }).then(function (r) { if (r.ok) last = serialized; }).catch(function () {});
  }
  function schedule() { clearTimeout(timer); timer = setTimeout(send, 900); }
  document.addEventListener('input', function (event) { if (checkout() && checkout().contains(event.target)) schedule(); }, true);
  document.addEventListener('change', function (event) { if (checkout() && checkout().contains(event.target)) schedule(); }, true);
  document.addEventListener('focusout', function (event) { if (checkout() && checkout().contains(event.target)) schedule(); }, true);
  window.addEventListener('pagehide', function () { clearTimeout(timer); send(); });
})();
