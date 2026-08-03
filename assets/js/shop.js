/* ==========================================================================
   GOMEZ GARAGE STUDIO — shop.js
   Cart (localStorage), product fetching/rendering, filters, sort
   ========================================================================== */

(function () {
  'use strict';

  const CART_KEY = 'ggs_cart';
  const API_BASE = '/studio/api';

  /* ======================= CART ======================= */

  function readCart() {
    try {
      return JSON.parse(localStorage.getItem(CART_KEY)) || [];
    } catch (e) {
      return [];
    }
  }

  function writeCart(cart) {
    localStorage.setItem(CART_KEY, JSON.stringify(cart));
    document.dispatchEvent(new CustomEvent('ggs:cart-updated', { detail: { cart } }));
  }

  function addItem(product, qty) {
    qty = Math.max(1, qty || 1);
    const cart = readCart();
    const existing = cart.find((i) => i.product_id === product.id);
    if (existing) {
      existing.qty += qty;
    } else {
      cart.push({
        product_id: product.id,
        slug: product.slug,
        name_en: product.name_en,
        name_ro: product.name_ro,
        price_eur: product.price_eur,
        image: (product.images && product.images[0]) || '',
        material: product.material,
        qty,
      });
    }
    writeCart(cart);
  }

  function removeItem(productId) {
    writeCart(readCart().filter((i) => i.product_id !== productId));
  }

  function updateQty(productId, qty) {
    const cart = readCart();
    const item = cart.find((i) => i.product_id === productId);
    if (!item) return;
    item.qty = Math.max(1, qty);
    writeCart(cart);
  }

  function getItemCount() {
    return readCart().reduce((sum, i) => sum + i.qty, 0);
  }

  function getSubtotalEur() {
    return readCart().reduce((sum, i) => sum + i.qty * Number(i.price_eur), 0);
  }

  function clearCart() {
    writeCart([]);
  }

  window.GGSCart = {
    readCart,
    addItem,
    removeItem,
    updateQty,
    getItemCount,
    getSubtotalEur,
    clearCart,
  };

  /* ======================= PRODUCT FETCH ======================= */

  function fetchProducts(params) {
    const query = params ? '?' + new URLSearchParams(params).toString() : '';
    return fetch(`${API_BASE}/get-products.php${query}`)
      .then((res) => (res.ok ? res.json() : Promise.reject(res)))
      .then((data) => data.products || []);
  }

  window.GGSShop = { fetchProducts };

  /* ======================= RENDER HELPERS ======================= */

  function money(priceEur) {
    return window.GGSCurrency ? window.GGSCurrency.format(priceEur) : `€${priceEur}`;
  }

  function stockBadge(product) {
    if (product.stock <= 0) return '<span class="badge badge-danger" data-en="Out of stock" data-ro="Stoc epuizat">Out of stock</span>';
    if (product.stock <= product.stock_threshold) return `<span class="badge badge-warning" data-en="Low stock" data-ro="Stoc redus">Low stock (${product.stock} left)</span>`;
    return '<span class="badge badge-success" data-en="In stock" data-ro="In stoc">In stock</span>';
  }

  function productCardHtml(p) {
    const img1 = (p.images && p.images[0]) || '/studio/assets/images/products/placeholder.svg';
    const img2 = (p.images && p.images[1]) || img1;
    return `
      <article class="product-card" data-category="${p.category}" data-price="${p.price_eur}" data-created="${p.created_at}">
        <a href="/studio/product.html?id=${p.id}" class="product-card-media">
          <span class="stock-flag">${stockBadge(p)}</span>
          <img class="img-primary" src="${img1}" alt="${window.GGSUtil.escapeHtml(p.name_en)}" loading="lazy">
          <img class="img-secondary" src="${img2}" alt="" loading="lazy">
        </a>
        <div class="product-card-body">
          <span class="badge mono material-badge">${window.GGSUtil.escapeHtml(p.material)}</span>
          <h3><a href="/studio/product.html?id=${p.id}" data-en="${window.GGSUtil.escapeHtml(p.name_en)}" data-ro="${window.GGSUtil.escapeHtml(p.name_ro)}">${window.GGSUtil.escapeHtml(p.name_en)}</a></h3>
          <div class="product-card-price mono">${money(p.price_eur)}</div>
          <div class="product-card-actions">
            <a href="/studio/product.html?id=${p.id}" class="btn btn-outline btn-sm" data-en="View" data-ro="Vezi">View</a>
            <button class="btn btn-primary btn-sm" data-add-to-cart="${p.id}" ${p.stock <= 0 ? 'disabled' : ''} data-en="Add to Cart" data-ro="Adauga in cos">Add to Cart</button>
          </div>
        </div>
      </article>
    `;
  }

  function bindAddToCartButtons(container, products) {
    container.querySelectorAll('[data-add-to-cart]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const id = Number(btn.getAttribute('data-add-to-cart'));
        const product = products.find((p) => p.id === id);
        if (product) {
          addItem(product, 1);
          btn.textContent = 'Added ✓';
          setTimeout(() => {
            btn.textContent = document.documentElement.lang === 'ro' ? 'Adauga in cos' : 'Add to Cart';
          }, 1200);
        }
      });
    });
  }

  /* ======================= HOMEPAGE FEATURED ======================= */

  function initFeaturedProducts() {
    const grid = document.querySelector('[data-featured-grid]');
    if (!grid) return;
    fetchProducts({ featured: 1 }).then((products) => {
      const top3 = products.slice(0, 3);
      if (!top3.length) {
        grid.innerHTML = '<p class="empty-state" data-en="Products coming soon." data-ro="Produse in curand.">Products coming soon.</p>';
        return;
      }
      grid.innerHTML = top3.map(productCardHtml).join('');
      bindAddToCartButtons(grid, top3);
    });
  }

  /* ======================= PRODUCTS PAGE ======================= */

  function initProductsPage() {
    const grid = document.querySelector('[data-products-grid]');
    if (!grid) return;

    let allProducts = [];
    let activeCategory = 'all';
    let activeSort = 'newest';

    function render() {
      let list = allProducts.filter((p) => activeCategory === 'all' || p.category === activeCategory);
      if (activeSort === 'price-asc') list = list.slice().sort((a, b) => a.price_eur - b.price_eur);
      else if (activeSort === 'price-desc') list = list.slice().sort((a, b) => b.price_eur - a.price_eur);
      else list = list.slice().sort((a, b) => new Date(b.created_at) - new Date(a.created_at));

      if (!list.length) {
        grid.innerHTML = '<p class="empty-state" data-en="No products match this filter." data-ro="Niciun produs pentru acest filtru.">No products match this filter.</p>';
        return;
      }
      grid.innerHTML = list.map(productCardHtml).join('');
      bindAddToCartButtons(grid, list);
    }

    document.querySelectorAll('[data-filter-btn]').forEach((btn) => {
      btn.addEventListener('click', () => {
        activeCategory = btn.getAttribute('data-filter-btn');
        document.querySelectorAll('[data-filter-btn]').forEach((b) => b.classList.toggle('active', b === btn));
        render();
      });
    });

    const sortSelect = document.querySelector('[data-sort-select]');
    if (sortSelect) {
      sortSelect.addEventListener('change', () => {
        activeSort = sortSelect.value;
        render();
      });
    }

    fetchProducts({ status: 'published' }).then((products) => {
      allProducts = products;
      render();
    });

    document.addEventListener('ggs:currency-changed', render);
  }

  /* ======================= PRODUCT DETAIL PAGE ======================= */

  function initProductDetailPage() {
    const wrap = document.querySelector('[data-product-detail]');
    if (!wrap) return;
    const id = window.GGSUtil.qs('id');
    if (!id) return;

    fetchProducts({ id }).then((products) => {
      const product = products[0];
      if (!product) {
        wrap.innerHTML = '<div class="empty-state"><h3>Product not found</h3></div>';
        return;
      }
      renderProductDetail(wrap, product);
      loadRelated(product);
    });
  }

  function renderProductDetail(wrap, p) {
    document.title = `${p.name_en} — Gomez Garage Studio`;
    const images = (p.images && p.images.length) ? p.images : ['/studio/assets/images/products/placeholder.svg'];
    const specs = p.specs || [];

    wrap.innerHTML = `
      <div class="gallery">
        <div class="gallery-main"><img id="gallery-main-img" src="${images[0]}" alt="${window.GGSUtil.escapeHtml(p.name_en)}"></div>
        ${images.length > 1 ? `<div class="gallery-thumbs">${images.map((img, i) => `<img src="${img}" class="${i === 0 ? 'active' : ''}" data-thumb>`).join('')}</div>` : ''}
      </div>
      <div class="product-info">
        <span class="badge mono badge-accent">${window.GGSUtil.escapeHtml(p.material)}</span>
        <h1 data-en="${window.GGSUtil.escapeHtml(p.name_en)}" data-ro="${window.GGSUtil.escapeHtml(p.name_ro)}">${window.GGSUtil.escapeHtml(p.name_en)}</h1>
        <div class="product-price-block mono">${money(p.price_eur)}</div>
        <p class="product-desc" data-en="${window.GGSUtil.escapeHtml(p.desc_short_en)}" data-ro="${window.GGSUtil.escapeHtml(p.desc_short_ro)}">${window.GGSUtil.escapeHtml(p.desc_short_en)}</p>
        <div class="stock-indicator ${p.stock <= 0 ? 'out' : (p.stock <= p.stock_threshold ? 'low' : 'in')}">${stockBadgeText(p)}</div>
        <div class="qty-selector">
          <button type="button" data-qty-minus>−</button>
          <input type="number" id="detail-qty" value="1" min="1">
          <button type="button" data-qty-plus>+</button>
        </div>
        <div class="product-actions">
          <button class="btn btn-outline" id="add-to-cart-detail" ${p.stock <= 0 ? 'disabled' : ''} data-en="Add to Cart" data-ro="Adauga in cos">Add to Cart</button>
          <button class="btn btn-primary" id="buy-now-detail" ${p.stock <= 0 ? 'disabled' : ''} data-en="Buy Now" data-ro="Cumpara acum">Buy Now</button>
        </div>
        ${specs.length ? `<table class="specs-table">${specs.map((s) => `<tr><td>${window.GGSUtil.escapeHtml(s.key)}</td><td>${window.GGSUtil.escapeHtml(s.value)}</td></tr>`).join('')}</table>` : ''}
        <div class="how-made-block">
          <h3 data-en="How it's made" data-ro="Cum este fabricat">How it's made</h3>
          <p data-en="${window.GGSUtil.escapeHtml(p.how_made_en)}" data-ro="${window.GGSUtil.escapeHtml(p.how_made_ro)}">${window.GGSUtil.escapeHtml(p.how_made_en)}</p>
        </div>
      </div>
    `;

    wrap.querySelectorAll('[data-thumb]').forEach((thumb) => {
      thumb.addEventListener('click', () => {
        document.getElementById('gallery-main-img').src = thumb.src;
        wrap.querySelectorAll('[data-thumb]').forEach((t) => t.classList.remove('active'));
        thumb.classList.add('active');
      });
    });

    const qtyInput = document.getElementById('detail-qty');
    wrap.querySelector('[data-qty-minus]').addEventListener('click', () => {
      qtyInput.value = Math.max(1, Number(qtyInput.value) - 1);
    });
    wrap.querySelector('[data-qty-plus]').addEventListener('click', () => {
      qtyInput.value = Number(qtyInput.value) + 1;
    });

    document.getElementById('add-to-cart-detail').addEventListener('click', () => {
      addItem(p, Number(qtyInput.value));
    });
    document.getElementById('buy-now-detail').addEventListener('click', () => {
      addItem(p, Number(qtyInput.value));
      window.location.href = '/studio/checkout.html';
    });
  }

  function stockBadgeText(p) {
    if (p.stock <= 0) return document.documentElement.lang === 'ro' ? 'Stoc epuizat' : 'Out of stock';
    if (p.stock <= p.stock_threshold) return (document.documentElement.lang === 'ro' ? 'Stoc redus' : 'Low stock') + ` (${p.stock})`;
    return document.documentElement.lang === 'ro' ? 'In stoc' : 'In stock';
  }

  function loadRelated(product) {
    const grid = document.querySelector('[data-related-grid]');
    if (!grid) return;
    fetchProducts({ category: product.category, status: 'published' }).then((products) => {
      const related = products.filter((p) => p.id !== product.id).slice(0, 3);
      grid.innerHTML = related.map(productCardHtml).join('');
      bindAddToCartButtons(grid, related);
    });
  }

  /* ======================= CART PAGE ======================= */

  function initCartPage() {
    const list = document.querySelector('[data-cart-list]');
    if (!list) return;

    function render() {
      const cart = readCart();
      if (!cart.length) {
        list.innerHTML = '<div class="empty-state"><h3 data-en="Your cart is empty" data-ro="Cosul este gol">Your cart is empty</h3><a href="/studio/products.html" class="btn btn-outline" data-en="Continue Shopping" data-ro="Continua cumparaturile">Continue Shopping</a></div>';
        updateSummary(cart);
        return;
      }
      list.innerHTML = cart.map((item) => `
        <div class="cart-item" data-item="${item.product_id}">
          <img src="${item.image || '/studio/assets/images/products/placeholder.svg'}" alt="${window.GGSUtil.escapeHtml(item.name_en)}">
          <div>
            <div class="cart-item-name">${window.GGSUtil.escapeHtml(item.name_en)}</div>
            <div class="cart-item-material mono">${window.GGSUtil.escapeHtml(item.material || '')}</div>
            <button class="cart-item-remove" data-remove="${item.product_id}" data-en="Remove" data-ro="Elimina">Remove</button>
          </div>
          <div class="qty-selector">
            <button type="button" data-cart-minus="${item.product_id}">−</button>
            <input type="number" min="1" value="${item.qty}" data-cart-qty="${item.product_id}">
            <button type="button" data-cart-plus="${item.product_id}">+</button>
          </div>
          <div class="cart-item-price mono">${money(item.price_eur * item.qty)}</div>
        </div>
      `).join('');

      list.querySelectorAll('[data-remove]').forEach((btn) => {
        btn.addEventListener('click', () => {
          removeItem(Number(btn.getAttribute('data-remove')));
          render();
        });
      });
      list.querySelectorAll('[data-cart-minus]').forEach((btn) => {
        btn.addEventListener('click', () => {
          const id = Number(btn.getAttribute('data-cart-minus'));
          const item = cart.find((i) => i.product_id === id);
          updateQty(id, Math.max(1, item.qty - 1));
          render();
        });
      });
      list.querySelectorAll('[data-cart-plus]').forEach((btn) => {
        btn.addEventListener('click', () => {
          const id = Number(btn.getAttribute('data-cart-plus'));
          const item = cart.find((i) => i.product_id === id);
          updateQty(id, item.qty + 1);
          render();
        });
      });
      list.querySelectorAll('[data-cart-qty]').forEach((input) => {
        input.addEventListener('change', () => {
          updateQty(Number(input.getAttribute('data-cart-qty')), Number(input.value));
          render();
        });
      });

      updateSummary(cart);
    }

    function updateSummary(cart) {
      const subtotalEl = document.querySelector('[data-cart-subtotal]');
      const checkoutBtn = document.querySelector('[data-go-checkout]');
      const subtotal = cart.reduce((sum, i) => sum + i.qty * Number(i.price_eur), 0);
      if (subtotalEl) subtotalEl.textContent = money(subtotal);
      if (checkoutBtn) checkoutBtn.classList.toggle('hidden', cart.length === 0);
    }

    render();
    document.addEventListener('ggs:currency-changed', render);
  }

  document.addEventListener('DOMContentLoaded', () => {
    initFeaturedProducts();
    initProductsPage();
    initProductDetailPage();
    initCartPage();
  });
})();
