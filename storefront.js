/* Presentation-only mobile navigation enhancement; native disclosure works without JS. */
(() => {
  const menu = document.querySelector('.mobile-menu');
  if (!menu) return;
  const summary = menu.querySelector('summary');
  menu.querySelectorAll('a').forEach(link => link.addEventListener('click', () => { menu.open = false; }));
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && menu.open) { menu.open = false; summary.focus(); }
  });
  document.addEventListener('click', event => {
    if (menu.open && !menu.contains(event.target)) menu.open = false;
  });
  matchMedia('(min-width: 769px)').addEventListener('change', event => {
    if (event.matches) menu.open = false;
  });
})();
