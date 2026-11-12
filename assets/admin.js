(function () {
  'use strict';
  var root = document.getElementById('pv-cart-live');
  if (!root || root.dataset.initialized) return;
  root.dataset.initialized = 'true';
  var pending = false, submitting = false;
  function boxes() { return Array.from(root.querySelectorAll('[data-cart-id]')); }
  function updateSelection() {
    var inputs = boxes(), count = inputs.filter(function (input) { return input.checked; }).length;
    var all = root.querySelector('#pv-cart-select-all');
    all.checked = inputs.length > 0 && count === inputs.length;
    all.indeterminate = count > 0 && count < inputs.length;
    all.disabled = inputs.length === 0;
    root.querySelector('#pv-cart-selection-count').textContent = 'Выбрано: ' + count;
    root.querySelector('#pv-cart-delete').disabled = count === 0;
  }
  function expand(button, open) {
    button.setAttribute('aria-expanded', String(open));
    root.querySelector('#' + button.getAttribute('aria-controls')).hidden = !open;
  }
  root.addEventListener('click', function (event) {
    var button = event.target.closest('[data-cart-toggle]');
    if (button && root.contains(button)) expand(button, button.getAttribute('aria-expanded') !== 'true');
  });
  root.addEventListener('change', function (event) {
    if (event.target.id === 'pv-cart-select-all') {
      boxes().forEach(function (input) { input.checked = event.target.checked; });
    }
    updateSelection();
  });
  root.addEventListener('submit', function (event) {
    if (event.target.id !== 'pv-cart-bulk-form') return;
    var count = boxes().filter(function (input) { return input.checked; }).length;
    if (!count || !window.confirm('Удалить выбранные записи корзин (' + count + ')? Заказы магазина сохранятся.')) {
      event.preventDefault();
      return;
    }
    submitting = true;
    root.querySelector('#pv-cart-delete').disabled = true;
  });
  updateSelection();
  setInterval(function () {
    if (pending || submitting || document.hidden) return;
    pending = true;
    var url = new URL(window.location.href);
    url.searchParams.set('pv_refresh', 'Y');
    var controller = new AbortController();
    var timeout = setTimeout(function () { controller.abort(); }, 10000);
    fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store', signal: controller.signal })
      .then(function (response) { if (!response.ok) throw new Error('Refresh unavailable'); return response.json(); })
      .then(function (data) {
        if (submitting || typeof data.html !== 'string') return;
        // Read state when the response arrives so clicks made during the request are retained.
        var checked = new Set(boxes().filter(function (input) { return input.checked; }).map(function (input) { return input.dataset.cartId; }));
        var opened = new Set(Array.from(root.querySelectorAll('[data-cart-toggle][aria-expanded="true"]')).map(function (button) { return button.dataset.cartToggle; }));
        var focusId = root.contains(document.activeElement) ? document.activeElement.id : '';
        root.innerHTML = data.html;
        boxes().forEach(function (input) { input.checked = checked.has(input.dataset.cartId); });
        root.querySelectorAll('[data-cart-toggle]').forEach(function (button) { expand(button, opened.has(button.dataset.cartToggle)); });
        updateSelection();
        var focused = focusId && document.getElementById(focusId);
        if (focused) focused.focus({ preventScroll: true });
      }).catch(function () {}).finally(function () { clearTimeout(timeout); pending = false; });
  }, 10000);
})();
