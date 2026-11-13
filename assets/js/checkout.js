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
   Safari-popup-blocker constraint to design around. The widget is mounted
   automatically below the order summary as soon as checkout and delivery
   details are complete.

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
  let paymentRefreshTimer = null;
  let mountedPaymentSignature = '';
  let paymentMountInFlight = false;
  let paymentMountGeneration = 0;
  let easyboxMap = null;
  let easyboxMarkers = null;
  let easyboxMapRequest = 0;

  // The order_number of the order created by the widget's createOrder()
  // callback, used to build the redirect URLs (Revolut's own callback
  // payloads carry Revolut's internal orderId, not ours).
  let lastOrderNumber = null;

  // Current mounted widget instance, so a second submit can clean up the
  // previous one before mounting a fresh one.
  let embeddedInstance = null;

  function shippingZoneFor(countryCode) {
    if (countryCode === 'RO') return 'RO';
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
            schedulePaymentRefresh(form);
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
    document.addEventListener('ggs:currency-changed', () => {
      renderOrderSummary(cart);
      schedulePaymentRefresh(form);
    });
    const customerContextReady = preloadCustomerContext(form);

    const countrySelect = form.querySelector('[name="country"]');
    countrySelect.addEventListener('change', () => {
      updateFiscalFieldRequirements(form);
      invalidateSamedayQuote(form);
      updateSamedayVisibility(form);
      renderOrderSummary(cart);
      syncBillingAddress(form);
      schedulePaymentRefresh(form);
    });

    const customerTypeSelect = form.querySelector('[name="customer_type"]');
    if (customerTypeSelect) {
      customerTypeSelect.addEventListener('change', () => {
        updateFiscalFieldRequirements(form);
        schedulePaymentRefresh(form);
      });
    }
    initBillingAddress(form);
    updateFiscalFieldRequirements(form);
    initSamedayDelivery(form, cart);

    preloadCheckoutConfig();
    form.addEventListener('submit', (event) => event.preventDefault());
    form.addEventListener('input', () => {
      syncBillingAddress(form);
      schedulePaymentRefresh(form);
    });
    form.addEventListener('change', () => {
      syncBillingAddress(form);
      schedulePaymentRefresh(form);
    });
    customerContextReady.finally(() => {
      syncBillingAddress(form);
      schedulePaymentRefresh(form);
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

  function initBillingAddress(form) {
    const same = form.querySelector('[name="billing_same_as_shipping"]');
    const fields = form.querySelector('[data-billing-fields]');
    if (!same || !fields) return;
    const update = () => {
      fields.classList.toggle('hidden', same.checked);
      ['billing_street', 'billing_city', 'billing_county', 'billing_postal', 'billing_country'].forEach((name) => {
        const field = form.querySelector(`[name="${name}"]`);
        if (field) field.required = !same.checked;
      });
      syncBillingAddress(form);
      schedulePaymentRefresh(form);
    };
    same.addEventListener('change', update);
    update();
  }

  function syncBillingAddress(form) {
    const same = form.querySelector('[name="billing_same_as_shipping"]');
    if (!same || !same.checked) return;
    const copies = {
      billing_street: 'street',
      billing_city: 'city',
      billing_county: 'county',
      billing_postal: 'postal',
      billing_country: 'country',
    };
    Object.entries(copies).forEach(([billingName, shippingName]) => {
      const target = form.querySelector(`[name="${billingName}"]`);
      const source = form.querySelector(`[name="${shippingName}"]`);
      if (target && source) target.value = source.value;
    });
  }

  function billingAddressFromForm(form) {
    return {
      countryCode: form.querySelector('[name="billing_country"]')?.value || '',
      postcode: form.querySelector('[name="billing_postal"]')?.value.trim() || '',
      region: form.querySelector('[name="billing_county"]')?.value.trim() || '',
      city: form.querySelector('[name="billing_city"]')?.value.trim() || '',
      streetLine1: form.querySelector('[name="billing_street"]')?.value.trim() || '',
    };
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
    const visible = samedayEnabled && country && country.value === 'RO';
    if (section) section.classList.toggle('hidden', !visible);
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
    schedulePaymentRefresh(form);
  }

  function initSamedayDelivery(form, cart) {
    const picker = form.querySelector('[data-easybox-picker]');
    const results = form.querySelector('[data-easybox-results]');
    const search = form.querySelector('[data-easybox-search]');
    const searchButton = form.querySelector('[data-easybox-search-btn]');
    const mapElement = form.querySelector('[data-easybox-map]');
    const geolocateButton = form.querySelector('[data-easybox-geolocate]');
    const quoteButton = form.querySelector('[data-sameday-quote]');
    const oohInput = form.querySelector('[name="shipping_ooh_id"]');
    let mapMoveTimer = null;
    let easyboxProgrammaticMoveUntil = 0;

    form.querySelectorAll('[name="shipping_method"]').forEach((radio) => radio.addEventListener('change', () => {
      form.querySelectorAll('[data-shipping-card]').forEach((card) => card.classList.toggle('active', card.dataset.shippingCard === radio.value));
      if (picker) picker.classList.toggle('hidden', radio.value !== 'easybox');
      invalidateSamedayQuote(form);
      if (radio.value === 'easybox') {
        window.requestAnimationFrame(() => {
          initializeEasyboxMap();
          if (easyboxMap) easyboxMap.invalidateSize();
          const city = form.querySelector('[name="city"]')?.value.trim() || '';
          const county = form.querySelector('[name="county"]')?.value.trim() || '';
          if (search && !search.value.trim()) search.value = city || county;
          findEasyboxes();
        });
      }
    }));

    ['street', 'city', 'county', 'postal', 'phone'].forEach((name) => {
      const input = form.querySelector(`[name="${name}"]`);
      if (input) input.addEventListener('input', () => invalidateSamedayQuote(form));
    });

    function chooseEasybox(location) {
      selectedEasybox = location;
      if (oohInput) oohInput.value = String(location.id);
      const selected = form.querySelector('[data-easybox-selected]');
      if (selected) {
        selected.innerHTML = `<strong>${window.GGSUtil.escapeHtml(location.name)}</strong><br><span>${window.GGSUtil.escapeHtml([location.address, location.city, location.county].filter(Boolean).join(', '))}</span>`;
        selected.classList.remove('hidden');
      }
      invalidateSamedayQuote(form);
      const quoteFields = ['phone', 'street', 'city', 'county', 'postal'];
      if (quoteFields.every((name) => form.querySelector(`[name="${name}"]`)?.value.trim())) requestSamedayQuote();
    }

    function popupFor(location) {
      const wrapper = document.createElement('div');
      wrapper.className = 'easybox-map-popup';
      const title = document.createElement('strong');
      title.textContent = location.name;
      const address = document.createElement('span');
      address.textContent = [location.address, location.city, location.county].filter(Boolean).join(', ');
      const choose = document.createElement('button');
      choose.type = 'button';
      choose.className = 'btn btn-primary btn-small';
      choose.textContent = document.documentElement.lang === 'ro' ? 'Alege acest Easybox' : 'Choose this Easybox';
      choose.addEventListener('click', () => {
        chooseEasybox(location);
        if (easyboxMap) easyboxMap.closePopup();
      });
      wrapper.append(title, address, choose);
      return wrapper;
    }

    function renderLocations(locations, fitMap) {
      const validLocations = locations.filter((location) => Number.isFinite(Number(location.lat)) && Number.isFinite(Number(location.lng)));
      if (easyboxMarkers) {
        easyboxMarkers.clearLayers();
        validLocations.forEach((location) => {
          const marker = window.L.circleMarker([Number(location.lat), Number(location.lng)], {
            radius: 7,
            color: '#ffffff',
            weight: 2,
            fillColor: '#e63b2e',
            fillOpacity: 0.95,
          }).bindPopup(popupFor(location));
          marker.addTo(easyboxMarkers);
        });
      }
      if (fitMap && easyboxMap && validLocations.length) {
        const bounds = window.L.latLngBounds(validLocations.map((location) => [Number(location.lat), Number(location.lng)]));
        easyboxProgrammaticMoveUntil = Date.now() + 750;
        easyboxMap.fitBounds(bounds.pad(0.12), { maxZoom: 15, animate: false });
      }
      if (!results) return;
      if (!locations.length) {
        results.innerHTML = '<p class="form-hint" data-en="No Easybox found. Move the map or try a shorter search." data-ro="Nu am gasit Easybox. Muta harta sau incearca o cautare mai scurta.">No Easybox found. Move the map or try a shorter search.</p>';
        return;
      }
      results.innerHTML = locations.slice(0, 20).map((location) => `<button type="button" class="sameday-location-option" data-location-id="${location.id}"><strong>${window.GGSUtil.escapeHtml(location.name)}</strong><small>${window.GGSUtil.escapeHtml([location.address, location.city, location.county].filter(Boolean).join(', '))}</small></button>`).join('');
      results.querySelectorAll('[data-location-id]').forEach((button) => button.addEventListener('click', () => {
        const location = locations.find((item) => String(item.id) === button.dataset.locationId);
        if (!location) return;
        chooseEasybox(location);
        if (easyboxMap && Number.isFinite(Number(location.lat)) && Number.isFinite(Number(location.lng))) {
          easyboxProgrammaticMoveUntil = Date.now() + 750;
          easyboxMap.setView([Number(location.lat), Number(location.lng)], 16, { animate: false });
        }
      }));
    }

    function requestLocations(params, fitMap) {
      const requestId = ++easyboxMapRequest;
      if (results) results.innerHTML = '<p class="form-hint" data-en="Loading Easybox locations..." data-ro="Se incarca punctele Easybox...">Loading Easybox locations...</p>';
      return fetch(`${API_BASE}/sameday-locations.php?${params}`)
        .then((response) => response.json())
        .then((data) => {
          if (requestId !== easyboxMapRequest) return;
          renderLocations(data.success && Array.isArray(data.locations) ? data.locations : [], fitMap);
        })
        .catch(() => {
          if (requestId === easyboxMapRequest) renderLocations([], false);
        });
    }

    function loadMapBounds() {
      if (!easyboxMap) return;
      const bounds = easyboxMap.getBounds();
      const params = new URLSearchParams({
        south: bounds.getSouth().toFixed(6),
        west: bounds.getWest().toFixed(6),
        north: bounds.getNorth().toFixed(6),
        east: bounds.getEast().toFixed(6),
        limit: '500',
      });
      requestLocations(params, false);
    }

    function initializeEasyboxMap() {
      if (easyboxMap || !mapElement) return;
      if (!window.L) {
        mapElement.innerHTML = '<p class="form-hint">Harta nu a putut fi incarcata. Foloseste cautarea de mai sus.</p>';
        return;
      }
      easyboxMap = window.L.map(mapElement, { scrollWheelZoom: false }).setView([45.9432, 24.9668], 7);
      window.L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
      }).addTo(easyboxMap);
      easyboxMarkers = window.L.layerGroup().addTo(easyboxMap);
      easyboxMap.on('moveend', () => {
        if (Date.now() < easyboxProgrammaticMoveUntil) return;
        window.clearTimeout(mapMoveTimer);
        mapMoveTimer = window.setTimeout(loadMapBounds, 250);
      });
    }

    function findEasyboxes() {
      initializeEasyboxMap();
      const fallback = form.querySelector('[name="city"]')?.value.trim() || form.querySelector('[name="county"]')?.value.trim() || '';
      const q = search && search.value.trim() ? search.value.trim() : fallback;
      if (!q) {
        loadMapBounds();
        return;
      }
      const params = new URLSearchParams({ q, limit: '100' });
      requestLocations(params, true);
    }
    if (searchButton) searchButton.addEventListener('click', findEasyboxes);
    if (search) search.addEventListener('keydown', (event) => { if (event.key === 'Enter') { event.preventDefault(); findEasyboxes(); } });
    if (geolocateButton) geolocateButton.addEventListener('click', () => {
      initializeEasyboxMap();
      if (!navigator.geolocation || !easyboxMap) return;
      geolocateButton.disabled = true;
      navigator.geolocation.getCurrentPosition(
        (position) => {
          easyboxMap.setView([position.coords.latitude, position.coords.longitude], 14);
          geolocateButton.disabled = false;
        },
        () => { geolocateButton.disabled = false; },
        { enableHighAccuracy: false, timeout: 8000, maximumAge: 300000 }
      );
    });

    function requestSamedayQuote() {
      const method = form.querySelector('[name="shipping_method"]:checked')?.value || 'home';
      const error = document.getElementById('sameday-quote-error');
      if (error) error.classList.add('hidden');
      if (method === 'easybox' && !selectedEasybox) {
        if (error) { error.textContent = document.documentElement.lang === 'ro' ? 'Alege un Easybox.' : 'Choose an Easybox.'; error.classList.remove('hidden'); }
        return Promise.resolve();
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
      return fetch(`${API_BASE}/sameday-quote.php`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
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
          schedulePaymentRefresh(form);
        })
        .catch((requestError) => {
          samedayQuote = null;
          if (error) { error.textContent = requestError.message; error.classList.remove('hidden'); }
          renderOrderSummary(cart);
          schedulePaymentRefresh(form);
        })
        .finally(() => { quoteButton.disabled = false; });
    }
    if (quoteButton) quoteButton.addEventListener('click', requestSamedayQuote);
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
      const companyCountry = form.querySelector('[name="company_country"]');
      if (companyCountry && companyCountry.value.trim() && !EU_COUNTRIES.includes(companyCountry.value.trim().toUpperCase())) {
        showFieldError(companyCountry, 'Company invoice country must be in the EU.', 'Tara de facturare a firmei trebuie sa fie in UE.');
        valid = false;
      }
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

    if (countryInput && !EU_COUNTRIES.includes(countryInput.value)) {
      showFieldError(countryInput, 'Delivery is available only within the European Union.', 'Livrarea este disponibila doar in Uniunea Europeana.');
      valid = false;
    }

    const sameBilling = form.querySelector('[name="billing_same_as_shipping"]');
    if (sameBilling && !sameBilling.checked) {
      ['billing_street', 'billing_city', 'billing_postal', 'billing_country'].forEach((name) => {
        const input = form.querySelector(`[name="${name}"]`);
        if (input && !input.value.trim()) {
          showFieldError(input, 'This billing field is required.', 'Acest camp de facturare este obligatoriu.');
          valid = false;
        }
      });
      const billingCountry = form.querySelector('[name="billing_country"]');
      if (billingCountry && !EU_COUNTRIES.includes(billingCountry.value)) {
        showFieldError(billingCountry, 'Choose an EU billing country.', 'Alege o tara din Uniunea Europeana.');
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

  function buildCheckoutPayload(form, cart) {
    const formData = new FormData(form);
    return {
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
      currency: window.GGSCurrency ? window.GGSCurrency.current : 'EUR',
      billing_address: billingAddressFromForm(form),
    };
  }

  function paymentFormIsReady(form) {
    const value = (name) => form.querySelector(`[name="${name}"]`)?.value.trim() || '';
    if (REQUIRED_FIELDS.some((name) => !value(name))) return false;
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value('email'))) return false;
    if (!EU_COUNTRIES.includes(value('country'))) return false;
    if (value('country') === 'RO' && !/^\d{6}$/.test(value('postal'))) return false;
    if (value('country') !== 'RO' && !/^[a-zA-Z0-9][a-zA-Z0-9\s-]{2,}$/.test(value('postal'))) return false;
    if (value('country') === 'RO' && !value('county')) return false;

    if (value('customer_type') === 'pj') {
      const companyRequired = ['company_name', 'company_cui', 'company_address', 'company_city', 'company_county', 'company_postal', 'company_country'];
      if (companyRequired.some((name) => !value(name))) return false;
      if (!EU_COUNTRIES.includes(value('company_country').toUpperCase())) return false;
    }
    const billing = billingAddressFromForm(form);
    if (!billing.streetLine1 || !billing.city || !billing.postcode || !EU_COUNTRIES.includes(billing.countryCode)) return false;

    if (samedayEnabled && value('country') === 'RO') {
      const method = form.querySelector('[name="shipping_method"]:checked')?.value || 'home';
      if (method === 'easybox' && !selectedEasybox) return false;
      if (!samedayQuote) return false;
    }
    return true;
  }

  function setPaymentReadyStatus(messageEn, messageRo, hidden) {
    const status = document.querySelector('[data-payment-ready-status]');
    if (!status) return;
    status.classList.toggle('hidden', Boolean(hidden));
    if (hidden) return;
    status.setAttribute('data-en', messageEn);
    status.setAttribute('data-ro', messageRo);
    status.textContent = document.documentElement.lang === 'ro' ? messageRo : messageEn;
  }

  function schedulePaymentRefresh(form) {
    window.clearTimeout(paymentRefreshTimer);
    paymentRefreshTimer = window.setTimeout(() => refreshPaymentModule(form), 450);
  }

  function refreshPaymentModule(form) {
    if (!form || !paymentFormIsReady(form)) {
      if (embeddedInstance || mountedPaymentSignature) resetPaymentWidget();
      setPaymentReadyStatus(
        'Complete the delivery details and rate to activate secure payment.',
        'Completeaza datele si tariful de livrare pentru a activa plata securizata.',
        false
      );
      return;
    }
    if (!revolutPublicKey || !window.RevolutCheckout || !window.RevolutCheckout.embeddedCheckout) {
      setPaymentReadyStatus('Secure payment is loading...', 'Se incarca plata securizata...', false);
      return;
    }
    const payload = buildCheckoutPayload(form, currentCart);
    const signature = JSON.stringify(payload);
    if ((embeddedInstance || paymentMountInFlight) && signature === mountedPaymentSignature) return;
    resetPaymentWidget();
    hideCheckoutError();
    setPaymentReadyStatus('Secure payment is loading...', 'Se incarca plata securizata...', false);
    mountedPaymentSignature = signature;
    mountEmbeddedCheckout(payload, signature, form);
  }

  /* ======================= Embedded Revolut Checkout ======================= */

  function resetPaymentWidget() {
    paymentMountGeneration += 1;
    if (embeddedInstance && typeof embeddedInstance.destroy === 'function') {
      embeddedInstance.destroy();
    }
    embeddedInstance = null;
    paymentMountInFlight = false;
    mountedPaymentSignature = '';
    lastOrderNumber = null;
    const target = document.getElementById('revolut-embedded-target');
    if (target) target.innerHTML = '';
  }

  function mountEmbeddedCheckout(payload, signature, form) {
    if (!revolutPublicKey || !window.RevolutCheckout || !window.RevolutCheckout.embeddedCheckout) {
      showCheckoutError(
        'Unable to reach the payment service. Please refresh and try again.',
        'Serviciul de plata nu este disponibil. Reincarca pagina si incearca din nou.'
      );
      return;
    }

    const target = document.getElementById('revolut-embedded-target');
    const subtotalEur = currentCart.reduce((sum, item) => sum + Number(item.price_eur) * item.qty, 0);
    const totalEur = subtotalEur + Number(payload.shipping_eur || 0);
    const conversion = payload.currency === 'RON' && window.GGSCurrency ? window.GGSCurrency.rate : 1;
    const totalMinor = Math.round(totalEur * conversion * 100);
    const shippingMinor = Math.round(Number(payload.shipping_eur || 0) * conversion * 100);
    const shippingLabel = payload.shipping_method === 'easybox'
      ? 'Sameday Easybox'
      : (payload.shipping_method === 'home' ? 'Sameday home delivery' : 'Standard delivery');
    const shippingOptions = [{ id: payload.shipping_method || 'standard', label: shippingLabel, amount: shippingMinor }];
    const mountGeneration = paymentMountGeneration;
    paymentMountInFlight = true;

    window.RevolutCheckout.embeddedCheckout({
      publicToken: revolutPublicKey,
      mode: revolutMode,
      target,
      email: payload.email,
      billingAddress: payload.billing_address,
      shippingOptions,
      onShippingAddressChange(address) {
        const country = String(address && address.countryCode || '').toUpperCase();
        return Promise.resolve({
          status: EU_COUNTRIES.includes(country) ? 'success' : 'fail',
          shippingOptions,
          total: { amount: totalMinor, label: 'Total' },
        });
      },
      onShippingOptionChange() {
        return Promise.resolve({ status: 'success', total: { amount: totalMinor, label: 'Total' } });
      },
      createOrder() {
        if (!validateCheckoutForm(form)) return Promise.reject(new Error('Checkout details are incomplete'));
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
        resetPaymentWidget();
        schedulePaymentRefresh(form);
      },
    })
      .then((instance) => {
        if (mountGeneration !== paymentMountGeneration) {
          if (instance && typeof instance.destroy === 'function') instance.destroy();
          return;
        }
        embeddedInstance = instance;
        paymentMountInFlight = false;
        mountedPaymentSignature = signature;
        setPaymentReadyStatus('', '', true);
      })
      .catch(() => {
        if (mountGeneration !== paymentMountGeneration) return;
        resetPaymentWidget();
        setPaymentReadyStatus(
          'Secure payment could not be loaded. Check the details or refresh the page.',
          'Plata securizata nu a putut fi incarcata. Verifica datele sau reincarca pagina.',
          false
        );
        showCheckoutError(
          "We couldn't load the payment form. Please refresh and try again.",
          'Nu am putut incarca formularul de plata. Reincarca pagina si incearca din nou.'
        );
      });
  }

  document.addEventListener('DOMContentLoaded', initCheckoutPage);
})();
