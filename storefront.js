/* Native disclosure remains usable without JS; animation only enhances presentation. */
(() => {
  const menu = document.querySelector('.mobile-menu');
  if (!menu) return;
  const trigger = menu.querySelector('summary');
  const panel = menu.querySelector('nav');
  const links = [...panel.querySelectorAll('a')];
  const reduced = matchMedia('(prefers-reduced-motion: reduce)');
  const desktop = matchMedia('(min-width: 769px)');
  let state = 'closed', generation = 0, animations = [];
  const cancel = () => { generation++; animations.forEach(a => a.cancel()); animations = []; };
  const reflect = next => {
    state = next; menu.dataset.menuState = next;
    const expanded = next === 'opening' || next === 'open';
    trigger.setAttribute('aria-expanded', String(expanded));
    trigger.setAttribute('aria-label', expanded ? 'Close menu' : 'Open menu');
    menu.dispatchEvent(new Event('navigationstate'));
    panel.inert = !expanded;
  };
  const finish = open => { menu.open = open; reflect(open ? 'open' : 'closed'); };
  const animate = (element, frames, timing) => {
    const animation = element.animate(frames, timing); animations.push(animation); return animation;
  };
  const open = () => {
    if (desktop.matches) return;
    const previous = menu.open ? getComputedStyle(panel) : null;
    const from = previous ? { opacity: previous.opacity, transform: previous.transform } : { opacity: 0, transform: 'translateY(-6px)' };
    cancel(); menu.open = true; reflect('opening');
    if (reduced.matches || !panel.animate) { finish(true); return; }
    const token = generation;
    const main = animate(panel, [
      from,
      { opacity: 1, transform: 'translateY(0)' }
    ], { duration: 220, easing: 'cubic-bezier(.2,.8,.2,1)' });
    links.forEach((link, index) => animate(link, [
      { opacity: 0, transform: 'translateY(6px)' }, { opacity: 1, transform: 'translateY(0)' }
    ], { duration: 160, delay: 20 + index * 15, easing: 'ease-out', fill: 'backwards' }));
    main.finished.then(() => { if (token === generation) finish(true); }, () => {});
  };
  const close = ({ returnFocus = false, immediate = false } = {}) => {
    if (returnFocus) trigger.focus({ preventScroll: true });
    if (state === 'closing' && !immediate) return;
    const previous = menu.open ? getComputedStyle(panel) : null;
    const from = previous ? { opacity: previous.opacity, transform: previous.transform } : { opacity: 1, transform: 'none' };
    cancel(); reflect('closing');
    if (!menu.open || immediate || reduced.matches || !panel.animate) { finish(false); return; }
    const token = generation;
    const main = animate(panel, [
      from,
      { opacity: 0, transform: 'translateY(-4px)' }
    ], { duration: 160, easing: 'cubic-bezier(.4,0,1,1)', fill: 'forwards' });
    main.finished.then(() => { if (token === generation) { finish(false); cancel(); } }, () => {});
  };
  let pressTimer;
  const releasePress = () => {
    clearTimeout(pressTimer);
    pressTimer = setTimeout(() => menu.classList.remove('is-pressed'), 90);
  };
  trigger.addEventListener('pointerdown', event => {
    if (event.button !== 0) return;
    clearTimeout(pressTimer); menu.classList.add('is-pressed');
  }, { passive: true });
  trigger.addEventListener('pointerup', releasePress, { passive: true });
  trigger.addEventListener('pointerleave', releasePress, { passive: true });
  trigger.addEventListener('pointercancel', () => { clearTimeout(pressTimer); menu.classList.remove('is-pressed'); }, { passive: true });
  trigger.addEventListener('blur', () => { clearTimeout(pressTimer); menu.classList.remove('is-pressed'); });
  trigger.addEventListener('click', event => {
    event.preventDefault(); trigger.focus({ preventScroll: true });
    if (state === 'open' || state === 'opening') close(); else open();
  });
  links.forEach(link => link.addEventListener('click', () => close()));
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && menu.open) { event.preventDefault(); close({ returnFocus: true }); }
  });
  document.addEventListener('click', event => {
    if (menu.open && !menu.contains(event.target)) close({ returnFocus: panel.contains(document.activeElement) });
  });
  menu.addEventListener('focusout', event => {
    if (menu.contains(event.relatedTarget)) return;
    // Let the native focus transfer settle before deciding it left the disclosure.
    setTimeout(() => { if (menu.open && !menu.contains(document.activeElement)) close(); }, 0);
  });
  menu.addEventListener('toggle', () => {
    if (!menu.open && state !== 'closed') { cancel(); finish(false); }
    else if (menu.open && state === 'closed') open();
  });
  desktop.addEventListener('change', event => {
    if (!event.matches) return;
    const active = document.activeElement;
    const href = active.closest('a')?.getAttribute('href');
    const target = href ? [...document.querySelectorAll('.desktop-nav a')].find(a => a.getAttribute('href') === href) : null;
    const restore = menu.contains(active);
    close({ immediate: true });
    if (restore) (target || document.querySelector('.brand'))?.focus({ preventScroll: true });
  });
  reduced.addEventListener('change', () => {
    const expanded = state === 'open' || state === 'opening'; cancel(); finish(expanded);
  });
  window.addEventListener('pagehide', () => { clearTimeout(pressTimer); cancel(); finish(false); menu.classList.remove('is-pressed'); });
  reflect('closed');
})();

/* Shared mobile headroom/CTA state. No element geometry reads in the scroll path. */
(() => {
  const header = document.querySelector('.site-header');
  const dock = document.querySelector('.contact-dock');
  const link = dock?.querySelector('.header-contact');
  if (!header || !link) return;
  const menu = header.querySelector('.mobile-menu');
  const gallery = document.querySelector('#product-gallery');
  const footer = document.querySelector('.site-footer');
  const mobile = matchMedia('(max-width: 768px)');
  const reduced = matchMedia('(prefers-reduced-motion: reduce)');
  const home = document.createComment('Desktop contact dock position');
  dock.before(home);
  const originalTabIndex = link.getAttribute('tabindex');
  const lifetime = new AbortController();
  let idleTimer, frame = 0, lastY = Math.max(0, window.scrollY), travel = 0;
  let scrolling = false, down = false, footerVisible = false, keyboard = false, suspended = false;
  let contentObserver, contentBlocked = false;
  const blockedTargets = new Set();
  const accessible = (element, unavailable) => {
    element.inert = unavailable;
    if (unavailable) element.setAttribute('aria-hidden', 'true');
    else element.removeAttribute('aria-hidden');
  };
  const render = () => {
    const active = document.activeElement;
    const keyFocus = header.contains(active) && (keyboard || active.matches(':focus-visible'));
    const navHidden = mobile.matches && !reduced.matches && scrolling && lastY > 56 && !menu.open && !keyFocus;
    header.classList.toggle('is-scroll-hidden', navHidden);
    accessible(header, navHidden);
    const focused = active === link;
    const obstructed = mobile.matches && (menu.open || gallery?.open || ((footerVisible || contentBlocked) && !focused));
    const ctaHidden = mobile.matches && !reduced.matches && scrolling && !focused && !obstructed;
    dock.classList.toggle('is-obstructed', Boolean(obstructed));
    dock.classList.toggle('is-scroll-hidden', Boolean(ctaHidden));
    const unavailable = Boolean(obstructed || ctaHidden);
    accessible(link, unavailable);
    if (unavailable) link.setAttribute('tabindex', '-1');
    else if (originalTabIndex === null) link.removeAttribute('tabindex');
    else link.setAttribute('tabindex', originalTabIndex);
  };
  const reset = () => {
    clearTimeout(idleTimer); cancelAnimationFrame(frame); frame = 0;
    lastY = Math.max(0, window.scrollY); travel = 0; scrolling = down = false; render();
  };
  const positionDock = () => {
    // Fixed CTA must be outside the transforming header. Preserve the same anchor/destination.
    if (mobile.matches) document.body.append(dock);
    else home.after(dock);
    header.classList.toggle('smart-navigation', mobile.matches);
    reset(); observeConflicts();
  };
  function observeConflicts() {
    contentObserver?.disconnect(); blockedTargets.clear(); contentBlocked = false;
    if (!mobile.matches || typeof IntersectionObserver !== 'function') { render(); return; }
    // Measure once on setup/resize, never on scroll; observer handles corner intersections.
    const box = link.getBoundingClientRect(), style = getComputedStyle(dock);
    const right = parseFloat(style.paddingRight), bottom = parseFloat(style.bottom) + parseFloat(style.paddingBottom);
    const inset = value => -Math.max(0, value);
    const margin = `${inset(innerHeight - bottom - box.height - 4)}px ${inset(right - 4)}px ${inset(bottom - 4)}px ${inset(innerWidth - right - box.width - 4)}px`;
    contentObserver = new IntersectionObserver(entries => {
      entries.forEach(entry => entry.isIntersecting ? blockedTargets.add(entry.target) : blockedTargets.delete(entry.target));
      contentBlocked = blockedTargets.size > 0; render();
    }, { rootMargin: margin });
    document.querySelectorAll('main a, main button, main .trust-item, main .unit-price').forEach(element => contentObserver.observe(element));
  }
  window.addEventListener('resize', observeConflicts, { passive: true, signal: lifetime.signal });
  const update = () => {
    frame = 0;
    const y = Math.max(0, window.scrollY), delta = y - lastY;
    if (delta !== 0) {
      travel = Math.sign(delta) === Math.sign(travel) ? travel + delta : delta;
      if (Math.abs(travel) >= 8) { scrolling = true; down = travel > 0; travel = 0; }
    }
    lastY = y; render();
  };
  window.addEventListener('scroll', () => {
    if (!mobile.matches || reduced.matches || suspended) return;
    if (!frame) frame = requestAnimationFrame(update);
    clearTimeout(idleTimer);
    idleTimer = setTimeout(() => {
      if (frame) { cancelAnimationFrame(frame); update(); }
      scrolling = down = false; travel = 0; render();
    }, 300);
  }, { passive: true, signal: lifetime.signal });
  document.addEventListener('keydown', () => { keyboard = true; render(); }, { capture: true, signal: lifetime.signal });
  document.addEventListener('pointerdown', () => { keyboard = false; }, { capture: true, passive: true, signal: lifetime.signal });
  document.addEventListener('focusin', render, { signal: lifetime.signal });
  document.addEventListener('focusout', () => { if (!suspended) requestAnimationFrame(render); }, { signal: lifetime.signal });
  menu.addEventListener('toggle', render, { signal: lifetime.signal });
  menu.addEventListener('navigationstate', render, { signal: lifetime.signal });
  const dialogObserver = new MutationObserver(render);
  if (gallery) dialogObserver.observe(gallery, { attributes: true, attributeFilter: ['open'] });
  const footerObserver = typeof IntersectionObserver === 'function' ? new IntersectionObserver(entries => {
    footerVisible = entries[0].isIntersecting; render();
  }, { rootMargin: '0px 0px 80px 0px' }) : null;
  if (footer) footerObserver?.observe(footer);
  mobile.addEventListener('change', positionDock, { signal: lifetime.signal });
  reduced.addEventListener('change', reset, { signal: lifetime.signal });
  window.addEventListener('pagehide', event => {
    suspended = true; clearTimeout(idleTimer); cancelAnimationFrame(frame); frame = 0;
    if (!event.persisted) { lifetime.abort(); dialogObserver.disconnect(); footerObserver?.disconnect(); contentObserver?.disconnect(); }
  });
  window.addEventListener('pageshow', () => { suspended = false; positionDock(); });
  positionDock();
})();
