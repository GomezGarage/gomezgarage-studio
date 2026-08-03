/* ==========================================================================
   GOMEZ GARAGE STUDIO — checkout.js
   Checkout form -> create-order.php -> Revolut Checkout Widget (embedded)
   ========================================================================== */

(function () {
  'use strict';

  const API_BASE = '/studio/api';

  const SHIPPING_RATES = {
    RO: { eur: 5, label: 'Romania' },
    EU: { eur: 12, label: 'European Union' },
    UK: { eur: 18, label: 'United Kingdom' },
  };

  const EU_COUNTRIES = ['AT','BE','BG','HR','CY','CZ','DK','EE','FI','FR','DE','GR','HU','IE','IT','LV','LT','LU','MT','NL','PL','PT','RO','SK','SI','ES','SE'];

  function shippingZoneFor(countryCode) {
    if (countryCode === 'RO') return 'RO';
    if (countryCode === 'GB') return 'UK';
    return 'EU';
  }

  function money(priceEur) {
    return window.GGSCurrency ? window.GGSCurrency.format(priceEur) : `€${priceEur}`;
  }

  function initCheckoutPage() {
    const form = document.querySelector('[data-checkout-form]');
    if (!form) return;

    const cart = window.GGSCart.readCart();
    if (!cart.length) {
      window.location.href = '/studio/cart.html';
      return;
    }

    renderOrderSummary(cart);

    const countrySelect = form.querySelector('[name="country"]');
    countrySelect.addEventListener('change', () => renderOrderSummary(cart));

    form.addEventListener('submit', (e) => {
      e.preventDefault();
      submitOrder(form, cart);
    });
  }

  function currentShippingEur(form) {
    const country = form ? form.querySelector('[name="country"]').value : 'RO';
    const zone = shippingZoneFor(country);
    return SHIPPING_RATES[zone].eur;
  }

  function renderOrderSummary(cart) {
    const container = document.querySelector('[data-order-summary]');
    if (!container) return;
    const form = document.querySelector('[data-checkout-form]');
    const subtotal = cart.reduce((sum, i) => sum + i.qty * Number(i.price_eur), 0);
    const shipping = currentShippingEur(form);
    const total = subtotal + shipping;

    container.innerHTML = `
      <div class="order-summary-mini">
        ${cart.map((i) => `<div class="summary-item"><span>${window.GGSUtil.escapeHtml(i.name_en)} × ${i.qty}</span><span>${money(i.qty * i.price_eur)}</span></div>`).join('')}
        <div class="summary-row"><span data-en="Subtotal" data-ro="Subtotal">Subtotal</span><span>${money(subtotal)}</span></div>
        <div class="summary-row"><span data-en="Shipping" data-ro="Livrare">Shipping</span><span>${money(shipping)}</span></div>
        <div class="summary-row total"><span data-en="Total" data-ro="Total">Total</span><span>${money(total)}</span></div>
      </div>
    `;
  }

  function submitOrder(form, cart) {
    const submitBtn = form.querySelector('[type="submit"]');
    submitBtn.disabled = true;
    submitBtn.textContent = 'Processing…';

    const formData = new FormData(form);
    const payload = {
      first_name: formData.get('first_name'),
      last_name: formData.get('last_name'),
      email: formData.get('email'),
      phone: formData.get('phone') || '',
      street: formData.get('street'),
      city: formData.get('city'),
      postal: formData.get('postal'),
      country: formData.get('country'),
      note: formData.get('note') || '',
      items: cart.map((i) => ({ product_id: i.product_id, name: i.name_en, price_eur: i.price_eur, qty: i.qty })),
      shipping_eur: currentShippingEur(form),
    };

    fetch(`${API_BASE}/create-order.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    })
      .then((res) => res.json())
      .then((data) => {
        if (!data.success) throw new Error(data.error || 'Order creation failed');
        initRevolutWidget(data);
      })
      .catch(() => {
        window.location.href = '/studio/order-failed.html';
      });
  }

  /*
   * Loads the Revolut Checkout Widget (RevolutCheckout JS) and mounts it
   * against the public order id returned by create-order.php.
   * Docs: https://developer.revolut.com/docs/merchant/accept-payments/embedded-checkout
   */
  function initRevolutWidget(order) {
    const container = document.getElementById('revolut-widget-container');
    container.textContent = '';

    const script = document.createElement('script');
    script.src = 'https://merchant.revolut.com/embed.js';
    script.onload = () => {
      if (!window.RevolutCheckout) {
        container.textContent = 'Unable to load payment widget.';
        return;
      }
      window.RevolutCheckout(order.public_id, order.mode || 'prod').then((instance) => {
        instance.payWithPopup({
          onSuccess() {
            window.GGSCart.clearCart();
            window.location.href = `/studio/order-success.html?order=${order.order_number}`;
          },
          onError() {
            window.location.href = `/studio/order-failed.html?order=${order.order_number}`;
          },
        });
      });
    };
    script.onerror = () => {
      container.textContent = 'Unable to reach Revolut payment service.';
    };
    document.body.appendChild(script);
  }

  document.addEventListener('DOMContentLoaded', initCheckoutPage);
})();
