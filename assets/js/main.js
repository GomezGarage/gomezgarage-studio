/* ==========================================================================
   GOMEZ GARAGE STUDIO — main.js
   Nav behavior, language toggle, cart count badge, scroll reveal, utils
   ========================================================================== */

(function () {
  'use strict';

  const LANG_KEY = 'ggs_lang';

  /* ---------- Language toggle ----------
     Every translatable element carries both data-en and data-ro attributes
     holding the two versions of its text; the attribute values are already
     HTML-entity-decoded by the parser, so a plain textContent swap is safe. */
  function applyLang(lang) {
    document.documentElement.setAttribute('lang', lang);

    document.querySelectorAll('[data-en]').forEach((el) => {
      const text = el.getAttribute(lang === 'ro' ? 'data-ro' : 'data-en');
      if (text !== null && text !== '') el.textContent = text;
    });

    document.querySelectorAll('[data-lang-btn]').forEach((btn) => {
      btn.classList.toggle('active', btn.getAttribute('data-lang-btn') === lang);
    });
  }

  function initLangToggle() {
    const stored = localStorage.getItem(LANG_KEY) || 'en';
    applyLang(stored);

    document.querySelectorAll('[data-lang-btn]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const lang = btn.getAttribute('data-lang-btn');
        localStorage.setItem(LANG_KEY, lang);
        applyLang(lang);
      });
    });
  }

  /* Re-applies the current language to any newly-injected DOM (e.g. product
     cards fetched after the initial DOMContentLoaded pass). */
  window.GGSLang = {
    refresh() {
      applyLang(localStorage.getItem(LANG_KEY) || 'en');
    },
  };

  /* ---------- Mobile nav ---------- */
  function initMobileNav() {
    const burger = document.querySelector('[data-nav-burger]');
    const links = document.querySelector('.nav-links');
    if (!burger || !links) return;
    burger.addEventListener('click', () => {
      links.classList.toggle('nav-links-open');
    });
  }

  /* ---------- Cart count badge (shared across pages) ---------- */
  function updateCartCountBadge() {
    const badges = document.querySelectorAll('[data-cart-count]');
    if (!badges.length || !window.GGSCart) return;
    const count = window.GGSCart.getItemCount();
    badges.forEach((el) => {
      el.textContent = count;
      el.classList.toggle('hidden', count === 0);
    });
  }

  /* ---------- Scroll reveal ---------- */
  function initScrollReveal() {
    const items = document.querySelectorAll('.reveal');
    if (!items.length) return;
    if (!('IntersectionObserver' in window)) {
      items.forEach((el) => el.classList.add('is-visible'));
      return;
    }
    const observer = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            entry.target.classList.add('is-visible');
            observer.unobserve(entry.target);
          }
        });
      },
      { threshold: 0.15 }
    );
    items.forEach((el) => observer.observe(el));
  }

  /* ---------- Smooth scroll for in-page anchors ---------- */
  function initAnchorScroll() {
    document.querySelectorAll('a[href^="#"]').forEach((a) => {
      a.addEventListener('click', (e) => {
        const target = document.querySelector(a.getAttribute('href'));
        if (target) {
          e.preventDefault();
          target.scrollIntoView({ behavior: 'smooth' });
        }
      });
    });
  }

  /* ---------- Shared utils exposed globally ---------- */
  window.GGSUtil = {
    formatMoney(amount, currency) {
      const value = Number(amount).toFixed(2);
      return currency === 'RON' ? `${value} RON` : `€${value}`;
    },
    qs(name) {
      return new URLSearchParams(window.location.search).get(name);
    },
    escapeHtml(str) {
      const div = document.createElement('div');
      div.textContent = str == null ? '' : String(str);
      return div.innerHTML;
    },
  };

  document.addEventListener('DOMContentLoaded', () => {
    initLangToggle();
    initMobileNav();
    initScrollReveal();
    initAnchorScroll();
    updateCartCountBadge();
    document.addEventListener('ggs:cart-updated', updateCartCountBadge);
  });
})();
