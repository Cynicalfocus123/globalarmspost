// Header menu, submenus, search box, dark mode and back-to-top. No libraries.
(function () {
  'use strict';
  var bar = document.querySelector('.menu-bar');
  var menuBtn = document.querySelector('.menu-toggle');
  var search = document.getElementById('site-search');
  var searchBtn = document.querySelector('.search-toggle');
  var themeBtn = document.querySelector('.theme-toggle');
  var toTop = document.querySelector('.to-top');
  var root = document.documentElement;

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
  bindTabs(document);

  // git/ copy only: '#' links (/#asia, /#regions/asia, /#news/slug) load that page into this one.
  // The live/ copy uses proper addresses and never runs this.
  if (root.getAttribute('data-links') === 'hash') {
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
          bindTabs(main);
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
})();
