/* ==========================================================================
   GOMEZ GARAGE STUDIO — checkout.js
   Checkout form -> create-order.php -> Revolut Checkout (payWithPopup)
   or straight to order-success.html for Cash on Delivery.

   Single-click flow: embed.js is preloaded via a <script defer> tag in
   checkout.html so window.RevolutCheckout is available up front. On submit
   we validate, create the order, then open payWithPopup() immediately in
   the same callback — no separate "Pay Now" step. This still opens the
   popup from inside the create-order.php fetch's .then(), which is a real
   async network round trip; Safari/mobile can still block it despite the
   script being preloaded, since the gap that matters is the order-creation
   request, not the script load.
   ========================================================================== */

(function () {
  'use strict';

  const API_BASE = '/studio/api';
  const REQUIRED_FIELDS = ['first_name', 'last_name', 'email', 'street', 'city', 'postal', 'country'];

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
    document.addEventListener('ggs:currency-changed', () => renderOrderSummary(cart));

    const countrySelect = form.querySelector('[name="country"]');
    countrySelect.addEventListener('change', () => renderOrderSummary(cart));

    initPaymentMethodSelector(form);

    form.addEventListener('submit', (e) => {
      e.preventDefault();
      if (!validateCheckoutForm(form)) return;
      submitOrder(form, cart, getSelectedPaymentMethod(form));
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
    if (window.GGSLang) window.GGSLang.refresh();
  }

  /* ======================= Payment method selector ======================= */

  function getSelectedPaymentMethod(form) {
    const checked = form.querySelector('input[name="payment_method"]:checked');
    return checked && checked.value === 'cod' ? 'cod' : 'online';
  }

  function initPaymentMethodSelector(form) {
    const cards = form.querySelectorAll('[data-payment-option]');
    const codNote = document.getElementById('cod-note');
    const submitBtn = document.getElementById('checkout-submit-btn');

    function updateUI() {
      const method = getSelectedPaymentMethod(form);

      cards.forEach((card) => {
        card.classList.toggle('active', card.getAttribute('data-payment-option') === method);
      });

      if (codNote) codNote.classList.toggle('hidden', method !== 'cod');

      if (submitBtn) {
        submitBtn.setAttribute('data-en', method === 'cod' ? 'Place Order' : 'Continue to Payment');
        submitBtn.setAttribute('data-ro', method === 'cod' ? 'Plaseaza Comanda' : 'Continua spre plata');
        submitBtn.textContent = document.documentElement.lang === 'ro'
          ? submitBtn.getAttribute('data-ro')
          : submitBtn.getAttribute('data-en');
      }
    }

    cards.forEach((card) => {
      card.addEventListener('click', () => {
        const radio = card.querySelector('input[type="radio"]');
        radio.checked = true;
        updateUI();
      });
    });

    updateUI();
  }

  /* ======================= Validation ======================= */

  function clearFieldErrors(form) {
    form.querySelectorAll('.field-error').forEach((el) => el.remove());
    form.querySelectorAll('.field-invalid').forEach((el) => el.classList.remove('field-invalid'));
  }

  function showFieldError(input, messageEn, messageRo) {
    input.classList.add('field-invalid');
    const error = document.createElement('div');
    error.className = 'field-error';
    error.setAttribute('data-en', messageEn);
    error.setAttribute('data-ro', messageRo);
    error.textContent = document.documentElement.lang === 'ro' ? messageRo : messageEn;
    input.insertAdjacentElement('afterend', error);
  }

  function validateCheckoutForm(form) {
    clearFieldErrors(form);
    let valid = true;

    REQUIRED_FIELDS.forEach((name) => {
      const input = form.querySelector(`[name="${name}"]`);
      if (!input) return;
      if (!input.value || !input.value.trim()) {
        showFieldError(input, 'This field is required.', 'Acest camp este obligatoriu.');
        valid = false;
      }
    });

    const emailInput = form.querySelector('[name="email"]');
    if (emailInput && emailInput.value.trim() && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailInput.value.trim())) {
      showFieldError(emailInput, 'Enter a valid email address.', 'Introdu o adresa de email valida.');
      valid = false;
    }

    if (!valid) {
      const firstInvalid = form.querySelector('.field-invalid');
      if (firstInvalid) firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    return valid;
  }

  /* ======================= Inline order-creation error ======================= */

  function showCheckoutError(messageEn, messageRo) {
    const el = document.getElementById('checkout-order-error');
    if (!el) return;
    el.setAttribute('data-en', messageEn);
    el.setAttribute('data-ro', messageRo);
    el.textContent = document.documentElement.lang === 'ro' ? messageRo : messageEn;
    el.classList.remove('hidden');
  }

  function hideCheckoutError() {
    const el = document.getElementById('checkout-order-error');
    if (el) el.classList.add('hidden');
  }

  function resetSubmitButton() {
    const submitBtn = document.getElementById('checkout-submit-btn');
    if (!submitBtn) return;
    submitBtn.disabled = false;
    submitBtn.textContent = document.documentElement.lang === 'ro'
      ? submitBtn.getAttribute('data-ro')
      : submitBtn.getAttribute('data-en');
  }

  /* ======================= Submit ======================= */

  function submitOrder(form, cart, method) {
    const submitBtn = document.getElementById('checkout-submit-btn');
    hideCheckoutError();
    submitBtn.disabled = true;
    submitBtn.textContent = document.documentElement.lang === 'ro' ? 'Se proceseaza…' : 'Processing…';

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
      payment_method: method === 'cod' ? 'cod' : 'card',
    };

    fetch(`${API_BASE}/create-order.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    })
      .then((res) => res.json())
      .then((data) => {
        if (!data.success) throw new Error(data.error || 'Order creation failed');

        if (method === 'cod') {
          window.GGSCart.clearCart();
          window.location.href = `/studio/order-success.html?order=${data.order_number}`;
          return;
        }

        openRevolutPopup(data);
      })
      .catch(() => {
        // Order creation failed (network/validation/server error) — this is
        // not a payment failure, so stay on the page: show an inline error
        // and let the customer fix things and retry, instead of bouncing
        // them to order-failed.html.
        resetSubmitButton();
        showCheckoutError(
          "We couldn't place your order. Please check your details and try again.",
          'Nu am putut plasa comanda. Verifica datele si incearca din nou.'
        );
      });
  }

  /*
   * Opens the Revolut payment popup for a just-created order. embed.js is
   * preloaded (defer) in checkout.html, so window.RevolutCheckout should
   * already be defined here.
   * Docs: https://developer.revolut.com/docs/merchant/accept-payments
   */
  function openRevolutPopup(order) {
    if (!window.RevolutCheckout) {
      resetSubmitButton();
      showCheckoutError(
        'Unable to reach the payment service. Please refresh and try again.',
        'Serviciul de plata nu este disponibil. Reincarca pagina si incearca din nou.'
      );
      return;
    }

    window.RevolutCheckout(order.public_id, order.mode || 'prod').then((instance) => {
      instance.payWithPopup({
        onSuccess() {
          window.GGSCart.clearCart();
          window.location.href = `/studio/order-success.html?order=${order.order_number}`;
        },
        onError(message) {
          console.error('Revolut error:', message);
          window.location.href = `/studio/order-failed.html?order=${order.order_number}`;
        },
        onCancel() {
          // Customer closed the popup without paying — re-enable the
          // submit button so they can retry (a fresh order is created).
          resetSubmitButton();
        },
      });
    });
  }

  document.addEventListener('DOMContentLoaded', initCheckoutPage);
})();
