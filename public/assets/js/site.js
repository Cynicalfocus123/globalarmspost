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
    if (bar && bar.classList.contains('open') && !ev.target.closest('.menu-bar')) setMenu(false);
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
