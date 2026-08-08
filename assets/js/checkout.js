/* ==========================================================================
   GOMEZ GARAGE STUDIO — checkout.js
   Checkout form -> Revolut embedded Checkout (all payment methods) or
   straight to order-success.html for Cash on Delivery.

   window.RevolutCheckout.embeddedCheckout({ publicToken, target,
   createOrder, onSuccess, onError, onCancel }) mounts an embedded widget
   (an iframe inside our own page, not a window.open() popup) that shows
   every payment method enabled on the Revolut Business account together
   (Revolut Pay, Card, Apple Pay, Google Pay) and calls our createOrder()
   callback itself, at the moment the customer actually commits to paying.
   Because it's embedded rather than a popup, there's no user-gesture /
   Safari-popup-blocker constraint to design around — a single click on
   "Continua spre plata" is enough.

   publicToken here is the *merchant* public key (REVOLUT_PUBLIC_KEY,
   fetched from api/get-checkout-config.php — config.php isn't reachable
   from this static frontend), not an order's public_id — that's created
   lazily inside createOrder() from api/create-order.php.

   Verified against the published @revolut/checkout package's type
   definitions: embeddedCheckout is real (RevolutCheckout.embeddedCheckout,
   a static property on the loaded RevolutCheckout function). instance.open()
   and instance.mount() are not — calling either throws at runtime.
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

  // Filled in by preloadCheckoutConfig(); used when mounting the widget.
  let revolutPublicKey = null;
  let revolutMode = 'prod';

  // The order_number of the order created by the widget's createOrder()
  // callback, used to build the redirect URLs (Revolut's own callback
  // payloads carry Revolut's internal orderId, not ours).
  let lastOrderNumber = null;

  // Current mounted widget instance, so a second submit can clean up the
  // previous one before mounting a fresh one.
  let embeddedInstance = null;

  function shippingZoneFor(countryCode) {
    if (countryCode === 'RO') return 'RO';
    if (countryCode === 'GB') return 'UK';
    return 'EU';
  }

  function money(priceEur) {
    return window.GGSCurrency ? window.GGSCurrency.format(priceEur) : `€${priceEur}`;
  }

  function preloadCheckoutConfig() {
    return fetch(`${API_BASE}/get-checkout-config.php`)
      .then((res) => res.json())
      .then((data) => {
        if (data.success) {
          revolutPublicKey = data.public_key;
          revolutMode = data.mode;
        }
      })
      .catch(() => {
        // openRevolutEmbedded() re-checks revolutPublicKey and surfaces an
        // inline error if it's still missing by the time of submit.
      });
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
    preloadCheckoutConfig();

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

      // Switching away from Online should tear down any mounted widget
      // from a previous attempt.
      if (method !== 'online') resetPaymentWidget();
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
    resetPaymentWidget();
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
      // Charge in whatever currency the customer was just shown in the
      // order summary, so the payment form matches (not always EUR).
      currency: window.GGSCurrency ? window.GGSCurrency.current : 'EUR',
    };

    if (method === 'cod') {
      fetch(`${API_BASE}/create-order.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      })
        .then((res) => res.json())
        .then((data) => {
          if (!data.success) throw new Error(data.error || 'Order creation failed');
          window.GGSCart.clearCart();
          window.location.href = `/studio/order-success.html?order=${data.order_number}`;
        })
        .catch(() => {
          resetSubmitButton();
          showCheckoutError(
            "We couldn't place your order. Please check your details and try again.",
            'Nu am putut plasa comanda. Verifica datele si incearca din nou.'
          );
        });
      return;
    }

    // Online: mount the embedded widget right here. The order itself is
    // created lazily by createOrder() below, called by the widget once the
    // customer actually picks a payment method — not before.
    mountEmbeddedCheckout(payload);
  }

  /* ======================= Embedded Revolut Checkout ======================= */

  function resetPaymentWidget() {
    if (embeddedInstance && typeof embeddedInstance.destroy === 'function') {
      embeddedInstance.destroy();
    }
    embeddedInstance = null;
    lastOrderNumber = null;
    const container = document.getElementById('revolut-widget-container');
    if (container) container.classList.add('hidden');
    const target = document.getElementById('revolut-embedded-target');
    if (target) target.innerHTML = '';
  }

  function mountEmbeddedCheckout(payload) {
    if (!revolutPublicKey || !window.RevolutCheckout || !window.RevolutCheckout.embeddedCheckout) {
      resetSubmitButton();
      showCheckoutError(
        'Unable to reach the payment service. Please refresh and try again.',
        'Serviciul de plata nu este disponibil. Reincarca pagina si incearca din nou.'
      );
      return;
    }

    const container = document.getElementById('revolut-widget-container');
    const target = document.getElementById('revolut-embedded-target');
    if (container) container.classList.remove('hidden');

    window.RevolutCheckout.embeddedCheckout({
      publicToken: revolutPublicKey,
      mode: revolutMode,
      target,
      email: payload.email,
      createOrder() {
        return fetch(`${API_BASE}/create-order.php`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
        })
          .then((res) => res.json())
          .then((data) => {
            if (!data.success || !data.public_id) throw new Error(data.error || 'Order creation failed');
            lastOrderNumber = data.order_number;
            return { publicId: data.public_id };
          });
      },
      onSuccess() {
        window.GGSCart.clearCart();
        window.location.href = `/studio/order-success.html?order=${lastOrderNumber || ''}`;
      },
      onError(errPayload) {
        console.error('Revolut error:', errPayload && errPayload.error);
        window.location.href = `/studio/order-failed.html?order=${lastOrderNumber || ''}`;
      },
      onCancel() {
        // Customer backed out without paying — tear down the widget and
        // re-enable the submit button so they can retry.
        resetPaymentWidget();
        resetSubmitButton();
      },
    })
      .then((instance) => {
        embeddedInstance = instance;
      })
      .catch(() => {
        resetSubmitButton();
        showCheckoutError(
          "We couldn't load the payment form. Please refresh and try again.",
          'Nu am putut incarca formularul de plata. Reincarca pagina si incearca din nou.'
        );
      });
  }

  document.addEventListener('DOMContentLoaded', initCheckoutPage);
})();
