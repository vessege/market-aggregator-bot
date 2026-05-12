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
      navHome: 'Bosh sahifa', admin: 'Admin', more: 'taklif',
      local: '🇺🇿 Mahalliy', intl: '🌍 Xalqaro',
      catPhone: 'Smartfon', catLaptop: 'Noutbuk', catTv: 'Televizor',
      catShoes: 'Krossovka', catWatch: 'Soat', catHeadphones: 'Naushnik',
      priceHistory: 'Narx tarixi', lowest: 'Eng past', atLowest: 'Tarixiy minimum',
      alertTitle: 'Narx tushganda email yuborilsin',
      alertTarget: 'Maqsadli narx (UZS)',
      alertEmail: 'Sizning email',
      alertSubscribe: 'Obuna bo‘lish',
      alertOk: 'Tayyor! Narx tushsa email yuboriladi.',
      alertErr: 'Xato:',
      alertInvalid: 'Email yoki narx noto‘g‘ri.',
      addCompare: 'Taqqoslashga qo‘shish',
      inCompare: 'Taqqoslashda',
      compareTitle: 'Taqqoslash',
      compareFull: 'Maksimum 4 ta mahsulot tanlash mumkin',
      compareNeed: 'Kamida 2 ta mahsulot tanlang',
      compareEmpty: 'Bo‘sh — taqqoslash uchun mahsulot tanlang',
      compareOpen: 'Taqqoslash', compareClear: 'Tozalash',
      remove: 'O‘chirish',
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
      navHome: 'Главная', admin: 'Админ', more: 'предлож.',
      priceHistory: 'История цен', lowest: 'Минимум', atLowest: 'Исторический минимум',
      alertTitle: 'Уведомить, когда цена упадёт',
      alertTarget: 'Целевая цена (UZS)',
      alertEmail: 'Ваш email',
      alertSubscribe: 'Подписаться',
      alertOk: 'Готово! Письмо придёт, когда цена упадёт.',
      alertErr: 'Ошибка:',
      alertInvalid: 'Неверный email или цена.',
      addCompare: 'В сравнение',
      inCompare: 'В сравнении',
      compareTitle: 'Сравнение',
      compareFull: 'Максимум 4 товара',
      compareNeed: 'Выберите минимум 2 товара',
      compareEmpty: 'Пусто',
      compareOpen: 'Сравнить', compareClear: 'Очистить',
      remove: 'Удалить',
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
      navHome: 'Home', admin: 'Admin', more: 'offers',
      priceHistory: 'Price history', lowest: 'Lowest', atLowest: 'All-time low',
      alertTitle: 'Notify me when the price drops',
      alertTarget: 'Target price (UZS)',
      alertEmail: 'Your email',
      alertSubscribe: 'Subscribe',
      alertOk: 'Done! We will email you when price drops.',
      alertErr: 'Error:',
      alertInvalid: 'Invalid email or price.',
      addCompare: 'Add to compare',
      inCompare: 'In compare',
      compareTitle: 'Compare',
      compareFull: 'Maximum 4 items',
      compareNeed: 'Pick at least 2 items',
      compareEmpty: 'Empty',
      compareOpen: 'Compare', compareClear: 'Clear',
      remove: 'Remove',
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

  // Only allow http(s):// (and protocol-relative //) URLs. Anything else
  // (javascript:, data:, vbscript:, etc.) becomes '#'. Prevents XSS via
  // admin-configured dynamic source URLs.
  function safeUrl(u) {
    if (u == null) return '#';
    const s = String(u).trim();
    if (s === '') return '#';
    if (/^https?:\/\//i.test(s)) return s;
    if (s.startsWith('//')) return 'https:' + s;
    if (s.startsWith('/') || s.startsWith('./') || s.startsWith('../')) return s;
    return '#';
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

  // ---------- Compare list (localStorage) ----------
  const COMPARE_KEY = 'mc.compare';
  const COMPARE_MAX = 4;

  function compareList() {
    try {
      const raw = JSON.parse(localStorage.getItem(COMPARE_KEY) || '[]');
      return new Set((Array.isArray(raw) ? raw : []).map(Number));
    } catch (_) {
      return new Set();
    }
  }

  function persistCompare(set) {
    localStorage.setItem(COMPARE_KEY, JSON.stringify([...set]));
    updateCompareBar();
  }

  function toggleCompare(id) {
    const set = compareList();
    id = Number(id);
    if (set.has(id)) {
      set.delete(id);
    } else if (set.size < COMPARE_MAX) {
      set.add(id);
    } else {
      toast(t('compareFull') || 'Limit reached');
      return;
    }
    persistCompare(set);
  }

  function updateCompareBar() {
    const bar = $('#compareBar');
    if (!bar) return;
    const set = compareList();
    if (set.size === 0) {
      bar.classList.remove('show');
      return;
    }
    bar.classList.add('show');
    bar.querySelector('.compare-count').textContent = String(set.size);
  }

  async function openCompare() {
    const set = compareList();
    if (set.size < 2) {
      toast(t('compareNeed') || 'Need at least 2 items');
      return;
    }
    showView('compare');
    const root = $('#compareDetail');
    root.innerHTML = '<div class="loading">⏳</div>';
    const data = await api('compare', { params: { ids: [...set].join(',') } });
    const list = (data.ok ? data.products || [] : []).map(normalize).filter(Boolean);
    if (!list.length) {
      root.innerHTML = `<div class="empty">${t('compareEmpty') || 'No items'}</div>`;
      return;
    }
    const cheapest = Math.min(...list.map((p) => p.price));
    root.innerHTML = `
      <div class="compare-grid">
        ${list.map((p) => `
          <div class="compare-cell ${p.price === cheapest ? 'is-cheapest' : ''}">
            <div class="compare-cell__img">${p.image ? `<img src="${escapeHtml(p.image)}" alt="">` : ''}</div>
            <div class="compare-cell__title">${escapeHtml(p.title)}</div>
            <div class="compare-cell__price">${fmtPrice(p.price, p.currency)}${p.price === cheapest ? ` <span class="badge-cheapest">⬇</span>` : ''}</div>
            <div class="compare-cell__src">${escapeHtml(p.source_name || p.source)}</div>
            ${p.rating ? `<div class="compare-cell__rating">⭐ ${p.rating.toFixed(1)} (${p.reviews || 0})</div>` : ''}
            <div class="compare-cell__actions">
              <a class="btn-primary" href="${escapeHtml(safeUrl(p.url))}" target="_blank" rel="noopener">${t('open')}</a>
              <button data-remove="${p.id}" class="link-danger">${t('remove') || 'Remove'}</button>
            </div>
          </div>
        `).join('')}
      </div>
    `;
    root.querySelectorAll('[data-remove]').forEach((b) => {
      b.onclick = () => {
        toggleCompare(Number(b.dataset.remove));
        openCompare();
      };
    });
  }

  function clearCompare() {
    persistCompare(new Set());
    if ($('#view-compare')?.classList.contains('active')) showView('home');
  }

  // ---------- API ----------
  // In-flight request token so we can ignore stale responses (typing fast).
  let _apiSeq = 0;

  function sleep(ms) { return new Promise((r) => setTimeout(r, ms)); }

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

    // Retry transient failures (network down, 5xx, 429) with exp backoff +
    // jitter. Keep total wait bounded so a flaky upstream doesn't hang the UI.
    const maxAttempts = (opts.retries == null ? 2 : opts.retries) + 1;
    let lastErr;
    for (let attempt = 0; attempt < maxAttempts; attempt++) {
      try {
        const res = await fetch(url.toString(), {
          method: opts.method || 'GET',
          headers: headers,
          body: opts.body ? JSON.stringify(opts.body) : undefined,
          signal: opts.signal,
        });
        if (res.status === 429 || res.status >= 500) {
          lastErr = { ok: false, status: res.status, error: 'transient' };
          if (attempt === maxAttempts - 1) return lastErr;
          await sleep(Math.min(2500, 300 * Math.pow(2, attempt)) * Math.random());
          continue;
        }
        let json;
        try { json = await res.json(); }
        catch (e) { return { ok: false, status: res.status, error: 'invalid json' }; }
        return json;
      } catch (err) {
        if (err && err.name === 'AbortError') {
          return { ok: false, aborted: true };
        }
        lastErr = { ok: false, error: String(err && err.message || err) };
        if (attempt === maxAttempts - 1) return lastErr;
        await sleep(Math.min(2500, 300 * Math.pow(2, attempt)) * Math.random());
      }
    }
    return lastErr || { ok: false, error: 'unknown' };
  }

  // Render N skeleton cards into a container while waiting on the API.
  function showSkeletons(container, count) {
    if (!container) return;
    container.classList.add('skeleton-grid');
    container.innerHTML = '';
    for (let i = 0; i < count; i++) {
      const c = document.createElement('div');
      c.className = 'skeleton-card';
      c.innerHTML = `
        <div class="sk-img"></div>
        <div class="sk-body">
          <div class="sk-line"></div>
          <div class="sk-line short"></div>
          <div class="sk-line price"></div>
        </div>`;
      container.appendChild(c);
    }
  }

  function clearSkeletons(container) {
    if (container) container.classList.remove('skeleton-grid');
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
    const price = Number(p.price_uzs != null ? p.price_uzs : p.price || 0);
    return {
      id: Number(p.id),
      source: p.source || '',
      source_name: srcLabel(p.source || ''),
      title: p.title || '',
      price: price,
      old_price: p.old_price ? Number(p.old_price_uzs != null ? p.old_price_uzs : p.old_price) : null,
      currency: 'UZS',
      // Tracks whether the price displayed is a converted one (RUB→UZS etc.)
      // — used to render a small "FX" badge next to the number.
      fxApplied: !!p.fx_applied,
      priceOriginal: p.price_original != null ? Number(p.price_original) : price,
      currencyOriginal: p.currency_original || p.currency || 'UZS',
      url: p.external_url || p.url || '#',
      image: img,
      rating: p.rating ? Number(p.rating) : 0,
      reviews: Number(p.reviews_count || p.reviews || 0),
      sold: Number(p.sold_count || 0),
      seller: p.seller || '',
      synced: p.synced_at_human || '',
      updated_at: p.updated_at || '',
      offers: Array.isArray(p.offers) ? p.offers : [],
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
    const fxBadge = p.fxApplied
      ? `<span class="price-fx-note" title="${escapeHtml(p.currencyOriginal + ' → UZS')}">FX</span>`
      : '';
    const ratingTxt = p.rating ? '⭐ ' + p.rating.toFixed(1) : '';
    const soldTxt = p.sold ? '🛒 ' + p.sold : '';
    const moreTxt = p.offers && p.offers.length ? `+${p.offers.length} ${t('more') || ''}`.trim() : '';
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
        <div class="price">${fmtPrice(p.price, p.currency)}${fxBadge}${oldP}</div>
        <div class="meta">
          <span>${ratingTxt}</span>
          <span>${soldTxt || moreTxt}</span>
        </div>
      </div>`;
    card.onclick = () => openProduct(p);
    return card;
  }

  function renderResults() {
    let list = state.products.slice();
    if (state.sourceFilter) list = list.filter((p) => p.source === state.sourceFilter);
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
      case 'rating':     list.sort((a, b) => (b.rating || 0) - (a.rating || 0) || b.reviews - a.reviews); break;
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

  // Abort any in-flight search so we don't race responses when typing fast.
  let _searchAbort = null;

  async function performSearch(query, source) {
    const q = (query || '').trim();
    state.lastQuery = q;
    state.lastSource = source || '';
    showView('results');
    $('#resultsTitle').textContent = q ? `🔍 "${q}"` : t('topProducts');
    $('#resultsLoading').classList.add('hidden');
    $('#resultsEmpty').classList.add('hidden');

    if (_searchAbort) _searchAbort.abort();
    _searchAbort = new AbortController();
    const seq = ++_apiSeq;

    const grid = $('#resultsGrid');
    showSkeletons(grid, 8);

    const data = await api('products', {
      params: { q: q, source: source || '', sort: state.sortMode, limit: 40 },
      signal: _searchAbort.signal,
    });

    if (seq !== _apiSeq || data.aborted) return; // a newer search superseded us
    clearSkeletons(grid);
    grid.innerHTML = '';

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
    $('#marketLoading').classList.add('hidden');
    $('#marketEmpty').classList.add('hidden');
    const grid = $('#marketGridResults');
    showSkeletons(grid, 8);
    const data = await api('products', {
      params: { source: source || '', q: query || '', limit: 40 },
    });
    clearSkeletons(grid);
    grid.innerHTML = '';
    const list = (data.ok ? (data.products || []) : []).map(normalize).filter(Boolean);
    if (!list.length) {
      $('#marketEmpty').classList.remove('hidden');
      return;
    }
    list.forEach((p) => grid.appendChild(productCard(p)));
  }

  function sparkline(points, width, height) {
    if (!points || points.length < 2) return '';
    const w = width || 300;
    const h = height || 60;
    const min = Math.min(...points);
    const max = Math.max(...points);
    const range = max - min || 1;
    const step = w / (points.length - 1);
    const xy = points.map((v, i) => {
      const x = (i * step).toFixed(1);
      const y = (h - ((v - min) / range) * (h - 4) - 2).toFixed(1);
      return `${x},${y}`;
    });
    const path = `M ${xy.join(' L ')}`;
    const area = `M 0,${h} L ${xy.join(' L ')} L ${w},${h} Z`;
    return `
      <svg viewBox="0 0 ${w} ${h}" preserveAspectRatio="none" width="100%" height="${h}" aria-hidden="true">
        <defs>
          <linearGradient id="sparkfill" x1="0" x2="0" y1="0" y2="1">
            <stop offset="0%"  stop-color="currentColor" stop-opacity="0.25"/>
            <stop offset="100%" stop-color="currentColor" stop-opacity="0"/>
          </linearGradient>
        </defs>
        <path d="${area}" fill="url(#sparkfill)" stroke="none"/>
        <path d="${path}" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round"/>
      </svg>`;
  }

  async function renderHistory(productId, mountEl, currentPrice) {
    mountEl.innerHTML = `<div class="loading">⏳</div>`;
    const data = await api('history', { params: { id: productId, limit: 60 } });
    if (!data.ok || !data.history || data.history.length < 2) {
      mountEl.innerHTML = ''; // not enough points yet — hide section
      return;
    }
    const series = data.history.map((r) => Number(r.price_uzs != null ? r.price_uzs : r.price));
    const minUzs = Number(data.min_uzs || 0);
    const atMin = currentPrice > 0 && minUzs > 0 && Math.abs(currentPrice - minUzs) < 1;
    mountEl.innerHTML = `
      <h3 class="section-title">${t('priceHistory')}</h3>
      <div class="sparkline-wrap${atMin ? ' is-min' : ''}">
        ${sparkline(series, 600, 80)}
        <div class="sparkline-meta">
          <span>${t('lowest')}: <strong>${fmtPrice(minUzs, 'UZS')}</strong></span>
          ${atMin ? `<span class="badge-min">⬇ ${t('atLowest')}</span>` : ''}
        </div>
      </div>
    `;
  }

  function alertFormHtml(productId, currentPrice) {
    const suggested = Math.round(currentPrice * 0.9);
    return `
      <details class="alert-box">
        <summary>🔔 ${t('alertTitle')}</summary>
        <form class="alert-form" data-product-id="${productId}">
          <label class="alert-row">
            <span>${t('alertTarget')}</span>
            <input type="number" min="1" step="1" name="target_price" value="${suggested}" required>
          </label>
          <label class="alert-row">
            <span>${t('alertEmail')}</span>
            <input type="email" name="email" placeholder="email@example.com" required>
          </label>
          <button type="submit" class="btn-primary">${t('alertSubscribe')}</button>
          <div class="alert-msg" aria-live="polite"></div>
        </form>
      </details>`;
  }

  function bindAlertForm(formEl) {
    if (!formEl) return;
    formEl.addEventListener('submit', async (e) => {
      e.preventDefault();
      const pid = Number(formEl.dataset.productId);
      const target = Number(formEl.target_price.value);
      const email = String(formEl.email.value || '').trim();
      const msg = formEl.querySelector('.alert-msg');
      if (!email || !target || !pid) {
        msg.textContent = t('alertInvalid');
        msg.className = 'alert-msg is-error';
        return;
      }
      msg.textContent = '…';
      msg.className = 'alert-msg';
      try {
        const r = await fetch('../api/index.php?action=alert_create', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ product_id: pid, email, target_price: target, currency: 'UZS' }),
        });
        const j = await r.json();
        if (j.ok) {
          msg.textContent = t('alertOk');
          msg.className = 'alert-msg is-ok';
          formEl.reset();
        } else {
          msg.textContent = t('alertErr') + ' ' + (j.error || '');
          msg.className = 'alert-msg is-error';
        }
      } catch (err) {
        msg.textContent = t('alertErr');
        msg.className = 'alert-msg is-error';
      }
    });
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
      <a class="compare-row" href="${escapeHtml(safeUrl(o.url))}" target="_blank" rel="noopener">
        <div>
          <div class="src">${escapeHtml(o.source_name || o.source)}</div>
          <div>${escapeHtml((o.title || '').substring(0, 50))}</div>
        </div>
        <div class="p">${fmtPrice(o.price, o.currency)}</div>
      </a>`).join('');

    const syncedHtml = fresh.synced
      ? `<div class="detail-synced">🟢 ${t('synced')}: ${escapeHtml(fresh.synced)}</div>`
      : '';
    const inCompare = compareList().has(fresh.id);

    root.innerHTML = `
      <div class="detail-img">${fresh.image ? `<img src="${escapeHtml(fresh.image)}" alt="">` : ''}</div>
      <div class="detail-title">${escapeHtml(fresh.title)}</div>
      <div><span class="detail-price">${fmtPrice(fresh.price, fresh.currency)}</span>${oldP}</div>
      ${syncedHtml}
      <div class="detail-row"><span class="label">${t('source')}</span><span class="value">${escapeHtml(fresh.source_name || fresh.source)}</span></div>
      ${fresh.rating ? `<div class="detail-row"><span class="label">${t('rating')}</span><span class="value">⭐ ${fresh.rating.toFixed(1)} (${fresh.reviews})</span></div>` : ''}
      ${fresh.seller ? `<div class="detail-row"><span class="label">${t('seller')}</span><span class="value">${escapeHtml(fresh.seller)}</span></div>` : ''}
      <div class="detail-actions">
        <a class="detail-buy" href="${escapeHtml(safeUrl(fresh.url))}" target="_blank" rel="noopener">${t('open')}</a>
        <button class="btn-compare" id="toggleCompareBtn">${inCompare ? '✓ ' + t('inCompare') : '⚖ ' + t('addCompare')}</button>
      </div>
      <div id="priceHistoryMount"></div>
      ${alertFormHtml(fresh.id, fresh.price)}
      ${others.length ? `<h3 class="section-title">${t('similar')}</h3><div class="compare-list">${compare}</div>` : ''}
    `;
    renderHistory(fresh.id, $('#priceHistoryMount'), fresh.price);
    bindAlertForm(root.querySelector('.alert-form'));
    const btn = $('#toggleCompareBtn');
    if (btn) btn.onclick = () => {
      toggleCompare(fresh.id);
      btn.textContent = compareList().has(fresh.id)
        ? '✓ ' + t('inCompare')
        : '⚖ ' + t('addCompare');
    };
  }

  async function loadHomeProducts() {
    const grid = $('#homeGrid');
    if (!grid) return;
    $('#resultsLoadingHome').classList.add('hidden');
    showSkeletons(grid, 6);
    const data = await api('products', { params: { limit: 12, sort: 'popular' } });
    clearSkeletons(grid);
    grid.innerHTML = '';
    const list = (data.ok ? (data.products || []) : []).map(normalize).filter(Boolean);
    list.forEach((p) => grid.appendChild(productCard(p)));
    state.products = list;
  }

  function debounce(fn, delay) {
    let t;
    return function (...args) {
      clearTimeout(t);
      t = setTimeout(() => fn.apply(this, args), delay);
    };
  }

  // ---------- Events ----------
  function bindEvents() {
    $('#searchBtn').onclick = () => performSearch($('#searchInput').value, '');
    const debouncedSearch = debounce((v) => {
      if (v.trim().length >= 2) performSearch(v, '');
    }, 350);
    $('#searchInput').addEventListener('input', (e) => debouncedSearch(e.target.value));
    $('#searchInput').addEventListener('keydown', (e) => {
      // Enter triggers an immediate search (skipping the debounce delay).
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

    const mi = $('#marketSearchInput');
    if (mi) {
      const debouncedMarket = debounce((src, v) => {
        if (v.trim().length >= 2 || v.trim().length === 0) runMarketSearch(src, v);
      }, 350);
      mi.addEventListener('input', (e) => {
        debouncedMarket(e.target.dataset.source, e.target.value);
      });
      mi.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          const src = e.target.dataset.source;
          runMarketSearch(src, e.target.value);
        }
      });
    }

    const openBtn = $('#compareOpenBtn');
    if (openBtn) openBtn.onclick = openCompare;
    const clearBtn = $('#compareClearBtn');
    if (clearBtn) clearBtn.onclick = clearCompare;
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
        document.documentElement.setAttribute('lang', lang);
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
    document.documentElement.setAttribute('lang', lang);
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
    updateCompareBar();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
