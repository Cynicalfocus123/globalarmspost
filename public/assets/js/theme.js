// Runs in <head> before the page paints, so dark mode never flashes white.
(function () {
  try {
    if (localStorage.getItem('gap-theme') === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
  } catch (e) { /* storage blocked: light mode */ }
})();
