/* ==========================================================================
   GOMEZ GARAGE STUDIO — currency.js
   RON/EUR detection (ip-api.com), manual toggle, conversion, persistence
   ========================================================================== */

(function () {
  'use strict';

  const CURRENCY_KEY = 'GGSCurrency';
  const RATE_KEY = 'GGSRate';
  const MANUAL_KEY = 'ggs_currency_manual';
  const RATE_FALLBACK = 5.0; // 1 EUR = 5.00 RON fallback, overridden by api/get-rate.php

  function readStoredRate() {
    const stored = parseFloat(localStorage.getItem(RATE_KEY));
    return Number.isFinite(stored) && stored > 0 ? stored : RATE_FALLBACK;
  }

  // Read synchronously at script-load time (before DOMContentLoaded) so pages
  // that render prices during their own DOMContentLoaded handler — cart.html,
  // checkout.html — already see the persisted currency/rate on the very
  // first render, not just after the async geo/rate fetches resolve.
  const state = {
    currency: localStorage.getItem(CURRENCY_KEY) === 'RON' ? 'RON' : 'EUR',
    rate: readStoredRate(),
  };

  function applyCurrencyUI() {
    document.querySelectorAll('[data-currency-btn]').forEach((btn) => {
      btn.classList.toggle('active', btn.getAttribute('data-currency-btn') === state.currency);
    });
  }

  function broadcastChange() {
    document.dispatchEvent(new CustomEvent('ggs:currency-changed', {
      detail: { currency: state.currency, rate: state.rate },
    }));
  }

  function setCurrency(currency, { manual = false } = {}) {
    state.currency = currency === 'RON' ? 'RON' : 'EUR';
    localStorage.setItem(CURRENCY_KEY, state.currency);
    if (manual) localStorage.setItem(MANUAL_KEY, '1');
    applyCurrencyUI();
    broadcastChange();
  }

  function setRate(rate) {
    if (!Number.isFinite(rate) || rate <= 0) return;
    state.rate = rate;
    localStorage.setItem(RATE_KEY, String(rate));
  }

  function fetchRate() {
    return fetch('/studio/api/get-rate.php')
      .then((res) => (res.ok ? res.json() : Promise.reject()))
      .then((data) => {
        if (data && data.rate) {
          setRate(Number(data.rate));
          broadcastChange();
        }
      })
      .catch(() => {
        // Keep whatever we already had (persisted rate or the hardcoded fallback).
      });
  }

  function detectGeoCurrency() {
    if (localStorage.getItem(MANUAL_KEY)) {
      return Promise.resolve();
    }

    return fetch('https://ip-api.com/json/?fields=countryCode')
      .then((res) => (res.ok ? res.json() : Promise.reject()))
      .then((data) => {
        const detected = data && data.countryCode === 'RO' ? 'RON' : 'EUR';
        setCurrency(detected);
      })
      .catch(() => {
        // Keep whatever we already had (persisted currency or the EUR default).
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
    // Reflect the persisted (or default) state immediately — no need to wait
    // on any network call for the common "returning visitor" case.
    applyCurrencyUI();
    fetchRate();
    detectGeoCurrency();
  });
})();
