/* ==============================================================
 * MarketCompare — standalone web frontend
 * No Telegram WebApp dependency. Liquid-glass UI.
 * ============================================================== */
(function () {
  'use strict';

  const API_BASE = '../api/index.php';

  // ---------- i18n ----------
  const I18N = {
    uz: {
      heroTitle: 'Bir qidiruv — ko‘p marketdan natija',
      heroSub: 'Uzum, Wildberries va boshqa marketplacelar bo‘yicha narxlarni real vaqtda taqqoslang.',
      search: 'Qidirish', searching: 'Qidirilmoqda...', empty: 'Hech narsa topilmadi',
      all: 'Barchasi', score: '🏆 Reyting', cheap: '💰 Arzon', expensive: '💎 Qimmat',
      reviews: '⭐ Sharhlar', open: '🛒 Marketda sotib olish',
      langTitle: 'Tilni tanlang', delivery: 'Yetkazib berish', days: 'kun',
      seller: 'Sotuvchi', rating: 'Reyting', source: 'Manba',
      similar: 'Boshqa mahsulotlar', synced: 'Yangilangan',
      markets: 'Marketplacelar', categories: 'Mashhur kategoriyalar',
      topProducts: 'Mashhur mahsulotlar', product: 'Mahsulot',
      navHome: 'Bosh sahifa', admin: 'Admin',
      local: '🇺🇿 Mahalliy', intl: '🌍 Xalqaro',
      catPhone: 'Smartfon', catLaptop: 'Noutbuk', catTv: 'Televizor',
      catShoes: 'Krossovka', catWatch: 'Soat', catHeadphones: 'Naushnik',
    },
    ru: {
      heroTitle: 'Один поиск — результаты со всех маркетплейсов',
      heroSub: 'Сравнивайте цены на Uzum, Wildberries и других маркетплейсах в реальном времени.',
      search: 'Поиск', searching: 'Поиск...', empty: 'Ничего не найдено',
      all: 'Все', score: '🏆 Рейтинг', cheap: '💰 Дешёвые', expensive: '💎 Дорогие',
      reviews: '⭐ Отзывы', open: '🛒 Купить в магазине',
      langTitle: 'Выберите язык', delivery: 'Доставка', days: 'дн.',
      seller: 'Продавец', rating: 'Рейтинг', source: 'Источник',
      similar: 'Похожие товары', synced: 'Обновлено',
      markets: 'Маркетплейсы', categories: 'Категории',
      topProducts: 'Популярные товары', product: 'Товар',
      navHome: 'Главная', admin: 'Админ',
      local: '🇺🇿 Локальные', intl: '🌍 Международные',
      catPhone: 'Смартфон', catLaptop: 'Ноутбук', catTv: 'Телевизор',
      catShoes: 'Кроссовки', catWatch: 'Часы', catHeadphones: 'Наушники',
    },
    en: {
      heroTitle: 'One search — results from many marketplaces',
      heroSub: 'Compare prices across Uzum, Wildberries and other marketplaces in real time.',
      search: 'Search', searching: 'Searching...', empty: 'Nothing found',
      all: 'All', score: '🏆 Score', cheap: '💰 Cheapest', expensive: '💎 Premium',
      reviews: '⭐ Reviews', open: '🛒 Buy on marketplace',
      langTitle: 'Select language', delivery: 'Delivery', days: 'days',
      seller: 'Seller', rating: 'Rating', source: 'Source',
      similar: 'More products', synced: 'Updated',
      markets: 'Marketplaces', categories: 'Categories',
      topProducts: 'Top products', product: 'Product',
      navHome: 'Home', admin: 'Admin',
      local: '🇺🇿 Local', intl: '🌍 International',
      catPhone: 'Phone', catLaptop: 'Laptop', catTv: 'TV',
      catShoes: 'Sneakers', catWatch: 'Watch', catHeadphones: 'Headphones',
    },
  };

  let lang = localStorage.getItem('mc_lang') || 'uz';
  if (!I18N[lang]) lang = 'uz';
  const t = (k) => (I18N[lang] && I18N[lang][k]) || k;

  // ---------- Theme ----------
  function getStoredTheme() {
    return localStorage.getItem('mc_theme') || '';
  }
  function applyTheme(theme) {
    if (theme === 'light' || theme === 'dark') {
      document.documentElement.setAttribute('data-theme', theme);
    } else {
      document.documentElement.removeAttribute('data-theme');
    }
    const btn = document.getElementById('themeBtn');
    if (btn) {
      const eff = theme || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
      btn.textContent = eff === 'dark' ? '🌙' : '☀️';
    }
  }
  function toggleTheme() {
    const cur = getStoredTheme();
    const sysDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    let next;
    if (!cur) next = sysDark ? 'light' : 'dark';
    else if (cur === 'dark') next = 'light';
    else next = 'dark';
    localStorage.setItem('mc_theme', next);
    applyTheme(next);
  }

  // ---------- State ----------
  const state = {
    products: [],
    sourceFilter: '',
    sortMode: 'popular',
    marketplaces: [],
    currentProduct: null,
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
    if (currency === 'UZS') return s + ' so‘m';
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
    const headers = { Accept: 'application/json' };
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
    const score = p.rating ? Math.round(p.rating * 20) : 0;
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
    const list = state.products.slice();
    if (state.sourceFilter) list.filter((p) => p.source === state.sourceFilter);
    sortProducts(list);
    const grid = $('#resultsGrid');
    grid.innerHTML = '';
    if (!list.length) {
      $('#resultsEmpty').classList.remove('hidden');
      return;
    }
    $('#resultsEmpty').classList.add('hidden');
    list.forEach((p) => grid.appendChild(productCard(p)));
  }

  function sortProducts(list) {
    switch (state.sortMode) {
      case 'price_asc':  list.sort((a, b) => a.price - b.price); break;
      case 'price_desc': list.sort((a, b) => b.price - a.price); break;
      case 'rating':     list.sort((a, b) => b.reviews - a.reviews); break;
      case 'popular':
      default:           list.sort((a, b) => (b.rating * 20 + Math.log10(b.reviews + 1) * 5) -
                                              (a.rating * 20 + Math.log10(a.reviews + 1) * 5));
    }
  }

  function buildFilterChips() {
    const bar = $('#filterBar');
    if (!bar) return;
    bar.innerHTML = '';
    const all = document.createElement('button');
    all.className = 'chip active';
    all.dataset.source = '';
    all.textContent = t('all');
    bar.appendChild(all);

    const present = new Set(state.products.map((p) => p.source));
    state.marketplaces.filter((m) => present.has(m.code)).forEach((m) => {
      const c = document.createElement('button');
      c.className = 'chip';
      c.dataset.source = m.code;
      c.textContent = m.name;
      bar.appendChild(c);
    });

    $$('#filterBar .chip').forEach((c) => {
      c.onclick = () => {
        $$('#filterBar .chip').forEach((x) => x.classList.remove('active'));
        c.classList.add('active');
        state.sourceFilter = c.dataset.source || '';
        const list = state.sourceFilter
          ? state.products.filter((p) => p.source === state.sourceFilter)
          : state.products.slice();
        sortProducts(list);
        const grid = $('#resultsGrid');
        grid.innerHTML = '';
        if (!list.length) {
          $('#resultsEmpty').classList.remove('hidden');
        } else {
          $('#resultsEmpty').classList.add('hidden');
          list.forEach((p) => grid.appendChild(productCard(p)));
        }
      };
    });
  }

  async function performSearch(query, source) {
    const q = (query || '').trim();
    state.lastQuery = q;
    state.lastSource = source || '';
    showView('results');
    $('#resultsTitle').textContent = q ? `🔍 "${q}"` : t('topProducts');
    $('#resultsLoading').classList.remove('hidden');
    $('#resultsEmpty').classList.add('hidden');
    $('#resultsGrid').innerHTML = '';
    const data = await api('products', {
      params: { q: q, source: source || '', sort: state.sortMode, limit: 40 },
    });
    $('#resultsLoading').classList.add('hidden');
    const list = (data.ok ? (data.products || []) : []).map(normalize).filter(Boolean);
    state.products = list;
    state.sourceFilter = '';
    buildFilterChips();
    renderResults();
  }

  async function openMarketplace(m) {
    showView('market');
    $('#marketTitle').textContent = m.name;
    const inp = $('#marketSearchInput');
    if (inp) inp.dataset.source = m.code;
    runMarketSearch(m.code, '');
  }

  async function runMarketSearch(source, query) {
    $('#marketLoading').classList.remove('hidden');
    $('#marketEmpty').classList.add('hidden');
    $('#marketGridResults').innerHTML = '';
    const data = await api('products', {
      params: { source: source || '', q: query || '', limit: 40 },
    });
    $('#marketLoading').classList.add('hidden');
    const list = (data.ok ? (data.products || []) : []).map(normalize).filter(Boolean);
    if (!list.length) {
      $('#marketEmpty').classList.remove('hidden');
      return;
    }
    list.forEach((p) => $('#marketGridResults').appendChild(productCard(p)));
  }

  async function openProduct(p) {
    state.currentProduct = p;
    showView('product');
    const root = $('#productDetail');
    root.innerHTML = '<div class="loading">⏳</div>';

    const data = await api('product', { params: { id: p.id } });
    let fresh = p;
    if (data.ok && data.product) fresh = normalize(data.product);

    const others = state.products
      .filter((x) => x.title === fresh.title && x.source !== fresh.source)
      .sort((a, b) => a.price - b.price);
    const oldP = fresh.old_price && fresh.old_price > fresh.price
      ? `<span class="detail-old">${fmtPrice(fresh.old_price, fresh.currency)}</span>` : '';
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
      <a class="detail-buy" href="${escapeHtml(fresh.url)}" target="_blank" rel="noopener">${t('open')}</a>
      ${others.length ? `<h3 class="section-title">${t('similar')}</h3><div class="compare-list">${compare}</div>` : ''}
    `;
  }

  async function loadHomeProducts() {
    const grid = $('#homeGrid');
    if (!grid) return;
    grid.innerHTML = '';
    $('#resultsLoadingHome').classList.remove('hidden');
    const data = await api('products', { params: { limit: 12, sort: 'popular' } });
    $('#resultsLoadingHome').classList.add('hidden');
    const list = (data.ok ? (data.products || []) : []).map(normalize).filter(Boolean);
    list.forEach((p) => grid.appendChild(productCard(p)));
    state.products = list;
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
    $$('.nav-btn[data-view]').forEach((b) => {
      b.onclick = () => {
        const v = b.dataset.view;
        if (v === 'markets-jump') {
          showView('home');
          // Smooth scroll to marketplaces section.
          setTimeout(() => {
            const target = document.getElementById('marketGrid');
            if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
          }, 50);
        } else {
          showView(v);
        }
      };
    });
    $('#langBtn').onclick = showLangPicker;
    $('#themeBtn').onclick = toggleTheme;

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
        const lbl = $('#langLabel');
        if (lbl) lbl.textContent = lang.toUpperCase();
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
    const sb = $('#searchBtn').querySelector('span');
    if (sb) sb.textContent = t('search');
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
    applyTheme(getStoredTheme());
    const lbl = $('#langLabel');
    if (lbl) lbl.textContent = lang.toUpperCase();
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
