/* Market Aggregator Bot — WebApp client */
(() => {
  'use strict';

  const tg = window.Telegram && window.Telegram.WebApp;
  if (tg) {
    tg.ready();
    tg.expand();
    if (tg.colorScheme === 'dark') {
      document.body.classList.add('tg-dark');
    }
  }

  const API_BASE = '../api/';
  const initData = tg ? tg.initData : '';

  /** ---------- API helpers ---------- */
  async function api(action, opts = {}) {
    const url = new URL(API_BASE + 'index.php', window.location.href);
    url.searchParams.set('action', action);
    if (opts.params) {
      for (const [k, v] of Object.entries(opts.params)) {
        if (v !== null && v !== undefined && v !== '') url.searchParams.set(k, v);
      }
    }
    const res = await fetch(url.toString(), {
      method: opts.method || 'GET',
      headers: {
        'Accept': 'application/json',
        'X-Telegram-Init-Data': initData || '',
        ...(opts.body ? { 'Content-Type': 'application/json' } : {}),
      },
      body: opts.body ? JSON.stringify(opts.body) : undefined,
      signal: opts.signal,
    });
    const json = await res.json().catch(() => ({ ok: false, error: 'invalid json' }));
    if (!json.ok) {
      throw new Error(json.error || `HTTP ${res.status}`);
    }
    return json;
  }

  /** ---------- State ---------- */
  const PAGE_SIZE = 30;
  const state = {
    products: [],
    categories: [],
    activeCategory: null,
    favorites: [],
    favIdSet: new Set(),
    sources: [],
    me: null,
    searchQuery: '',
    offset: 0,
    hasMore: false,
  };

  const $ = (sel) => document.querySelector(sel);
  const $$ = (sel) => Array.from(document.querySelectorAll(sel));

  /** ---------- Formatting ---------- */
  function fmtNum(n) {
    return Math.round(Number(n || 0)).toLocaleString('uz-UZ').replace(/,/g, ' ');
  }
  function currencyLabel(cur) {
    if (cur === 'RUB') return '₽';
    if (cur === 'USD') return '$';
    return 'so\'m';
  }
  // Convert any product amount (price/old_price) into so'm using the
  // server-provided price_uzs as the rate anchor. Returns null if unknown.
  function toUzs(p, value) {
    if (!p || value == null) return null;
    if (p.currency === 'UZS' || !p.currency) return Number(value);
    if (p.price_uzs && Number(p.price) > 0) {
      return Number(value) * (Number(p.price_uzs) / Number(p.price));
    }
    return null;
  }
  // Main price display: so'm when a conversion is known, original otherwise.
  function priceHTML(p, value) {
    const uzs = toUzs(p, value);
    if (uzs !== null) return `${fmtNum(uzs)} <small>so'm</small>`;
    return `${fmtNum(value)} <small>${escapeHTML(currencyLabel(p.currency))}</small>`;
  }
  function priceText(p, value) {
    const uzs = toUzs(p, value);
    if (uzs !== null) return `${fmtNum(uzs)} so'm`;
    return `${fmtNum(value)} ${currencyLabel(p.currency)}`;
  }
  function originalPriceNote(p) {
    if (!p || p.currency === 'UZS' || !p.currency) return '';
    if (toUzs(p, p.price) === null) return '';
    return `≈ ${fmtNum(p.price)} ${currencyLabel(p.currency)}`;
  }
  function discountPercent(price, oldPrice) {
    if (!oldPrice || oldPrice <= price) return null;
    return Math.round(((oldPrice - price) / oldPrice) * 100);
  }
  function escapeHTML(str) {
    return String(str || '').replace(/[&<>"']/g, c => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;',
    })[c]);
  }
  function stripTags(html) {
    // DOMParser never executes scripts/handlers and never loads resources,
    // unlike assigning innerHTML on a detached element.
    const doc = new DOMParser().parseFromString(String(html || ''), 'text/html');
    return doc.body.textContent || '';
  }

  /** ---------- Rendering ---------- */
  function renderCategories(target = '#categories') {
    const node = $(target);
    if (!node) return;
    const items = [
      { slug: '', name: 'Hammasi', icon: '🛍' },
      ...state.categories,
    ];
    node.innerHTML = items.map(c => `
      <button class="category-chip ${state.activeCategory === c.slug ? 'is-active' : ''}" data-slug="${escapeHTML(c.slug)}">
        <span>${escapeHTML(c.icon || '•')}</span>
        <span>${escapeHTML(c.name)}</span>
      </button>
    `).join('');
    node.querySelectorAll('.category-chip').forEach(btn => {
      btn.addEventListener('click', () => {
        state.activeCategory = btn.dataset.slug || null;
        renderCategories();
        loadProducts();
      });
    });
  }

  function productCardHTML(p) {
    const discount = discountPercent(p.price, p.old_price);
    const fav = p.is_favorite || state.favIdSet.has(Number(p.id));
    const synced = p.synced_at_human ? `Yangilangan: ${escapeHTML(p.synced_at_human)}` : '';
    const img = (p.images && p.images[0]) || p.image_url || '';
    return `
      <article class="card" data-id="${p.id}">
        <div class="card__image-wrap">
          ${img ? `<img class="card__image" loading="lazy" src="${escapeHTML(img)}" alt="">` : ''}
          <span class="card__source-badge">${escapeHTML(p.source)}</span>
          <button class="card__fav ${fav ? 'is-active' : ''}" data-action="fav" data-id="${p.id}" aria-label="Sevimli">
            <svg viewBox="0 0 24 24" fill="${fav ? 'currentColor' : 'none'}" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 1 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
          </button>
        </div>
        <div class="card__body">
          <div class="card__title">${escapeHTML(p.title)}</div>
          <div class="card__price-row">
            <span class="card__price">${priceHTML(p, p.price)}</span>
            ${p.old_price ? `<span class="card__old-price">${escapeHTML(priceText(p, p.old_price).replace(/ so'm$/, ''))}</span>` : ''}
          </div>
          <div class="card__rating">${p.rating ? Number(p.rating).toFixed(1) : '—'} · ${p.reviews_count || 0} sharh</div>
          <div class="card__synced">${synced}</div>
        </div>
      </article>
    `;
  }

  function renderProducts(items, target = '#products-grid', emptyTarget = '#empty-state') {
    const grid = $(target);
    if (!grid) return;
    grid.innerHTML = items.map(productCardHTML).join('');
    const empty = $(emptyTarget);
    if (empty) empty.hidden = items.length > 0;

    grid.querySelectorAll('.card').forEach(card => {
      card.addEventListener('click', e => {
        if (e.target.closest('[data-action="fav"]')) return;
        openProduct(Number(card.dataset.id));
      });
    });
    grid.querySelectorAll('[data-action="fav"]').forEach(btn => {
      btn.addEventListener('click', e => {
        e.stopPropagation();
        toggleFavorite(Number(btn.dataset.id), btn);
      });
    });
  }

  /** ---------- API actions ---------- */
  let productsAbort = null;

  async function loadProducts(append = false) {
    if (!append) state.offset = 0;
    if (productsAbort) productsAbort.abort();
    productsAbort = new AbortController();
    const signal = productsAbort.signal;

    const loader = $('#loader');
    if (loader) loader.hidden = false;
    try {
      const params = { limit: PAGE_SIZE, offset: state.offset };
      if (state.activeCategory) params.category = state.activeCategory;
      if (state.searchQuery) {
        params.q = state.searchQuery;
        // Live marketplace fan-out on real queries (first page only);
        // the server TTL-caches identical queries.
        if (!append && state.searchQuery.length >= 3) params.live = 1;
      }
      const res = await api('products', { params, signal });
      const items = res.products || [];
      state.hasMore = items.length === PAGE_SIZE;
      state.products = append ? state.products.concat(items) : items;
      state.products.forEach(p => { if (p.is_favorite) state.favIdSet.add(Number(p.id)); });
      renderProducts(state.products);
      updateLoadMore();
    } catch (e) {
      if (e.name === 'AbortError') return; // superseded by a newer request
      console.warn('loadProducts failed', e);
      renderProducts([]);
      state.hasMore = false;
      updateLoadMore();
    } finally {
      if (loader && !signal.aborted) loader.hidden = true;
    }
  }

  function updateLoadMore() {
    const btn = $('#load-more');
    if (btn) btn.hidden = !state.hasMore;
  }

  async function loadCategories() {
    try {
      const res = await api('categories');
      state.categories = res.categories || [];
      renderCategories();
    } catch (e) { console.warn('loadCategories failed', e); }
  }

  async function loadFavorites() {
    try {
      const res = await api('favorites');
      state.favorites = res.products || [];
      state.favIdSet = new Set(state.favorites.map(p => Number(p.id)));
      state.favorites.forEach(p => { p.is_favorite = true; });
      renderProducts(state.favorites, '#favorites-grid', '#favorites-empty');
      const stat = $('#stat-favorites');
      if (stat) stat.textContent = state.favorites.length;
    } catch (e) {
      const empty = $('#favorites-empty');
      if (empty) empty.hidden = false;
    }
  }

  async function loadMe() {
    try {
      const res = await api('me');
      state.me = res.user;
      const name = state.me ? [state.me.first_name, state.me.last_name].filter(Boolean).join(' ') : 'Mehmon';
      $('#profile-name').textContent = name || 'Foydalanuvchi';
      $('#profile-id').textContent = state.me ? `ID: ${state.me.id}` : '';
      $('#profile-avatar').textContent = (name || '?').slice(0, 1).toUpperCase();
    } catch (e) { console.warn('loadMe failed', e); }
  }

  async function loadSources() {
    try {
      const res = await api('sources');
      state.sources = res.sources || [];
      const stat = $('#stat-sources');
      if (stat) stat.textContent = state.sources.length;
    } catch (e) { /* ignore */ }
  }

  async function toggleFavorite(productId, btnEl) {
    try {
      const res = await api('toggle_favorite', { method: 'POST', body: { product_id: productId } });
      const favorited = !!res.favorited;
      if (favorited) state.favIdSet.add(productId); else state.favIdSet.delete(productId);
      if (btnEl) btnEl.classList.toggle('is-active', favorited);
      $$('.card[data-id="' + productId + '"] .card__fav').forEach(b => b.classList.toggle('is-active', favorited));
      const sheetFav = $('#sheet-fav');
      if (sheetFav && Number(sheetFav.dataset.id) === productId) {
        sheetFav.classList.toggle('is-active', favorited);
      }
    } catch (e) {
      if (tg && tg.showAlert) tg.showAlert('Avval botda /start ni bosing va qayta urunib ko\'ring.');
      console.warn('toggle_favorite failed', e);
    }
  }

  /** ---------- Product sheet ---------- */
  async function openProduct(id) {
    const sheet = $('#product-sheet');
    sheet.hidden = false;
    document.body.style.overflow = 'hidden';
    $('#sheet-title').textContent = 'Yuklanmoqda…';
    $('#sheet-price').textContent = '';
    $('#sheet-gallery').innerHTML = '';
    $('#sheet-desc').innerHTML = '';
    $('#sheet-meta').innerHTML = '';
    $('#sheet-seller').innerHTML = '';
    $('#sheet-synced').textContent = '';

    try {
      const res = await api('product', { params: { id } });
      const p = res.product;
      $('#sheet-title').textContent = p.title;
      $('#sheet-price').innerHTML = priceHTML(p, p.price);
      const oldEl = $('#sheet-old-price');
      const discEl = $('#sheet-discount');
      if (p.old_price) {
        oldEl.textContent = priceText(p, p.old_price).replace(/ so'm$/, '');
        const d = discountPercent(p.price, p.old_price);
        discEl.textContent = d ? `-${d}%` : '';
      } else {
        oldEl.textContent = '';
        discEl.textContent = '';
      }

      const imgs = (p.images && p.images.length ? p.images : (p.image_url ? [p.image_url] : []));
      $('#sheet-gallery').innerHTML = imgs
        .slice(0, 8)
        .map(src => `<img loading="lazy" src="${escapeHTML(src)}" alt="">`)
        .join('');

      $('#sheet-rating').innerHTML = (p.rating ? `★ ${Number(p.rating).toFixed(1)}` : '★ —')
        + ` · ${p.reviews_count || 0} sharh · ${p.sold_count || 0} marta sotib olingan`;

      const origNote = originalPriceNote(p);
      $('#sheet-meta').innerHTML = `
        <span>Manba: <strong>${escapeHTML(p.source)}</strong></span>
        ${p.category_name ? `<span>${escapeHTML(p.category_name)}</span>` : ''}
        ${origNote ? `<span>Asl narx: ${escapeHTML(origNote)}</span>` : ''}
      `;
      if (p.synced_at_human) {
        $('#sheet-synced').textContent = `🟢 Narx yangilangan: ${p.synced_at_human}`;
      }

      if (p.seller) {
        $('#sheet-seller').innerHTML = `<strong>Sotuvchi</strong>${escapeHTML(p.seller)}${p.seller_rating ? ` · ★ ${Number(p.seller_rating).toFixed(1)}` : ''}`;
      }
      const desc = stripTags(p.description || '');
      if (desc) $('#sheet-desc').textContent = desc;

      const fav = $('#sheet-fav');
      fav.dataset.id = id;
      fav.classList.toggle('is-active', !!p.is_favorite);
      fav.onclick = () => toggleFavorite(id, fav);

      const cta = $('#cta-buy');
      cta.textContent = `${p.source[0].toUpperCase() + p.source.slice(1)}da sotib olish`;
      cta.onclick = () => {
        if (p.external_url) {
          if (tg && tg.openLink) tg.openLink(p.external_url);
          else window.open(p.external_url, '_blank');
        }
      };
    } catch (e) {
      $('#sheet-title').textContent = 'Xatolik';
      $('#sheet-desc').textContent = e.message || 'Mahsulotni yuklab bo\'lmadi.';
    }
  }

  function closeSheet() {
    $('#product-sheet').hidden = true;
    document.body.style.overflow = '';
  }

  /** ---------- Navigation ---------- */
  function goPage(name) {
    $$('.page').forEach(el => el.classList.remove('page--active'));
    const target = $(`#page-${name}`);
    if (target) target.classList.add('page--active');
    $$('.nav-item').forEach(el => el.classList.toggle('is-active', el.dataset.page === name));
    if (name === 'favorites') loadFavorites();
  }

  /** ---------- Init ---------- */
  function bind() {
    $$('.nav-item').forEach(el => {
      el.addEventListener('click', () => goPage(el.dataset.page));
    });
    $('#sheet-close').addEventListener('click', closeSheet);
    $('.sheet__overlay').addEventListener('click', closeSheet);

    let searchTimer = null;
    $('#search-input').addEventListener('input', e => {
      state.searchQuery = e.target.value.trim();
      clearTimeout(searchTimer);
      searchTimer = setTimeout(() => loadProducts(false), 300);
    });

    const loadMoreBtn = $('#load-more');
    if (loadMoreBtn) {
      loadMoreBtn.addEventListener('click', () => {
        state.offset += PAGE_SIZE;
        loadProducts(true);
      });
    }
  }

  async function init() {
    bind();
    await loadCategories();
    await loadProducts();
    await loadMe();
    await loadSources();
  }

  document.addEventListener('DOMContentLoaded', init);
})();
