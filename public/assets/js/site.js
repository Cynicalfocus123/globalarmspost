// Header menu, submenus, search box, dark mode, back-to-top, and the reader side: Aa text size, share,
// the photo gallery (arrows, counter, full screen), story rows and search suggestions. No libraries.
(function () {
  'use strict';
  var bar = document.querySelector('.menu-bar');
  var menuBtn = document.querySelector('.menu-toggle');
  var search = document.getElementById('site-search');
  var searchBtn = document.querySelector('.search-toggle');
  var themeBtn = document.querySelector('.theme-toggle');
  var toTop = document.querySelector('.to-top');
  var root = document.documentElement;
  var hashLinks = root.getAttribute('data-links') === 'hash';

  function closeSubs(except) {
    document.querySelectorAll('.has-sub.open').forEach(function (li) {
      if (except && li.contains(except)) return;
      li.classList.remove('open');
      li.querySelector('.sub-toggle').setAttribute('aria-expanded', 'false');
    });
  }
  function setMenu(open) {
    if (!bar || !menuBtn) return;
    bar.classList.toggle('open', open);
    menuBtn.setAttribute('aria-expanded', String(open));
    menuBtn.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    if (!open) closeSubs();
  }

  if (menuBtn) menuBtn.addEventListener('click', function () { setMenu(!bar.classList.contains('open')); });

  document.querySelectorAll('.sub-toggle').forEach(function (btn) {
    btn.addEventListener('click', function (ev) {
      ev.stopPropagation();
      var li = btn.parentElement;
      var open = !li.classList.contains('open');
      closeSubs(li);
      li.classList.toggle('open', open);
      btn.setAttribute('aria-expanded', String(open));
    });
  });

  document.addEventListener('click', function (ev) {
    if (!ev.target.closest('.has-sub')) closeSubs();
    if (search && !search.hidden && !ev.target.closest('#site-search, .search-toggle')) { search.hidden = true; searchBtn.setAttribute('aria-expanded', 'false'); }
    if (bar && bar.classList.contains('open') && !ev.target.closest('.menu-bar, .menu-toggle')) setMenu(false);
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Escape') return;
    closeSubs();
    setMenu(false);
    if (search && !search.hidden) { search.hidden = true; searchBtn.setAttribute('aria-expanded', 'false'); searchBtn.focus(); }
  });

  if (searchBtn && search) searchBtn.addEventListener('click', function () {
    search.hidden = !search.hidden;
    searchBtn.setAttribute('aria-expanded', String(!search.hidden));
    if (!search.hidden) search.querySelector('input').focus();
  });

  if (themeBtn) {
    var dark = root.getAttribute('data-theme') === 'dark';
    themeBtn.setAttribute('aria-pressed', String(dark));
    themeBtn.addEventListener('click', function () {
      dark = root.getAttribute('data-theme') !== 'dark';
      if (dark) root.setAttribute('data-theme', 'dark'); else root.removeAttribute('data-theme');
      themeBtn.setAttribute('aria-pressed', String(dark));
      try { localStorage.setItem('gap-theme', dark ? 'dark' : 'light'); } catch (e) { /* storage blocked */ }
    });
  }

  // Tabs (e.g. Top stories / Latest).
  function bindTabs(scope) {
    scope.querySelectorAll('[role="tablist"]').forEach(function (list) {
      var tabs = list.querySelectorAll('[role="tab"]');
      tabs.forEach(function (tab, i) {
        tab.addEventListener('click', function () { select(i); });
        tab.addEventListener('keydown', function (ev) {
          if (ev.key === 'ArrowRight' || ev.key === 'ArrowLeft') {
            var n = (i + (ev.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length;
            select(n); tabs[n].focus();
          }
        });
      });
      function select(n) {
        tabs.forEach(function (t, k) {
          t.setAttribute('aria-selected', String(k === n));
          t.tabIndex = k === n ? 0 : -1;
          var panel = document.getElementById(t.getAttribute('aria-controls'));
          if (panel) panel.hidden = k !== n;
        });
      }
    });
  }

  /* ---------- Aa: three text sizes, remembered in this browser ---------- */
  var SIZES = ['s', 'm', 'l'];
  function applySize(size) {
    var article = document.querySelector('[data-article]');
    if (!article) return;
    SIZES.forEach(function (s) { article.classList.remove('size-' + s); });
    article.classList.add('size-' + (SIZES.indexOf(size) >= 0 ? size : 'm'));
    document.querySelectorAll('.aa-menu [data-size]').forEach(function (b) {
      b.setAttribute('aria-pressed', String(b.getAttribute('data-size') === size));
    });
  }
  function initAa(scope) {
    var btn = scope.querySelector('.aa-btn');
    var menu = scope.querySelector('.aa-menu');
    if (!btn || !menu) return;
    var saved = 'm';
    try { saved = localStorage.getItem('gap-article-size') || 'm'; } catch (e) { /* storage blocked */ }
    applySize(saved);
    btn.addEventListener('click', function () {
      var open = menu.hidden;
      menu.hidden = !open;
      btn.setAttribute('aria-expanded', String(open));
      if (open) { var first = menu.querySelector('[data-size]'); if (first) first.focus(); }
    });
    menu.querySelectorAll('[data-size]').forEach(function (b) {
      b.addEventListener('click', function () {
        var size = b.getAttribute('data-size');
        applySize(size);
        try { localStorage.setItem('gap-article-size', size); } catch (e) { /* storage blocked */ }
        menu.hidden = true;
        btn.setAttribute('aria-expanded', 'false');
        btn.focus();
      });
    });
  }

  /* ---------- Share: the phone's share sheet, or a menu with plain links ---------- */
  function initShare(scope) {
    var btn = scope.querySelector('.share-btn');
    var menu = scope.querySelector('.share-menu');
    if (!btn) return;
    btn.addEventListener('click', function () {
      var title = btn.getAttribute('data-title') || document.title;
      var url = btn.getAttribute('data-url') || location.href;
      var coarse = window.matchMedia('(pointer: coarse)').matches;
      if (navigator.share && coarse) {
        navigator.share({ title: title, url: url }).catch(function () { if (menu) { menu.hidden = false; } });
        return;
      }
      if (menu) {
        var open = menu.hidden;
        menu.hidden = !open;
        btn.setAttribute('aria-expanded', String(open));
        if (open) { var first = menu.querySelector('a, button'); if (first) first.focus(); }
      }
    });
    if (menu) {
      document.addEventListener('click', function (ev) {
        if (menu.hidden) return;
        if (ev.target.closest('.share-menu, .share-btn')) return;
        menu.hidden = true;
        btn.setAttribute('aria-expanded', 'false');
      });
      document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape' && !menu.hidden) { menu.hidden = true; btn.setAttribute('aria-expanded', 'false'); btn.focus(); }
      });
    }
  }
  function initCopy(scope) {
    scope.querySelectorAll('.copy-link').forEach(function (btn) {
      if (btn.dataset.bound === '1') return;
      btn.dataset.bound = '1';
      btn.addEventListener('click', function () {
        var url = btn.getAttribute('data-url') || location.href;
        var done = function () {
          var was = btn.textContent;
          btn.textContent = 'Copied';
          btn.classList.add('done');
          setTimeout(function () { btn.textContent = was; btn.classList.remove('done'); }, 1600);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(url).then(done, function () { fallbackCopy(url); done(); });
        } else {
          fallbackCopy(url);
          done();
        }
      });
    });
  }
  function fallbackCopy(text) {
    var area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.appendChild(area);
    area.select();
    try { document.execCommand('copy'); } catch (e) { /* nothing we can do */ }
    document.body.removeChild(area);
  }

  /* ---------- Photo gallery: arrows, counter, swipe, full screen ---------- */
  function bindTrack(track, counter, prev, next) {
    var slides = track.querySelectorAll('.gal-slide');
    var total = slides.length;
    function current() {
      var width = track.clientWidth || 1;
      return Math.max(0, Math.min(total - 1, Math.round(track.scrollLeft / width)));
    }
    function update() {
      var i = current();
      if (counter) counter.textContent = (i + 1) + '/' + total;
      if (prev) prev.disabled = i <= 0;
      if (next) next.disabled = i >= total - 1;
    }
    function go(i) {
      var at = Math.max(0, Math.min(total - 1, i));
      track.scrollTo({ left: at * (track.clientWidth || 1), behavior: 'smooth' });
      update();
    }
    if (prev) prev.addEventListener('click', function () { go(current() - 1); });
    if (next) next.addEventListener('click', function () { go(current() + 1); });
    track.addEventListener('scroll', function () { window.requestAnimationFrame(update); }, { passive: true });
    track.addEventListener('keydown', function (ev) {
      if (ev.key === 'ArrowLeft') { ev.preventDefault(); go(current() - 1); }
      if (ev.key === 'ArrowRight') { ev.preventDefault(); go(current() + 1); }
    });
    window.addEventListener('resize', update);
    update();
  }
  function initGallery(scope) {
    scope.querySelectorAll('[data-gal]').forEach(function (gal) {
      if (gal.dataset.bound === '1') return;
      gal.dataset.bound = '1';
      bindTrack(gal.querySelector('.gal-track'), gal.querySelector('.gal-count'), gal.querySelector('.gal-prev'), gal.querySelector('.gal-next'));
      var expand = gal.querySelector('.gal-expand');
      if (!expand) return;
      expand.addEventListener('click', function () {
        if (typeof document.createElement('dialog').showModal !== 'function') return;
        var dialog = document.createElement('dialog');
        dialog.className = 'gal-dialog';
        dialog.setAttribute('aria-label', 'Photos');
        var wrap = document.createElement('div');
        wrap.className = 'gal-dialog-inner';
        wrap.innerHTML = gal.querySelector('.gal-track').innerHTML;
        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'gal-dialog-close';
        close.setAttribute('aria-label', 'Close');
        close.textContent = '×';
        var prev = document.createElement('button');
        prev.type = 'button';
        prev.className = 'gal-prev';
        prev.setAttribute('aria-label', 'Previous photo');
        prev.textContent = '‹';
        var next = document.createElement('button');
        next.type = 'button';
        next.className = 'gal-next';
        next.setAttribute('aria-label', 'Next photo');
        next.textContent = '›';
        var counter = document.createElement('span');
        counter.className = 'gal-count';
        wrap.appendChild(close);
        wrap.appendChild(prev);
        wrap.appendChild(next);
        wrap.appendChild(counter);
        dialog.appendChild(wrap);
        document.body.appendChild(dialog);
        var track = wrap.querySelector('.gal-track');
        bindTrack(track, counter, prev, next);
        close.addEventListener('click', function () { dialog.close(); });
        dialog.addEventListener('close', function () { dialog.remove(); });
        dialog.addEventListener('click', function (ev) { if (ev.target === dialog) dialog.close(); });
        dialog.showModal();
        if (track) track.focus();
      });
    });
  }

  /* ---------- Story rows with ‹ › arrows (swipe works by itself on phones) ---------- */
  function initRows(scope) {
    scope.querySelectorAll('.row-wrap').forEach(function (wrap) {
      if (wrap.dataset.bound === '1') return;
      wrap.dataset.bound = '1';
      var list = wrap.querySelector('.row-list');
      var prev = wrap.querySelector('.row-prev');
      var next = wrap.querySelector('.row-next');
      if (!list) return;
      var step = function () {
        var item = list.querySelector('.row-item');
        return item ? item.getBoundingClientRect().width + 16 : 300;
      };
      if (prev) prev.addEventListener('click', function () { list.scrollBy({ left: -step(), behavior: 'smooth' }); });
      if (next) next.addEventListener('click', function () { list.scrollBy({ left: step(), behavior: 'smooth' }); });
      var sync = function () {
        var max = list.scrollWidth - list.clientWidth;
        if (prev) prev.disabled = list.scrollLeft <= 2;
        if (next) next.disabled = list.scrollLeft >= max - 2;
      };
      list.addEventListener('scroll', function () { window.requestAnimationFrame(sync); }, { passive: true });
      window.addEventListener('resize', sync);
      sync();
    });
  }

  /* ---------- Suggestions while typing (small JSON endpoint, no third-party code) ---------- */
  function initSuggest(input) {
    if (input.dataset.bound === '1') return;
    input.dataset.bound = '1';
    var box = input.closest('form') ? input.closest('form').querySelector('[data-suggest-box]') : null;
    if (!box) return;
    var timer = 0;
    var active = -1;
    var items = [];

    function close() {
      box.hidden = true;
      box.innerHTML = '';
      items = [];
      active = -1;
      input.setAttribute('aria-expanded', 'false');
    }
    function pick(i) {
      var item = items[i];
      if (!item) return;
      if (item.url) { location.href = item.url; return; }
      input.value = item.term;
      close();
      submitForm(input);
    }
    function render(data) {
      var terms = data.terms || [];
      var stories = data.stories || [];
      if (!terms.length && !stories.length) { close(); return; }
      items = [];
      var html = '';
      if (terms.length) {
        html += '<div class="sg-head">Words</div><ul role="listbox" aria-label="Suggested words">';
        terms.forEach(function (term) {
          items.push({ term: term });
          html += '<li role="option" id="sg-' + (items.length - 1) + '"><button type="button" data-i="' + (items.length - 1) + '">' + escText(term) + '</button></li>';
        });
        html += '</ul>';
      }
      if (stories.length) {
        html += '<div class="sg-head">Stories</div><ul role="listbox" aria-label="Suggested stories">';
        stories.forEach(function (story) {
          items.push({ url: story.url });
          html += '<li role="option" id="sg-' + (items.length - 1) + '"><a href="' + escAttr(story.url) + '">' + escText(story.title) + '</a></li>';
        });
        html += '</ul>';
      }
      box.innerHTML = html;
      box.hidden = false;
      input.setAttribute('aria-expanded', 'true');
      box.querySelectorAll('button[data-i]').forEach(function (b) {
        b.addEventListener('mousedown', function (ev) { ev.preventDefault(); });
        b.addEventListener('click', function () { pick(Number(b.getAttribute('data-i'))); });
      });
      box.querySelectorAll('a').forEach(function (a) {
        a.addEventListener('mouseenter', function () { a.focus(); });
      });
      active = -1;
    }
    function highlight(n) {
      var options = box.querySelectorAll('[role="option"]');
      if (!options.length) return;
      active = (n + options.length) % options.length;
      options.forEach(function (o, i) { o.classList.toggle('on', i === active); });
      input.setAttribute('aria-activedescendant', options[active].id);
    }
    function fetchNow() {
      var q = input.value.trim();
      if (q.length < 2) { close(); return; }
      fetch('/api/suggest?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) { if (data) render(data); else close(); })
        .catch(function () { close(); });
    }
    input.addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(fetchNow, 180);
    });
    input.addEventListener('keydown', function (ev) {
      if (box.hidden) return;
      if (ev.key === 'ArrowDown') { ev.preventDefault(); highlight(active + 1); }
      else if (ev.key === 'ArrowUp') { ev.preventDefault(); highlight(active - 1); }
      else if (ev.key === 'Escape') { close(); }
    });
    document.addEventListener('click', function (ev) {
      if (box.hidden) return;
      if (ev.target.closest('[data-suggest-box], [data-suggest]')) return;
      close();
    });
  }
  function escText(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function escAttr(s) { return escText(s); }

  /* ---------- Search forms: a normal GET in live/, a '#' link in the git/ copy ---------- */
  function submitForm(input) {
    var form = input.closest('form');
    if (form) { if (form.requestSubmit) form.requestSubmit(); else form.submit(); }
  }
  function initSearchForms(scope) {
    scope.querySelectorAll('form[data-search-form]').forEach(function (form) {
      if (form.dataset.bound === '1') return;
      form.dataset.bound = '1';
      if (!hashLinks) return; // live/: a plain GET to /search?q=… is right
      form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var data = new FormData(form);
        var params = new URLSearchParams();
        ['q', 'cat', 'from', 'to', 'sort', 'page'].forEach(function (key) {
          var value = data.get(key);
          if (value) params.set(key, String(value));
        });
        var query = params.toString();
        location.hash = '#search' + (query ? '?' + query : '');
      });
    });
  }

  /* ---------- everything that must also run on content loaded by the '#' router ---------- */
  function initContent(scope) {
    bindTabs(scope);
    initAa(scope);
    initShare(scope);
    initCopy(scope);
    initGallery(scope);
    initRows(scope);
    initSearchForms(scope);
    scope.querySelectorAll('[data-suggest]').forEach(initSuggest);
  }

  // git/ copy only: '#' links (/#asia, /#regions/asia, /#news/slug, /#search?q=) load that page into this one.
  // The live/ copy uses proper addresses and never runs this.
  if (hashLinks) {
    var main = document.getElementById('main');
    var current = '';
    var route = function () {
      var h = decodeURIComponent(location.hash.slice(1));
      if (h === 'main') return;
      if (h === 'home') h = '';
      if (h === current) return;
      var q = h.indexOf('?') >= 0 ? h.slice(h.indexOf('?')) : '';
      var parts = h.split('?')[0].split('/').filter(Boolean);
      var url = '/';
      if (parts[0] === 'news' && parts[1]) url = '/news/' + encodeURIComponent(parts[1]);
      else if ((parts[0] === 'tag') && parts[1]) url = '/tag/' + encodeURIComponent(parts[1]);
      else if (parts[0] === 'search') url = '/search';
      else if (parts.length) url = '/' + encodeURIComponent(parts[parts.length - 1]);
      current = h;
      fetch(url + q, { headers: { 'X-Partial': '1' } }).then(function (res) {
        var title = res.headers.get('X-Page-Title');
        return res.text().then(function (html) {
          main.innerHTML = html;
          if (title) document.title = decodeURIComponent(title);
          initContent(main);
          setMenu(false);
          window.scrollTo(0, 0);
        });
      });
    };
    window.addEventListener('hashchange', route);
    if (location.hash && location.hash !== '#home' && location.hash !== '#main') { current = ''; route(); } else { current = ''; }
  }

  if (toTop) {
    var onScroll = function () { toTop.hidden = window.scrollY < 600; };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
    toTop.addEventListener('click', function () {
      var smooth = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      window.scrollTo({ top: 0, behavior: smooth ? 'smooth' : 'auto' });
    });
  }

  initContent(document);
})();
