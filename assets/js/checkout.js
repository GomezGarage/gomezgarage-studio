/* ==========================================================================
   GOMEZ GARAGE STUDIO — checkout.js
   Checkout form -> Revolut embedded Checkout (online payment methods only).

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
  const REQUIRED_FIELDS = ['first_name', 'last_name', 'email', 'phone', 'street', 'city', 'postal', 'country'];

  const SHIPPING_RATES = {
    RO: { eur: 5, label: 'Romania' },
    EU: { eur: 12, label: 'European Union' },
    UK: { eur: 18, label: 'United Kingdom' },
  };

  const EU_COUNTRIES = ['AT','BE','BG','HR','CY','CZ','DK','EE','FI','FR','DE','GR','HU','IE','IT','LV','LT','LU','MT','NL','PL','PT','RO','SK','SI','ES','SE'];

  // Filled in by preloadCheckoutConfig(); used when mounting the widget.
  let revolutPublicKey = null;
  let revolutMode = 'prod';
  let customerCsrfToken = '';
  let samedayEnabled = false;
  let samedayQuote = null;
  let selectedEasybox = null;
  let currentCart = [];

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
          samedayEnabled = Boolean(data.sameday_enabled);
          const form = document.querySelector('[data-checkout-form]');
          if (form) {
            updateSamedayVisibility(form);
            renderOrderSummary(currentCart);
          }
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
    currentCart = cart;
    if (!cart.length) {
      window.location.href = '/studio/cart.html';
      return;
    }

    renderOrderSummary(cart);
    document.addEventListener('ggs:currency-changed', () => renderOrderSummary(cart));
    const customerContextReady = preloadCustomerContext(form);

    const countrySelect = form.querySelector('[name="country"]');
    countrySelect.addEventListener('change', () => {
      updateFiscalFieldRequirements(form);
      invalidateSamedayQuote(form);
      updateSamedayVisibility(form);
      renderOrderSummary(cart);
    });

    const customerTypeSelect = form.querySelector('[name="customer_type"]');
    if (customerTypeSelect) {
      customerTypeSelect.addEventListener('change', () => updateFiscalFieldRequirements(form));
    }
    updateFiscalFieldRequirements(form);
    initSamedayDelivery(form, cart);

    preloadCheckoutConfig();

    form.addEventListener('submit', (e) => {
      e.preventDefault();
      customerContextReady.finally(() => {
        if (!validateCheckoutForm(form)) return;
        submitOrder(form, cart);
      });
    });
  }

  function preloadCustomerContext(form) {
    return fetch(`${API_BASE}/customer-context.php`, { cache: 'no-store' })
      .then((res) => res.json())
      .then((data) => {
        customerCsrfToken = data.csrf_token || '';
        if (!data.authenticated || !data.customer) return;
        const splitName = String(data.customer.name || '').trim().split(/\s+/);
        setIfBlank(form, 'first_name', splitName.shift() || '');
        setIfBlank(form, 'last_name', splitName.join(' '));
        setIfBlank(form, 'email', data.customer.email || '');

        const addresses = Array.isArray(data.addresses) ? data.addresses : [];
        if (!addresses.length) return;
        const firstStep = form.querySelector('.checkout-step');
        const selectWrap = document.createElement('div');
        selectWrap.className = 'form-group checkout-saved-addresses';
        const label = document.createElement('label');
        label.setAttribute('data-en', 'Use a saved address');
        label.setAttribute('data-ro', 'Foloseste o adresa salvata');
        label.textContent = 'Use a saved address';
        const select = document.createElement('select');
        select.name = 'saved_address';
        const manual = document.createElement('option');
        manual.value = '';
        manual.setAttribute('data-en', 'Enter address manually');
        manual.setAttribute('data-ro', 'Completeaza manual');
        manual.textContent = document.documentElement.lang === 'ro' ? 'Completeaza manual' : 'Enter address manually';
        select.append(manual);
        addresses.forEach((address, index) => {
          const option = document.createElement('option');
          option.value = String(index);
          option.textContent = `${address.label} — ${address.street}, ${address.city}`;
          option.dataset.address = JSON.stringify(address);
          select.append(option);
          if (Number(address.is_default) === 1 && select.value === '') select.value = String(index);
        });
        selectWrap.append(label, select);
        const title = firstStep.querySelector('h3');
        if (title) title.insertAdjacentElement('afterend', selectWrap);
        else firstStep.prepend(selectWrap);

        function applyAddress(address) {
          if (!address) return;
          setIfBlank(form, 'phone', address.phone || '');
          form.querySelector('[name="street"]').value = address.street || '';
          form.querySelector('[name="city"]').value = address.city || '';
          form.querySelector('[name="county"]').value = address.county || '';
          form.querySelector('[name="postal"]').value = address.postal || '';
          form.querySelector('[name="country"]').value = address.country || 'RO';
          updateFiscalFieldRequirements(form);
          const recipientName = String(address.recipient_name || data.customer.name || '').trim().split(/\s+/);
          form.querySelector('[name="first_name"]').value = recipientName.shift() || '';
          form.querySelector('[name="last_name"]').value = recipientName.join(' ');
        }
        select.addEventListener('change', () => {
          if (select.value === '') return;
          try { applyAddress(JSON.parse(select.selectedOptions[0].dataset.address)); } catch (_) {}
        });
        if (select.value !== '') {
          try { applyAddress(JSON.parse(select.selectedOptions[0].dataset.address)); } catch (_) {}
        }
        if (window.GGSLang) window.GGSLang.refresh();
      })
      .catch(() => {});
  }

  function setIfBlank(form, name, value) {
    const field = form.querySelector(`[name="${name}"]`);
    if (field && !field.value && value) field.value = value;
  }

  function updateFiscalFieldRequirements(form) {
    const country = form.querySelector('[name="country"]');
    const county = form.querySelector('[name="county"]');
    if (county) county.required = Boolean(country && country.value === 'RO');

    const customerType = form.querySelector('[name="customer_type"]');
    const companyFields = form.querySelector('[data-company-fields]');
    const isCompany = Boolean(customerType && customerType.value === 'pj');
    if (companyFields) companyFields.classList.toggle('hidden', !isCompany);
    ['company_name', 'company_cui', 'company_address', 'company_city', 'company_county', 'company_postal', 'company_country'].forEach((name) => {
      const field = form.querySelector(`[name="${name}"]`);
      if (field) field.required = isCompany;
    });
    if (isCompany) {
      const copies = {
        company_address: 'street',
        company_city: 'city',
        company_county: 'county',
        company_postal: 'postal',
        company_country: 'country',
      };
      Object.entries(copies).forEach(([companyName, shippingName]) => {
        const target = form.querySelector(`[name="${companyName}"]`);
        const source = form.querySelector(`[name="${shippingName}"]`);
        if (target && source && !target.value.trim()) target.value = source.value;
      });
    }
  }

  function currentShippingEur(form) {
    const country = form ? form.querySelector('[name="country"]').value : 'RO';
    if (samedayEnabled && country === 'RO') return samedayQuote ? samedayQuote.amount_eur : null;
    const zone = shippingZoneFor(country);
    return SHIPPING_RATES[zone].eur;
  }

  function renderOrderSummary(cart) {
    const container = document.querySelector('[data-order-summary]');
    if (!container) return;
    const form = document.querySelector('[data-checkout-form]');
    const subtotal = cart.reduce((sum, i) => sum + i.qty * Number(i.price_eur), 0);
    const shipping = currentShippingEur(form);
    const total = subtotal + (shipping === null ? 0 : shipping);
    const shippingDisplay = shipping === null ? '—' : money(shipping);
    const totalDisplay = shipping === null ? money(subtotal) + ' +' : money(total);

    container.innerHTML = `
      <div class="order-summary-mini">
        ${cart.map((i) => `<div class="summary-item"><span>${window.GGSUtil.escapeHtml(i.name_en)} × ${i.qty}</span><span>${money(i.qty * i.price_eur)}</span></div>`).join('')}
        <div class="summary-row"><span data-en="Subtotal" data-ro="Subtotal">Subtotal</span><span>${money(subtotal)}</span></div>
        <div class="summary-row"><span data-en="Shipping" data-ro="Livrare">Shipping</span><span>${shippingDisplay}</span></div>
        <div class="summary-row total"><span data-en="Total" data-ro="Total">Total</span><span>${totalDisplay}</span></div>
      </div>
    `;
    if (window.GGSLang) window.GGSLang.refresh();
  }

  /* ======================= Validation ======================= */

  function updateSamedayVisibility(form) {
    const country = form.querySelector('[name="country"]');
    const section = form.querySelector('[data-sameday-delivery]');
    const badge = form.querySelector('[data-payment-step-badge]');
    const visible = samedayEnabled && country && country.value === 'RO';
    if (section) section.classList.toggle('hidden', !visible);
    if (badge) badge.textContent = visible ? '3' : '2';
  }

  function invalidateSamedayQuote(form) {
    samedayQuote = null;
    const status = form.querySelector('[data-sameday-quote-status]');
    if (status) {
      status.setAttribute('data-en', 'Complete the Romanian delivery address, then calculate the exact Sameday rate.');
      status.setAttribute('data-ro', 'Completeaza adresa din Romania, apoi calculeaza tariful exact Sameday.');
      status.textContent = document.documentElement.lang === 'ro' ? status.getAttribute('data-ro') : status.getAttribute('data-en');
    }
    renderOrderSummary(currentCart);
  }

  function initSamedayDelivery(form, cart) {
    const picker = form.querySelector('[data-easybox-picker]');
    const results = form.querySelector('[data-easybox-results]');
    const search = form.querySelector('[data-easybox-search]');
    const searchButton = form.querySelector('[data-easybox-search-btn]');
    const quoteButton = form.querySelector('[data-sameday-quote]');
    const oohInput = form.querySelector('[name="shipping_ooh_id"]');

    form.querySelectorAll('[name="shipping_method"]').forEach((radio) => radio.addEventListener('change', () => {
      form.querySelectorAll('[data-shipping-card]').forEach((card) => card.classList.toggle('active', card.dataset.shippingCard === radio.value));
      if (picker) picker.classList.toggle('hidden', radio.value !== 'easybox');
      invalidateSamedayQuote(form);
    }));

    ['street', 'city', 'county', 'postal', 'phone'].forEach((name) => {
      const input = form.querySelector(`[name="${name}"]`);
      if (input) input.addEventListener('input', () => invalidateSamedayQuote(form));
    });

    function renderLocations(locations) {
      if (!results) return;
      if (!locations.length) {
        results.innerHTML = '<p class="form-hint" data-en="No Easybox found. Try a city or a shorter search." data-ro="Nu am gasit Easybox. Incearca orasul sau o cautare mai scurta.">No Easybox found. Try a city or a shorter search.</p>';
        return;
      }
      results.innerHTML = locations.map((location) => `<button type="button" class="sameday-location-option" data-location-id="${location.id}"><strong>${window.GGSUtil.escapeHtml(location.name)}</strong><small>${window.GGSUtil.escapeHtml([location.address, location.city, location.county].filter(Boolean).join(', '))}</small></button>`).join('');
      results.querySelectorAll('[data-location-id]').forEach((button) => button.addEventListener('click', () => {
        selectedEasybox = locations.find((location) => String(location.id) === button.dataset.locationId) || null;
        if (!selectedEasybox) return;
        if (oohInput) oohInput.value = String(selectedEasybox.id);
        const selected = form.querySelector('[data-easybox-selected]');
        if (selected) {
          selected.innerHTML = `<strong>${window.GGSUtil.escapeHtml(selectedEasybox.name)}</strong><br><span>${window.GGSUtil.escapeHtml([selectedEasybox.address, selectedEasybox.city, selectedEasybox.county].filter(Boolean).join(', '))}</span>`;
          selected.classList.remove('hidden');
        }
        results.innerHTML = '';
        invalidateSamedayQuote(form);
      }));
    }

    function findEasyboxes() {
      const q = search ? search.value.trim() : '';
      const county = form.querySelector('[name="county"]')?.value.trim() || '';
      const city = form.querySelector('[name="city"]')?.value.trim() || '';
      const params = new URLSearchParams({ q, county, city, limit: '30' });
      if (results) results.innerHTML = '<p class="form-hint">Searching…</p>';
      fetch(`${API_BASE}/sameday-locations.php?${params}`)
        .then((response) => response.json())
        .then((data) => renderLocations(data.success && Array.isArray(data.locations) ? data.locations : []))
        .catch(() => renderLocations([]));
    }
    if (searchButton) searchButton.addEventListener('click', findEasyboxes);
    if (search) search.addEventListener('keydown', (event) => { if (event.key === 'Enter') { event.preventDefault(); findEasyboxes(); } });

    if (quoteButton) quoteButton.addEventListener('click', () => {
      const method = form.querySelector('[name="shipping_method"]:checked')?.value || 'home';
      const error = document.getElementById('sameday-quote-error');
      if (error) error.classList.add('hidden');
      if (method === 'easybox' && !selectedEasybox) {
        if (error) { error.textContent = document.documentElement.lang === 'ro' ? 'Alege un Easybox.' : 'Choose an Easybox.'; error.classList.remove('hidden'); }
        return;
      }
      const payload = {
        shipping_method: method,
        shipping_ooh_id: selectedEasybox ? selectedEasybox.id : null,
        customer_name: `${form.querySelector('[name="first_name"]').value} ${form.querySelector('[name="last_name"]').value}`.trim(),
        email: form.querySelector('[name="email"]').value,
        phone: form.querySelector('[name="phone"]').value,
        street: form.querySelector('[name="street"]').value,
        city: form.querySelector('[name="city"]').value,
        county: form.querySelector('[name="county"]').value,
        postal: form.querySelector('[name="postal"]').value,
        country: form.querySelector('[name="country"]').value,
        items: cart.map((item) => ({ product_id: item.product_id, qty: item.qty })),
      };
      quoteButton.disabled = true;
      fetch(`${API_BASE}/sameday-quote.php`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
        .then((response) => response.json())
        .then((data) => {
          if (!data.success) throw new Error(data.error || 'Quote failed');
          samedayQuote = data;
          const status = form.querySelector('[data-sameday-quote-status]');
          if (status) {
            status.setAttribute('data-en', `Exact Sameday rate: ${Number(data.amount).toFixed(2)} ${data.currency}.`);
            status.setAttribute('data-ro', `Tarif Sameday exact: ${Number(data.amount).toFixed(2)} ${data.currency}.`);
            status.textContent = document.documentElement.lang === 'ro' ? status.getAttribute('data-ro') : status.getAttribute('data-en');
          }
          renderOrderSummary(cart);
        })
        .catch((requestError) => {
          samedayQuote = null;
          if (error) { error.textContent = requestError.message; error.classList.remove('hidden'); }
          renderOrderSummary(cart);
        })
        .finally(() => { quoteButton.disabled = false; });
    });
    updateSamedayVisibility(form);
  }

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

    const countryForInvoice = form.querySelector('[name="country"]');
    const countyInput = form.querySelector('[name="county"]');
    if (countryForInvoice && countryForInvoice.value === 'RO' && countyInput && !countyInput.value.trim()) {
      showFieldError(countyInput, 'County is required for Romanian fiscal invoices.', 'Judetul este obligatoriu pentru facturile din Romania.');
      valid = false;
    }

    const customerType = form.querySelector('[name="customer_type"]');
    if (customerType && customerType.value === 'pj') {
      ['company_name', 'company_cui', 'company_address', 'company_city', 'company_county', 'company_postal', 'company_country'].forEach((name) => {
        const input = form.querySelector(`[name="${name}"]`);
        if (input && !input.value.trim()) {
          showFieldError(input, 'This company field is required.', 'Acest camp al firmei este obligatoriu.');
          valid = false;
        }
      });
    }

    const emailInput = form.querySelector('[name="email"]');
    if (emailInput && emailInput.value.trim() && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailInput.value.trim())) {
      showFieldError(emailInput, 'Enter a valid email address.', 'Introdu o adresa de email valida.');
      valid = false;
    }

    const postalInput = form.querySelector('[name="postal"]');
    const countryInput = form.querySelector('[name="country"]');
    if (postalInput && postalInput.value.trim() && countryInput) {
      const postalValue = postalInput.value.trim();
      const isRomania = countryInput.value === 'RO';
      const postalIsValid = isRomania
        ? /^\d{6}$/.test(postalValue)
        : /^[a-zA-Z0-9]{3,}$/.test(postalValue);

      if (!postalIsValid) {
        showFieldError(
          postalInput,
          isRomania ? 'Romanian postal code must be exactly 6 digits.' : 'Enter a valid postal code (at least 3 letters/digits).',
          isRomania ? 'Codul postal trebuie sa aiba exact 6 cifre.' : 'Introdu un cod postal valid (minim 3 litere/cifre).'
        );
        valid = false;
      }
    }

    if (!valid) {
      const firstInvalid = form.querySelector('.field-invalid');
      if (firstInvalid) firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    const country = form.querySelector('[name="country"]');
    if (valid && samedayEnabled && country && country.value === 'RO') {
      const method = form.querySelector('[name="shipping_method"]:checked')?.value || 'home';
      if (method === 'easybox' && !selectedEasybox) {
        showCheckoutError('Choose an Easybox before continuing.', 'Alege un Easybox inainte de a continua.');
        valid = false;
      } else if (!samedayQuote) {
        showCheckoutError('Calculate the Sameday delivery rate before continuing.', 'Calculeaza tariful Sameday inainte de a continua.');
        valid = false;
      }
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

  function submitOrder(form, cart) {
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
      county: formData.get('county') || '',
      postal: formData.get('postal'),
      country: formData.get('country'),
      customer_type: formData.get('customer_type') === 'pj' ? 'pj' : 'pf',
      company_name: formData.get('company_name') || '',
      company_cui: formData.get('company_cui') || '',
      company_reg: formData.get('company_reg') || '',
      company_address: formData.get('company_address') || '',
      company_city: formData.get('company_city') || '',
      company_county: formData.get('company_county') || '',
      company_postal: formData.get('company_postal') || '',
      company_country: formData.get('company_country') || '',
      company_vat_payer: formData.get('company_vat_payer') === '1',
      note: formData.get('note') || '',
      items: cart.map((i) => ({ product_id: i.product_id, name: i.name_en, price_eur: i.price_eur, qty: i.qty })),
      shipping_eur: currentShippingEur(form),
      shipping_method: samedayEnabled && formData.get('country') === 'RO' ? (formData.get('shipping_method') || 'home') : 'standard',
      shipping_ooh_id: samedayEnabled && formData.get('country') === 'RO' && formData.get('shipping_method') === 'easybox' ? Number(formData.get('shipping_ooh_id')) : null,
      payment_method: 'card',
      // Charge in whatever currency the customer was just shown in the
      // order summary, so the payment form matches (not always EUR).
      currency: window.GGSCurrency ? window.GGSCurrency.current : 'EUR',
    };

    // Mount the embedded widget right here. The order itself is
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
          headers: { 'Content-Type': 'application/json', ...(customerCsrfToken ? { 'X-CSRF-Token': customerCsrfToken } : {}) },
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
