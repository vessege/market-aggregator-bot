/* ===== MarketCompare WebApp =====
 * Visual design imported from a user-provided template.
 * API integration adapted to the existing market-aggregator-bot backend.
 */
(function () {
  'use strict';

  // ---------- Telegram WebApp ----------
  const tg = (window.Telegram && window.Telegram.WebApp) ? window.Telegram.WebApp : null;
  if (tg) { tg.ready(); tg.expand(); }
  const initData = tg ? (tg.initData || '') : '';

  const API_BASE = '../api/index.php';

  // ---------- i18n ----------
  const I18N = {
    uz: {
      search: 'Qidirish', searching: 'Qidirilmoqda...', empty: 'Hech narsa topilmadi',
      all: 'Barchasi', score: '🏆 Reyting', cheap: '💰 Arzon', expensive: '💎 Qimmat',
      reviews: '⭐ Sharhlar', open: '🛒 Marketda sotib olish',
      favSaved: 'Sevimlilarga qo\'shildi', favRem: 'Olib tashlandi',
      favOpen: 'Sevimlilar', noFav: 'Hozircha sevimli mahsulot yo\'q',
      langTitle: 'Tilni tanlang', delivery: 'Yetkazib berish', days: 'kun',
      seller: 'Sotuvchi', rating: 'Reyting', source: 'Manba',
      similar: 'Boshqa mahsulotlar', synced: 'Yangilangan',
      markets: 'Marketplacelar', categories: 'Mashhur kategoriyalar',
      topProducts: 'Mashhur mahsulotlar', product: 'Mahsulot', profile: 'Profil',
      navHome: 'Bosh sahifa', share: 'Ulashish', admin: 'Admin panel',
      authNeeded: 'Telegram orqali oching',
      local: '🇺🇿 Mahalliy', intl: '🌍 Xalqaro',
    },
    ru: {
      search: 'Поиск', searching: 'Поиск...', empty: 'Ничего не найдено',
      all: 'Все', score: '🏆 Рейтинг', cheap: '💰 Дешёвые', expensive: '💎 Дорогие',
      reviews: '⭐ Отзывы', open: '🛒 Купить в магазине',
      favSaved: 'Добавлено в избранное', favRem: 'Удалено',
      favOpen: 'Избранное', noFav: 'Нет избранных товаров',
      langTitle: 'Выберите язык', delivery: 'Доставка', days: 'дн.',
      seller: 'Продавец', rating: 'Рейтинг', source: 'Источник',
      similar: 'Похожие товары', synced: 'Обновлено',
      markets: 'Маркетплейсы', categories: 'Категории',
      topProducts: 'Популярные товары', product: 'Товар', profile: 'Профиль',
      navHome: 'Главная', share: 'Поделиться', admin: 'Админ панель',
      authNeeded: 'Откройте через Telegram',
      local: '🇺🇿 Локальные', intl: '🌍 Международные',
    },
    en: {
      search: 'Search', searching: 'Searching...', empty: 'Nothing found',
      all: 'All', score: '🏆 Score', cheap: '💰 Cheapest', expensive: '💎 Premium',
      reviews: '⭐ Reviews', open: '🛒 Buy on marketplace',
      favSaved: 'Added to favorites', favRem: 'Removed',
      favOpen: 'Favorites', noFav: 'No favorites yet',
      langTitle: 'Select language', delivery: 'Delivery', days: 'days',
      seller: 'Seller', rating: 'Rating', source: 'Source',
      similar: 'More products', synced: 'Updated',
      markets: 'Marketplaces', categories: 'Categories',
      topProducts: 'Top products', product: 'Product', profile: 'Profile',
      navHome: 'Home', share: 'Share', admin: 'Admin panel',
      authNeeded: 'Open via Telegram',
      local: '🇺🇿 Local', intl: '🌍 International',
    },
  };
  let lang = localStorage.getItem('mc_lang')
    || (tg && tg.initDataUnsafe && tg.initDataUnsafe.user && tg.initDataUnsafe.user.language_code)
    || 'uz';
  if (!I18N[lang]) lang = 'uz';
  const t = (k) => (I18N[lang] && I18N[lang][k]) || k;

  // ---------- State ----------
  const state = {
    products: [],
    sourceFilter: '',
    sortMode: 'popular',
    marketplaces: [],
    currentProduct: null,
    favIdSet: new Set(),
    me: null,
    lastQuery: '',
    lastSource: '',
  };

  // ---------- Utilities ----------
  const $ = (s) => document.querySelector(s);
  const $$ = (s) => Array.from(document.querySelectorAll(s));

  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    })[c]);
  }

  function fmtPrice(amount, currency) {
    currency = (currency || 'UZS').toUpperCase();
    const n = Math.round(Number(amount) || 0);
    const s = n.toLocaleString('ru-RU').replace(/,/g, ' ');
    if (currency === 'UZS') return s + ' soʻm';
    if (currency === 'USD') return '$' + s;
    if (currency === 'RUB') return s + ' ₽';
    if (currency === 'EUR') return '€' + s;
    return s + ' ' + currency;
  }

  function toast(msg) {
    const el = $('#toast');
    if (!el) return;
    el.textContent = msg;
    el.classList.add('show');
    clearTimeout(toast._t);
    toast._t = setTimeout(() => el.classList.remove('show'), 1800);
  }

  function showView(name) {
    $$('.view').forEach((v) => v.classList.remove('active'));
    const v = document.getElementById('view-' + name);
    if (v) v.classList.add('active');
    $$('.nav-btn[data-view]').forEach((b) => {
      b.classList.toggle('active', b.dataset.view === name);
    });
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  // ---------- API ----------
  async function api(action, opts) {
    opts = opts || {};
    const url = new URL(API_BASE, window.location.href);
    url.searchParams.set('action', action);
    if (opts.params) {
      for (const k of Object.keys(opts.params)) {
        const v = opts.params[k];
        if (v !== null && v !== undefined && v !== '') url.searchParams.set(k, v);
      }
    }
    const headers = {
      Accept: 'application/json',
      'X-Telegram-Init-Data': initData || '',
    };
    if (opts.body) headers['Content-Type'] = 'application/json';
    const res = await fetch(url.toString(), {
      method: opts.method || 'GET',
      headers: headers,
      body: opts.body ? JSON.stringify(opts.body) : undefined,
    });
    let json;
    try { json = await res.json(); }
    catch (e) { return { ok: false, error: 'invalid json' }; }
    return json;
  }

  // ---------- Backend → UI normalization ----------
  function srcLabel(code) {
    const m = state.marketplaces.find((x) => x.code === code);
    return m ? m.name : (code || '').toString().toUpperCase();
  }

  function srcCategory(code) {
    if (!code) return 'international';
    const local = ['uzum', 'olx', 'sello', 'asaxiy', 'texnomart'];
    return local.includes(String(code).toLowerCase()) ? 'local' : 'international';
  }

  function normalize(p) {
    if (!p) return null;
    const img = (p.images && p.images[0]) || p.image_url || '';
    const isFav = !!p.is_favorite || state.favIdSet.has(Number(p.id));
    return {
      id: Number(p.id),
      source: p.source || '',
      source_name: srcLabel(p.source || ''),
      title: p.title || '',
      price: Number(p.price || 0),
      old_price: p.old_price ? Number(p.old_price) : null,
      currency: p.currency || 'UZS',
      url: p.external_url || p.url || '#',
      image: img,
      rating: p.rating ? Number(p.rating) : 0,
      reviews: Number(p.reviews_count || p.reviews || 0),
      sold: Number(p.sold_count || 0),
      seller: p.seller || '',
      synced: p.synced_at_human || '',
      updated_at: p.updated_at || '',
      is_favorite: isFav,
    };
  }

  // ---------- Render ----------
  function renderMarketplaces() {
    const grid = $('#marketGrid');
    if (!grid) return;
    grid.innerHTML = '';
    if (!state.marketplaces.length) {
      grid.innerHTML = '<div class="loading">⏳</div>';
      return;
    }
    state.marketplaces.forEach((m) => {
      const initial = (m.name || m.code || '?').substring(0, 1).toUpperCase();
      const el = document.createElement('button');
      el.className = 'market-card';
      el.innerHTML = `
        <div class="icon">${escapeHtml(initial)}</div>
        <div class="name">${escapeHtml(m.name)}</div>
        <div class="badge">${m.category === 'local' ? t('local') : t('intl')}</div>`;
      el.onclick = () => openMarketplace(m);
      grid.appendChild(el);
    });
  }

  function productCard(p) {
    const card = document.createElement('div');
    card.className = 'product-card';
    const score = p.rating ? Math.round(p.rating * 20) : 0; // 0-100 from rating
    const oldP = p.old_price && p.old_price > p.price
      ? `<span class="old-price">${fmtPrice(p.old_price, p.currency)}</span>` : '';
    const ratingTxt = p.rating ? '⭐ ' + p.rating.toFixed(1) : '';
    const soldTxt = p.sold ? '🛒 ' + p.sold : '';
    const imgTag = p.image
      ? `<img loading="lazy" src="${escapeHtml(p.image)}" alt="" onerror="this.style.display='none'">`
      : '';
    card.innerHTML = `
      <div class="img-wrap">
        <span class="src-tag">${escapeHtml(p.source_name || p.source)}</span>
        ${score ? `<span class="score-tag">${score}</span>` : ''}
        ${imgTag}
      </div>
      <div class="body">
        <div class="title">${escapeHtml(p.title)}</div>
        <div class="price">${fmtPrice(p.price, p.currency)}${oldP}</div>
        <div class="meta">
          <span>${ratingTxt}</span>
          <span>${soldTxt}</span>
        </div>
      </div>`;
    card.onclick = () => openProduct(p);
    return card;
  }

  function renderResults() {
    const grid = $('#resultsGrid');
    if (!grid) return;
    grid.innerHTML = '';
    let list = state.products.slice();
    if (state.sourceFilter) list = list.filter((p) => p.source === state.sourceFilter);
    list = sortList(list, state.sortMode);
    const empty = $('#resultsEmpty');
    if (!list.length) {
      if (empty) empty.classList.remove('hidden');
      return;
    }
    if (empty) empty.classList.add('hidden');
    list.forEach((p) => grid.appendChild(productCard(p)));
  }

  function sortList(list, mode) {
    const copy = list.slice();
    if (mode === 'price_asc') copy.sort((a, b) => a.price - b.price);
    else if (mode === 'price_desc') copy.sort((a, b) => b.price - a.price);
    else if (mode === 'rating') copy.sort((a, b) => (b.rating || 0) - (a.rating || 0));
    else copy.sort((a, b) => (b.sold || 0) - (a.sold || 0)); // popular
    return copy;
  }

  function renderFilterChips() {
    const bar = $('#filterBar');
    if (!bar) return;
    bar.innerHTML = '';
    const chipAll = document.createElement('button');
    chipAll.className = 'chip' + (state.sourceFilter === '' ? ' active' : '');
    chipAll.dataset.source = '';
    chipAll.textContent = t('all');
    bar.appendChild(chipAll);
    const sources = Array.from(new Set(state.products.map((p) => p.source))).filter(Boolean);
    sources.forEach((s) => {
      const c = document.createElement('button');
      c.className = 'chip' + (state.sourceFilter === s ? ' active' : '');
      c.dataset.source = s;
      c.textContent = srcLabel(s);
      bar.appendChild(c);
    });
    bar.querySelectorAll('.chip').forEach((b) => {
      b.onclick = () => {
        state.sourceFilter = b.dataset.source;
        bar.querySelectorAll('.chip').forEach((x) => x.classList.remove('active'));
        b.classList.add('active');
        renderResults();
      };
    });
  }

  // ---------- Actions ----------
  async function performSearch(q, sourceOverride) {
    const query = (q == null ? '' : String(q)).trim();
    showView('results');
    const titleEl = $('#resultsTitle');
    if (titleEl) titleEl.textContent = query ? '"' + query + '"' : t('all');
    $('#resultsGrid').innerHTML = '';
    $('#resultsLoading').classList.remove('hidden');
    $('#resultsEmpty').classList.add('hidden');

    state.lastQuery = query;
    state.lastSource = sourceOverride || '';
    state.sourceFilter = sourceOverride || '';

    const params = { limit: 30, sort: state.sortMode };
    if (query) params.q = query;
    if (sourceOverride) params.source = sourceOverride;
    const data = await api('products', { params: params });

    $('#resultsLoading').classList.add('hidden');
    state.products = (data.ok ? (data.products || []) : []).map(normalize).filter(Boolean);
    syncFavSetFrom(state.products);
    renderFilterChips();
    renderResults();
  }

  function openMarketplace(m) {
    showView('market');
    $('#marketTitle').textContent = m.name;
    const input = $('#marketSearchInput');
    if (input) {
      input.value = '';
      input.dataset.source = m.code;
    }
    runMarketSearch(m.code, '');
  }

  async function runMarketSearch(source, q) {
    const grid = $('#marketGridResults');
    grid.innerHTML = '';
    $('#marketLoading').classList.remove('hidden');
    $('#marketEmpty').classList.add('hidden');
    const params = { source: source, limit: 30 };
    if (q) params.q = q;
    const data = await api('products', { params: params });
    $('#marketLoading').classList.add('hidden');
    const list = (data.ok ? (data.products || []) : []).map(normalize).filter(Boolean);
    if (!list.length) {
      $('#marketEmpty').classList.remove('hidden');
      return;
    }
    list.forEach((p) => grid.appendChild(productCard(p)));
  }

  async function openProduct(p) {
    state.currentProduct = p;
    showView('product');
    const root = $('#productDetail');
    root.innerHTML = '<div class="loading">⏳</div>';

    // Fetch fresh detail (triggers backend on-demand refresh if stale).
    let fresh = p;
    try {
      const data = await api('product', { params: { id: p.id } });
      if (data.ok && data.product) fresh = normalize(data.product);
    } catch (e) { /* keep cached version */ }
    state.currentProduct = fresh;

    // Sync fav set
    if (fresh.is_favorite) state.favIdSet.add(fresh.id);
    else state.favIdSet.delete(fresh.id);
    const favBtn = $('#favBtn');
    favBtn.classList.toggle('active', !!fresh.is_favorite);
    favBtn.textContent = fresh.is_favorite ? '❤️' : '🤍';

    const oldP = fresh.old_price && fresh.old_price > fresh.price
      ? `<span class="detail-old">${fmtPrice(fresh.old_price, fresh.currency)}</span>` : '';

    const others = state.products
      .filter((x) => x.id !== fresh.id)
      .slice(0, 5);
    const compare = others.map((o) => `
      <a class="compare-row" href="${escapeHtml(o.url)}" target="_blank" rel="noopener">
        <div>
          <div class="src">${escapeHtml(o.source_name || o.source)}</div>
          <div>${escapeHtml((o.title || '').substring(0, 50))}</div>
        </div>
        <div class="p">${fmtPrice(o.price, o.currency)}</div>
      </a>`).join('');

    const syncedHtml = fresh.synced
      ? `<div class="detail-synced">🟢 ${t('synced')}: ${escapeHtml(fresh.synced)}</div>`
      : '';

    root.innerHTML = `
      <div class="detail-img">${fresh.image ? `<img src="${escapeHtml(fresh.image)}" alt="">` : ''}</div>
      <div class="detail-title">${escapeHtml(fresh.title)}</div>
      <div><span class="detail-price">${fmtPrice(fresh.price, fresh.currency)}</span>${oldP}</div>
      ${syncedHtml}
      <div class="detail-row"><span class="label">${t('source')}</span><span class="value">${escapeHtml(fresh.source_name || fresh.source)}</span></div>
      ${fresh.rating ? `<div class="detail-row"><span class="label">${t('rating')}</span><span class="value">⭐ ${fresh.rating.toFixed(1)} (${fresh.reviews})</span></div>` : ''}
      ${fresh.seller ? `<div class="detail-row"><span class="label">${t('seller')}</span><span class="value">${escapeHtml(fresh.seller)}</span></div>` : ''}
      <a class="detail-buy" href="${escapeHtml(fresh.url)}" target="_blank" rel="noopener" id="detailBuyBtn">${t('open')}</a>
      ${others.length ? `<h3 class="section-title">${t('similar')}</h3><div class="compare-list">${compare}</div>` : ''}
    `;

    // Use Telegram openLink API if available (more reliable inside Telegram WebView).
    const buyBtn = document.getElementById('detailBuyBtn');
    if (buyBtn && tg) {
      buyBtn.addEventListener('click', (e) => {
        if (typeof tg.openLink === 'function') {
          e.preventDefault();
          tg.openLink(fresh.url);
        }
      });
    }
  }

  function syncFavSetFrom(list) {
    list.forEach((p) => {
      if (p.is_favorite) state.favIdSet.add(p.id);
    });
  }

  async function loadHomeProducts() {
    const grid = $('#homeGrid');
    if (!grid) return;
    grid.innerHTML = '';
    $('#resultsLoadingHome').classList.remove('hidden');
    const data = await api('products', { params: { limit: 12, sort: 'popular' } });
    $('#resultsLoadingHome').classList.add('hidden');
    const list = (data.ok ? (data.products || []) : []).map(normalize).filter(Boolean);
    syncFavSetFrom(list);
    list.forEach((p) => grid.appendChild(productCard(p)));
    // Cache for cross-source comparison in detail view
    state.products = list;
  }

  async function openFavs() {
    showView('favs');
    const grid = $('#favsGrid');
    grid.innerHTML = '';
    $('#favsEmpty').classList.add('hidden');
    $('#favsLoading').classList.remove('hidden');
    const data = await api('favorites');
    $('#favsLoading').classList.add('hidden');
    if (!data.ok) {
      $('#favsEmpty').classList.remove('hidden');
      $('#favsEmpty').textContent = data.error === 'auth required' ? t('authNeeded') : t('noFav');
      return;
    }
    const list = (data.products || []).map(normalize).filter(Boolean);
    list.forEach((p) => state.favIdSet.add(p.id));
    if (!list.length) {
      $('#favsEmpty').classList.remove('hidden');
      $('#favsEmpty').textContent = t('noFav');
      return;
    }
    list.forEach((p) => grid.appendChild(productCard(p)));
  }

  async function toggleFavCurrent() {
    const p = state.currentProduct;
    if (!p) return;
    if (!initData) { toast(t('authNeeded')); return; }
    const data = await api('toggle_favorite', { method: 'POST', body: { product_id: p.id } });
    if (!data.ok) {
      toast(data.error || 'error');
      return;
    }
    const added = !!data.favorited;
    p.is_favorite = added;
    if (added) state.favIdSet.add(p.id);
    else state.favIdSet.delete(p.id);
    const btn = $('#favBtn');
    btn.classList.toggle('active', added);
    btn.textContent = added ? '❤️' : '🤍';
    toast(added ? t('favSaved') : t('favRem'));
  }

  // ---------- Profile ----------
  async function openProfile() {
    showView('profile');
    if (state.me === null) {
      try {
        const data = await api('me');
        if (data.ok) state.me = data.user || null;
      } catch (e) { state.me = null; }
    }
    const u = state.me;
    const nameEl = $('#profileName');
    const idEl = $('#profileId');
    const avEl = $('#profileAvatar');
    if (u) {
      const fullName = [u.first_name, u.last_name].filter(Boolean).join(' ') || 'User';
      nameEl.textContent = fullName;
      idEl.textContent = u.username ? '@' + u.username : ('ID: ' + (u.id || ''));
      avEl.textContent = (fullName || '?').substring(0, 1).toUpperCase();
    } else {
      nameEl.textContent = 'Mehmon';
      idEl.textContent = t('authNeeded');
      avEl.textContent = '?';
    }
    // Stats
    try {
      const favs = await api('favorites');
      $('#statFavs').textContent = (favs.ok && favs.products) ? favs.products.length : 0;
    } catch (e) { $('#statFavs').textContent = 0; }
    $('#statSources').textContent = state.marketplaces.length;
  }

  // ---------- Events ----------
  function bindEvents() {
    $('#searchBtn').onclick = () => performSearch($('#searchInput').value, '');
    $('#searchInput').addEventListener('keydown', (e) => {
      if (e.key === 'Enter') performSearch(e.target.value, '');
    });
    $$('.cat-card').forEach((b) => {
      b.onclick = () => performSearch(b.dataset.q, '');
    });
    $$('.sort-btn').forEach((b) => {
      b.onclick = () => {
        $$('.sort-btn').forEach((x) => x.classList.remove('active'));
        b.classList.add('active');
        state.sortMode = b.dataset.sort;
        // Re-fetch with sort if we have a previous query, otherwise just re-sort cached
        if (state.lastQuery !== '' || state.lastSource !== '') {
          performSearch(state.lastQuery, state.lastSource);
        } else {
          renderResults();
        }
      };
    });
    $$('.back-btn').forEach((b) => {
      b.onclick = () => showView(b.dataset.back || 'home');
    });
    $$('.nav-btn').forEach((b) => {
      b.onclick = () => {
        const v = b.dataset.view;
        if (v === 'favs') openFavs();
        else if (v === 'profile') openProfile();
        else showView(v);
      };
    });
    $('#favBtn').onclick = toggleFavCurrent;
    $('#langBtn').onclick = showLangPicker;

    const ms = $('#menuShare');
    if (ms) ms.onclick = () => {
      if (tg && typeof tg.switchInlineQuery === 'function') {
        try { tg.switchInlineQuery('', ['users', 'groups']); return; } catch (e) {}
      }
      if (navigator.share) navigator.share({ title: 'MarketCompare', url: location.href }).catch(() => {});
      else toast(location.href);
    };
    const ml = $('#menuLang');
    if (ml) ml.onclick = showLangPicker;

    $('#marketSearchInput').addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        const src = e.target.dataset.source;
        runMarketSearch(src, e.target.value);
      }
    });
  }

  function showLangPicker() {
    const m = document.createElement('div');
    m.className = 'lang-modal show';
    m.innerHTML = `
      <div class="sheet">
        <h3>${t('langTitle')}</h3>
        <button data-l="uz">🇺🇿 O‘zbekcha</button>
        <button data-l="ru">🇷🇺 Русский</button>
        <button data-l="en">🇬🇧 English</button>
      </div>`;
    m.onclick = (e) => {
      const l = e.target && e.target.dataset && e.target.dataset.l;
      if (l) {
        lang = l;
        localStorage.setItem('mc_lang', lang);
        $('#langBtn').textContent = '🌐 ' + lang.toUpperCase();
        applyTexts();
        m.remove();
      } else if (e.target === m) {
        m.remove();
      }
    };
    document.body.appendChild(m);
  }

  function applyTexts() {
    $('#searchInput').placeholder = t('search') + '...';
    const ms = $('#marketSearchInput');
    if (ms) ms.placeholder = t('search') + '...';
    $('#searchBtn').textContent = t('search');
    const sortBtns = $$('.sort-btn');
    if (sortBtns[0]) sortBtns[0].textContent = t('score');
    if (sortBtns[1]) sortBtns[1].textContent = t('cheap');
    if (sortBtns[2]) sortBtns[2].textContent = t('expensive');
    if (sortBtns[3]) sortBtns[3].textContent = t('reviews');
    document.querySelectorAll('[data-i18n]').forEach((el) => {
      const k = el.dataset.i18n;
      if (k && I18N[lang] && I18N[lang][k]) el.textContent = I18N[lang][k];
    });
    if (state.marketplaces.length) renderMarketplaces();
  }

  // ---------- Init ----------
  async function init() {
    $('#langBtn').textContent = '🌐 ' + lang.toUpperCase();
    bindEvents();
    applyTexts();
    try {
      const m = await api('sources');
      if (m.ok && Array.isArray(m.sources)) {
        state.marketplaces = m.sources.map((s) => ({
          code: s.source,
          name: s.name,
          category: srcCategory(s.source),
        }));
      }
    } catch (e) { /* ignore */ }
    renderMarketplaces();
    loadHomeProducts();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
