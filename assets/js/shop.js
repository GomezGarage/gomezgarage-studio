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

  function productPath(p) {
    return '/studio/product.html?' + (p.slug ? `slug=${encodeURIComponent(p.slug)}` : `id=${p.id}`);
  }

  function productCardHtml(p) {
    const img1 = (p.images && p.images[0]) || '/studio/assets/images/products/placeholder.svg';
    const img2 = (p.images && p.images[1]) || img1;
    return `
      <article class="product-card" data-category="${p.category}" data-price="${p.price_eur}" data-created="${p.created_at}">
        <a href="${productPath(p)}" class="product-card-media">
          <span class="stock-flag">${stockBadge(p)}</span>
          <img class="img-primary" src="${window.GGSUtil.escapeHtml(img1)}" alt="${window.GGSUtil.escapeHtml(p.name_en)}" loading="lazy">
          <img class="img-secondary" src="${window.GGSUtil.escapeHtml(img2)}" alt="" loading="lazy">
        </a>
        <div class="product-card-body">
          <span class="badge mono material-badge">${window.GGSUtil.escapeHtml(p.material)}</span>
          <h3><a href="${productPath(p)}" data-en="${window.GGSUtil.escapeHtml(p.name_en)}" data-ro="${window.GGSUtil.escapeHtml(p.name_ro)}">${window.GGSUtil.escapeHtml(p.name_en)}</a></h3>
          <div class="product-card-price mono">${money(p.price_eur)}</div>
          <div class="product-card-actions">
            <a href="${productPath(p)}" class="btn btn-outline btn-sm" data-en="View" data-ro="Vezi">View</a>
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
      } else {
        grid.innerHTML = top3.map(productCardHtml).join('');
        bindAddToCartButtons(grid, top3);
      }
      if (window.GGSLang) window.GGSLang.refresh();
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
      } else {
        grid.innerHTML = list.map(productCardHtml).join('');
        bindAddToCartButtons(grid, list);
      }
      if (window.GGSLang) window.GGSLang.refresh();
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
    const slug = window.GGSUtil.qs('slug');
    const id = window.GGSUtil.qs('id');
    if (slug ? !/^[a-z0-9][a-z0-9-]{0,99}$/.test(slug) : !/^[1-9][0-9]*$/.test(id || '')) return;

    fetchProducts(slug ? { slug } : { id }).then((products) => {
      const product = products[0];
      if (!product) {
        wrap.innerHTML = '<div class="empty-state"><h3>Product not found</h3></div>';
        return;
      }
      renderProductDetail(wrap, product);
      document.addEventListener('ggs:language-changed', () => {
        updateProductMetadata(product);
        const stock = wrap.querySelector('.stock-indicator');
        if (stock) stock.textContent = stockBadgeText(product);
        wrap.querySelectorAll('[data-image] img').forEach((img) => {
          const path = (product.images || [])[Number(img.parentElement.dataset.image)];
          const alt = (product.image_alt || {})[path] || {};
          img.alt = alt[document.documentElement.lang] || alt.en || product.name_en;
        });
        const main = wrap.querySelector('#gallery-main-img');
        const active = wrap.querySelector('[data-image].active img');
        if (main && active) main.alt = active.alt;
      });
      recordProductView(product.id);
      injectProductSchema(product);
      loadRelated(product);
      if (window.GGSLang) window.GGSLang.refresh();
    });
  }

  function recordProductView(productId) {
    if (document.visibilityState !== 'visible' || !Number.isInteger(productId) || productId < 1) return;
    const key = `ggs_product_viewed_${productId}`;
    try {
      if (sessionStorage.getItem(key) === '1') return;
      // Latch before the request so repeated renders or an uncertain network response
      // cannot count the same product repeatedly in this tab session.
      sessionStorage.setItem(key, '1');
    } catch (e) {
      // Skip counting when session storage is unavailable; avoid duplicate inflation.
      return;
    }
    fetch('/studio/api/record-product-view.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ product_id: productId }),
      keepalive: true,
      credentials: 'same-origin',
    }).catch(() => {
      // Analytics must never interfere with catalog rendering or checkout.
    });
  }

  function renderProductDetail(wrap, p) {
    updateProductMetadata(p);
    const images = (p.images && p.images.length) ? p.images : ['/studio/assets/images/products/placeholder.svg'];
    const specs = p.specs || [];
    const esc = window.GGSUtil.escapeHtml;
    const videos = (p.videos || []).filter((v) => /^\/studio\/assets\/videos\/products\/[a-f0-9]{32}\.mp4$/.test(v.src || ''));
    const imageAlt = (path) => (p.image_alt && p.image_alt[path]) || {};

    wrap.innerHTML = `
      <div class="gallery">
        <div class="gallery-main"><img id="gallery-main-img" src="${esc(images[0])}" alt="${esc(imageAlt(images[0]).en || p.name_en)}"><video id="gallery-main-video" hidden controls playsinline preload="none"></video></div>
        ${(images.length + videos.length) > 1 ? `<div class="gallery-thumbs">
          ${images.map((img, i) => `<button type="button" class="gallery-thumb ${i === 0 ? 'active' : ''}" data-thumb data-image="${i}" aria-label="Photo ${i + 1}" aria-pressed="${i === 0}"><img src="${esc(img)}" alt="${esc(imageAlt(img).en || p.name_en)}" loading="lazy"></button>`).join('')}
          ${videos.map((v, i) => `<button type="button" class="gallery-thumb" data-thumb data-video="${i}" aria-label="${esc(v.title_en || `Video ${i + 1}`)}" aria-pressed="false"><img src="${esc(v.poster || images[0])}" alt="" loading="lazy"><span class="gallery-video-label">&#9654; Video ${i + 1}</span></button>`).join('')}
        </div>` : ''}
        <p class="gallery-caption" id="gallery-caption" hidden></p>
      </div>
      <div class="product-info">
        <span class="badge mono badge-accent">${window.GGSUtil.escapeHtml(p.material)}</span>
        ${p.sku ? `<p class="mono product-sku">SKU: ${esc(p.sku)}</p>` : ''}
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
        ${(p.desc_long_en || p.desc_long_ro) ? `<div class="product-long-desc">
          <h2 data-en="About this piece" data-ro="Despre acest produs">About this piece</h2>
          <p data-en="${window.GGSUtil.escapeHtml(p.desc_long_en)}" data-ro="${window.GGSUtil.escapeHtml(p.desc_long_ro)}">${window.GGSUtil.escapeHtml(p.desc_long_en)}</p>
        </div>` : ''}
        ${specs.length ? `<table class="specs-table">${specs.map((s, i) => `<tr><td data-en="${esc(s.key)}" data-ro="${esc((p.specs_ro || [])[i]?.key || s.key)}">${esc(s.key)}</td><td data-en="${esc(s.value)}" data-ro="${esc((p.specs_ro || [])[i]?.value || s.value)}">${esc(s.value)}</td></tr>`).join('')}</table>` : ''}
        <div class="how-made-block">
          <h3 data-en="Original design by Gomez Garage Studio" data-ro="Design original Gomez Garage Studio">Original design by Gomez Garage Studio</h3>
          <p data-en="Designed from scratch in CAD and 3D printed by Gomez Garage Studio in Romania." data-ro="Proiectat de la zero in CAD si printat 3D de Gomez Garage Studio in Romania.">Designed from scratch in CAD and 3D printed by Gomez Garage Studio in Romania.</p>
          <p data-en="${window.GGSUtil.escapeHtml(p.how_made_en)}" data-ro="${window.GGSUtil.escapeHtml(p.how_made_ro)}">${window.GGSUtil.escapeHtml(p.how_made_en)}</p>
        </div>
      </div>
    `;

    wrap.querySelectorAll('[data-thumb]').forEach((thumb) => {
      thumb.addEventListener('click', () => {
        const image = document.getElementById('gallery-main-img');
        const player = document.getElementById('gallery-main-video');
        const caption = document.getElementById('gallery-caption');
        player.pause();
        if (thumb.hasAttribute('data-video')) {
          const video = videos[Number(thumb.dataset.video)];
          player.src = video.src;
          player.poster = video.poster || images[0];
          player.hidden = false;
          image.hidden = true;
          caption.dataset.en = video.title_en || 'Product video';
          caption.dataset.ro = video.title_ro || video.title_en || 'Video produs';
          caption.hidden = false;
        } else {
          const path = images[Number(thumb.dataset.image)];
          image.src = path;
          image.alt = imageAlt(path)[document.documentElement.lang] || imageAlt(path).en || p.name_en;
          image.hidden = false;
          player.hidden = true;
          player.removeAttribute('src');
          player.load();
          caption.hidden = true;
        }
        wrap.querySelectorAll('[data-thumb]').forEach((t) => { t.classList.remove('active'); t.setAttribute('aria-pressed', 'false'); });
        thumb.classList.add('active');
        thumb.setAttribute('aria-pressed', 'true');
        if (window.GGSLang) window.GGSLang.refresh();
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

  function updateProductMetadata(p) {
    const lang = document.documentElement.lang === 'ro' ? 'ro' : 'en';
    document.title = p[`seo_title_${lang}`] || `${p[`name_${lang}`] || p.name_en} | Gomez Garage Studio`;
    const description = p[`seo_description_${lang}`] || p[`desc_short_${lang}`] || p.desc_short_en;
    const url = `https://gomezgarage.eu${productPath(p)}`;
    let canonical = document.querySelector('link[rel="canonical"]');
    if (canonical) canonical.href = url;
    const setMeta = (selector, attribute, key, content) => {
      let tag = document.querySelector(selector);
      if (!tag) {
        tag = document.createElement('meta');
        tag.setAttribute(attribute, key);
        document.head.appendChild(tag);
      }
      tag.content = content || '';
    };
    setMeta('meta[name="description"]', 'name', 'description', description);
    setMeta('meta[property="og:title"]', 'property', 'og:title', document.title);
    setMeta('meta[property="og:description"]', 'property', 'og:description', description);
    setMeta('meta[property="og:url"]', 'property', 'og:url', url);
    if (p.images && p.images[0]) {
      setMeta('meta[property="og:image"]', 'property', 'og:image', new URL(p.images[0], 'https://gomezgarage.eu').href);
      const alt = (p.image_alt || {})[p.images[0]] || {};
      setMeta('meta[property="og:image:alt"]', 'property', 'og:image:alt', alt[lang] || alt.en || p[`name_${lang}`] || p.name_en);
    }
  }

  /* Schema.org Product markup (same canonical/data as the server renderer). */
  function injectProductSchema(p) {
    const images = p.images || [];
    const url = `https://gomezgarage.eu${productPath(p)}`;
    const schema = {
      '@context': 'https://schema.org/',
      '@type': 'Product',
      '@id': `${url}#product`,
      url,
      brand: { '@type': 'Brand', name: 'Gomez Garage Studio' },
      name: p.name_en,
      description: p.desc_short_en,
      ...(images.length ? { image: images.map((img) => new URL(img, 'https://gomezgarage.eu').href) } : {}),
      sku: p.sku || String(p.id),
      material: p.material,
      ...(Number(p.price_eur) > 0 ? { offers: {
        '@type': 'Offer',
        url,
        priceCurrency: 'EUR',
        price: Number(p.price_eur).toFixed(2),
        itemCondition: 'https://schema.org/NewCondition',
        seller: { '@type': 'Organization', name: 'Gomez Garage Studio' },
        availability: p.stock > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
      } } : {}),
    };
    let tag = document.getElementById('product-schema');
    if (!tag) {
      tag = document.createElement('script');
      tag.type = 'application/ld+json';
      tag.id = 'product-schema';
      document.head.appendChild(tag);
    }
    tag.textContent = JSON.stringify(schema);
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
      if (window.GGSLang) window.GGSLang.refresh();
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
        if (window.GGSLang) window.GGSLang.refresh();
        return;
      }
      list.innerHTML = cart.map((item) => `
        <div class="cart-item" data-item="${item.product_id}">
          <img src="${item.image || '/studio/assets/images/products/placeholder.svg'}" alt="${window.GGSUtil.escapeHtml(item.name_en)}">
          <div>
            <div class="cart-item-name" data-en="${window.GGSUtil.escapeHtml(item.name_en)}" data-ro="${window.GGSUtil.escapeHtml(item.name_ro || item.name_en)}">${window.GGSUtil.escapeHtml(item.name_en)}</div>
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
      if (window.GGSLang) window.GGSLang.refresh();
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
