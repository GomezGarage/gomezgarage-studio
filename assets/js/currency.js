/* ==========================================================================
   GOMEZ GARAGE STUDIO — currency.js
   RON/EUR detection (ip-api.com), manual toggle, conversion, persistence
   ========================================================================== */

(function () {
  'use strict';

  const CURRENCY_KEY = 'ggs_currency';
  const MANUAL_KEY = 'ggs_currency_manual';
  const RATE_FALLBACK = 5.0; // 1 EUR = 5.00 RON fallback, overridden by api/get-rate.php

  const state = {
    currency: 'EUR',
    rate: RATE_FALLBACK,
  };

  function setCurrency(currency, { manual = false } = {}) {
    state.currency = currency;
    localStorage.setItem(CURRENCY_KEY, currency);
    if (manual) localStorage.setItem(MANUAL_KEY, '1');
    document.querySelectorAll('[data-currency-btn]').forEach((btn) => {
      btn.classList.toggle('active', btn.getAttribute('data-currency-btn') === currency);
    });
    document.dispatchEvent(new CustomEvent('ggs:currency-changed', { detail: { currency } }));
  }

  function fetchRate() {
    return fetch('/studio/api/get-rate.php')
      .then((res) => (res.ok ? res.json() : Promise.reject()))
      .then((data) => {
        if (data && data.rate) state.rate = Number(data.rate);
      })
      .catch(() => {
        state.rate = RATE_FALLBACK;
      });
  }

  function detectGeoCurrency() {
    const manualOverride = localStorage.getItem(MANUAL_KEY);
    const stored = localStorage.getItem(CURRENCY_KEY);

    if (manualOverride && stored) {
      setCurrency(stored);
      return Promise.resolve();
    }

    if (stored) {
      // Not a manual override yet, but respect any stored value while we re-check geo.
      setCurrency(stored);
    }

    return fetch('https://ip-api.com/json/?fields=countryCode')
      .then((res) => (res.ok ? res.json() : Promise.reject()))
      .then((data) => {
        const detected = data && data.countryCode === 'RO' ? 'RON' : 'EUR';
        setCurrency(detected);
      })
      .catch(() => {
        setCurrency(stored || 'EUR');
      });
  }

  function initToggle() {
    document.querySelectorAll('[data-currency-btn]').forEach((btn) => {
      btn.addEventListener('click', () => {
        setCurrency(btn.getAttribute('data-currency-btn'), { manual: true });
      });
    });
  }

  /* Convert a EUR-denominated price into the active currency and format it. */
  function convert(priceEur) {
    if (state.currency === 'RON') {
      return Number(priceEur) * state.rate;
    }
    return Number(priceEur);
  }

  function format(priceEur) {
    const value = convert(priceEur);
    return window.GGSUtil ? window.GGSUtil.formatMoney(value, state.currency) : value.toFixed(2);
  }

  window.GGSCurrency = {
    get current() { return state.currency; },
    get rate() { return state.rate; },
    convert,
    format,
    setCurrency,
  };

  document.addEventListener('DOMContentLoaded', () => {
    initToggle();
    fetchRate().then(detectGeoCurrency);
  });
})();
