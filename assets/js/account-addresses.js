/* Guided address fields for saved customer addresses. */
(function () {
  'use strict';

  const API_BASE = '/studio/api';

  function normalize(value) {
    const normalized = String(value || '')
      .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
      .toLowerCase().replace(/[^a-z0-9]+/g, '');
    if (normalized === 'bucuresti' || normalized === 'municipiulbucuresti' || /^(sector|sectorul)[1-6]$/.test(normalized)) return 'bucharest';
    return normalized;
  }

  function regionLabel(country, value) {
    if (country === 'RO' && normalize(value) === 'bucharest' && document.documentElement.lang === 'ro') return 'Bucuresti';
    return value;
  }

  function fillDatalist(list, values) {
    list.innerHTML = '';
    values.forEach((value) => {
      const option = document.createElement('option');
      option.value = value;
      list.append(option);
    });
  }

  function initAddressForm(form) {
    const country = form.querySelector('[data-address-country]');
    const regionInput = form.querySelector('[data-address-region-input]');
    const regionSelect = form.querySelector('[data-address-region-select]');
    const regionList = document.getElementById('account-region-suggestions');
    const city = form.querySelector('[data-address-city]');
    const cityList = document.getElementById('account-city-suggestions');
    const postal = form.querySelector('[data-address-postal]');
    if (!country || !regionInput || !regionSelect || !regionList || !city || !cityList || !postal) return;

    let regionRequest = 0;
    let cityTimer = 0;

    function configurePostal() {
      if (country.value === 'RO') {
        postal.pattern = '\\d{6}';
        postal.inputMode = 'numeric';
        postal.maxLength = 6;
        postal.title = document.documentElement.lang === 'ro'
          ? 'Codul postal trebuie sa aiba exact 6 cifre.'
          : 'Romanian postal codes contain exactly 6 digits.';
      } else {
        postal.pattern = '[A-Za-z0-9][A-Za-z0-9 \\-]{1,10}[A-Za-z0-9]';
        postal.inputMode = 'text';
        postal.maxLength = 12;
        postal.title = document.documentElement.lang === 'ro'
          ? 'Introdu un cod postal valid.'
          : 'Enter a valid postal code.';
      }
    }

    function useRegionInput(value) {
      regionSelect.classList.add('hidden');
      regionSelect.disabled = true;
      regionSelect.required = false;
      regionSelect.removeAttribute('name');
      regionInput.classList.remove('hidden');
      regionInput.disabled = false;
      regionInput.required = country.value === 'RO';
      regionInput.name = 'county';
      if (value !== undefined) regionInput.value = value;
    }

    function selectRegion(value) {
      const wanted = normalize(value);
      const match = Array.from(regionSelect.options).find((option) => normalize(option.value) === wanted);
      if (!match) return false;
      regionSelect.value = match.value;
      return true;
    }

    function relabelRegions() {
      Array.from(regionSelect.options).forEach((option) => {
        if (!option.value) {
          option.textContent = document.documentElement.lang === 'ro' ? 'Alege judetul / regiunea' : 'Choose county / region';
          return;
        }
        option.textContent = regionLabel(country.value, option.value);
      });
    }

    function loadRegions(preserveValue) {
      const requestId = ++regionRequest;
      const existing = preserveValue !== undefined
        ? preserveValue
        : (regionSelect.value || regionInput.value || '');
      const params = new URLSearchParams({ country: country.value, kind: 'regions', all: '1' });
      fetch(`${API_BASE}/eurosender-geo.php?${params.toString()}`, { cache: 'no-store' })
        .then((response) => response.json())
        .then((data) => {
          if (requestId !== regionRequest) return;
          const suggestions = data.success && Array.isArray(data.suggestions) ? data.suggestions : [];
          if (!suggestions.length) {
            useRegionInput(existing);
            return;
          }
          regionSelect.innerHTML = '<option value="">Choose county / region</option>';
          suggestions.forEach((value) => {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = regionLabel(country.value, value);
            regionSelect.append(option);
          });
          regionInput.classList.add('hidden');
          regionInput.disabled = true;
          regionInput.required = false;
          regionInput.removeAttribute('name');
          regionSelect.classList.remove('hidden');
          regionSelect.disabled = false;
          regionSelect.required = true;
          regionSelect.name = 'county';
          selectRegion(existing);
          relabelRegions();
        })
        .catch(() => {
          if (requestId === regionRequest) useRegionInput(existing);
        });
    }

    function requestCities() {
      window.clearTimeout(cityTimer);
      const query = city.value.trim();
      if (query.length < 2) {
        fillDatalist(cityList, []);
        return;
      }
      cityTimer = window.setTimeout(() => {
        const activeRegion = form.querySelector('[name="county"]');
        const params = new URLSearchParams({ country: country.value, kind: 'cities', q: query });
        if (activeRegion && activeRegion.value.trim()) params.set('region', activeRegion.value.trim());
        fetch(`${API_BASE}/eurosender-geo.php?${params.toString()}`, { cache: 'no-store' })
          .then((response) => response.json())
          .then((data) => fillDatalist(cityList, data.success && Array.isArray(data.suggestions)
            ? data.suggestions.map((value) => regionLabel(country.value, value))
            : []))
          .catch(() => fillDatalist(cityList, []));
      }, 250);
    }

    country.addEventListener('change', () => {
      regionInput.value = '';
      regionSelect.value = '';
      city.value = '';
      postal.value = '';
      fillDatalist(regionList, []);
      fillDatalist(cityList, []);
      configurePostal();
      loadRegions('');
    });
    regionInput.addEventListener('input', () => fillDatalist(cityList, []));
    regionSelect.addEventListener('change', () => {
      city.value = '';
      fillDatalist(cityList, []);
    });
    city.addEventListener('input', requestCities);
    document.addEventListener('ggs:language-changed', () => {
      configurePostal();
      relabelRegions();
    });

    configurePostal();
    loadRegions(regionInput.value);
  }

  document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('[data-account-address-form]');
    if (form) initAddressForm(form);
  });
})();
