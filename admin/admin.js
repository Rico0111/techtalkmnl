/* Progressive enhancement only: no authentication or data persistence in the browser. */
(() => {
  'use strict';
  const nav = document.querySelector('.nav-disclosure');
  if (nav) {
    const desktop = matchMedia('(min-width: 1100px)');
    const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)');
    const summary = nav.querySelector('summary');
    const panel = nav.querySelector('.nav-menu-panel');
    const label = nav.querySelector('[data-menu-label]');
    let desiredOpen = nav.open;
    let animation = null;
    const setNavigation = (open, { animate = true, returnFocus = false } = {}) => {
      const fromHeight = panel.getBoundingClientRect().height;
      animation?.cancel();
      animation = null;
      desiredOpen = open;
      if (!open && (returnFocus || panel.contains(document.activeElement))) summary.focus();
      if (open) nav.open = true;
      panel.inert = !open;
      summary.setAttribute('aria-expanded', String(open));
      label.textContent = open ? 'Close' : 'Menu';
      nav.classList.toggle('is-open', open);
      const finish = () => {
        nav.open = open;
        panel.style.overflow = '';
      };
      if (!animate || desktop.matches || reducedMotion.matches || document.body?.dataset.reducedMotion === 'true' || !panel.animate) {
        finish();
        return;
      }
      panel.style.overflow = 'hidden';
      const current = panel.animate(
        [{ height: `${fromHeight}px`, opacity: open ? 0 : 1 },
         { height: `${open ? panel.scrollHeight : 0}px`, opacity: open ? 1 : 0 }],
        { duration: 180, easing: 'ease-out', fill: 'both' }
      );
      animation = current;
      current.finished.then(() => {
        if (animation !== current) return;
        finish();
        current.cancel();
        animation = null;
      }).catch(() => { /* A new toggle or viewport change cancels the prior animation. */ });
    };
    const syncNavigation = () => setNavigation(desktop.matches, { animate: false });
    syncNavigation();
    desktop.addEventListener('change', syncNavigation);
    reducedMotion.addEventListener('change', () => {
      if (reducedMotion.matches) setNavigation(desiredOpen, { animate: false });
    });
    summary.addEventListener('click', event => {
      if (desktop.matches) return;
      event.preventDefault();
      setNavigation(!desiredOpen);
    });
    nav.addEventListener('keydown', event => {
      if (event.key !== 'Escape' || desktop.matches || !desiredOpen) return;
      event.preventDefault();
      setNavigation(false, { returnFocus: true });
    });
    panel.addEventListener('click', event => {
      if (!desktop.matches && event.target.closest?.('a[href]')) setNavigation(false, { animate: false });
    });
    document.addEventListener('click', event => {
      if (!desktop.matches && desiredOpen && !nav.contains(event.target)) setNavigation(false);
    });
  }
  document.querySelector('[data-error-summary]')?.focus();
  const editor = document.querySelector('[data-product-editor]');
  if (!editor) return;
  const initial = new URLSearchParams(new FormData(editor)).toString();
  let submitting = false;
  const photo = editor.querySelector('#product_photo');
  const dirty = () => initial !== new URLSearchParams(new FormData(editor)).toString() || Boolean(photo?.files.length);
  window.addEventListener('beforeunload', event => {
    if (dirty() && !submitting) { event.preventDefault(); event.returnValue = ''; }
  });
  const publication = editor.querySelector('#publication_status');
  const note = editor.querySelector('#publication-note');
  const save = editor.querySelector('[data-save-label]');
  const updatePublication = () => {
    const published = publication.value === 'PUBLISHED';
    note.textContent = published
      ? 'Saving publishes this product on the public website. Check the price, availability and photographs before saving. Carousell is managed separately.'
      : publication.value === 'ARCHIVED'
        ? 'Saving archives this product and removes it from the public website. It remains available here for editing.'
        : 'Saving keeps this product private. Choose Published when it is ready to appear on the public website.';
    save.textContent = published ? 'Save & publish product' : publication.value === 'ARCHIVED' ? 'Save archived product' : save.dataset.saveLabel;
  };
  publication.addEventListener('change', updatePublication);
  updatePublication();
  editor.addEventListener('invalid', event => {
    const field = event.target;
    field.setAttribute('aria-invalid', 'true');
    const message = document.getElementById(`${field.id}-error`);
    if (message) { message.textContent = field.validationMessage; message.hidden = false; }
  }, true);
  editor.addEventListener('input', event => {
    const field = event.target;
    if (field.validity?.valid) {
      field.removeAttribute('aria-invalid');
      const message = document.getElementById(`${field.id}-error`);
      if (message) message.hidden = true;
    }
  });
  editor.addEventListener('submit', event => {
    if (submitting) { event.preventDefault(); return; }
    if (publication.value === 'PUBLISHED' && !confirm('Save and publish this product on the public website?')) {
      event.preventDefault(); return;
    }
    submitting = true;
    save.disabled = true;
    save.textContent = publication.value === 'PUBLISHED' ? 'Publishing…' : 'Saving…';
    editor.setAttribute('aria-busy', 'true');
    const processing = editor.querySelector('#photo-feedback');
    if (processing) processing.textContent = 'Saving product and processing photographs. Please keep this page open…';
  });
  window.addEventListener('pageshow', () => {
    submitting = false;
    save.disabled = false;
    editor.removeAttribute('aria-busy');
    updatePublication();
  });
})();

/* Mobile workspace navigation hides during scroll in either direction, recovers on idle. */
(() => {
  const header = document.querySelector('.admin-navbar');
  const menu = header?.querySelector('.nav-disclosure');
  if (!header || !menu) return;
  const mobile = matchMedia('(max-width: 1099px)');
  const reduced = matchMedia('(prefers-reduced-motion: reduce)');
  const lifetime = new AbortController();
  let frame = 0, idleTimer, lastY = Math.max(0, scrollY), travel = 0, scrolling = false;
  let keyboard = false, suspended = false;
  const render = () => {
    const active = document.activeElement;
    const keyboardFocus = header.contains(active) && (keyboard || active.matches(':focus-visible'));
    const editing = active.matches('input, select, textarea, [contenteditable="true"]');
    const critical = Boolean(document.querySelector('dialog[open], form[aria-busy="true"], form[data-busy="true"]'));
    const hidden = mobile.matches && !reduced.matches && document.body.dataset.reducedMotion !== 'true'
      && scrolling && lastY > 56 && !menu.open && !keyboardFocus && !editing && !critical;
    header.classList.toggle('is-scroll-hidden', hidden);
    header.inert = hidden;
    if (hidden) header.setAttribute('aria-hidden', 'true'); else header.removeAttribute('aria-hidden');
  };
  const reset = () => {
    clearTimeout(idleTimer); cancelAnimationFrame(frame); frame = 0;
    scrolling = false; travel = 0; lastY = Math.max(0, scrollY); render();
  };
  const update = () => {
    frame = 0;
    const y = Math.max(0, scrollY), delta = y - lastY;
    if (delta) {
      travel = Math.sign(delta) === Math.sign(travel) ? travel + delta : delta;
      if (Math.abs(travel) >= 8) { scrolling = true; travel = 0; }
    }
    lastY = y; render();
  };
  window.addEventListener('scroll', () => {
    if (!mobile.matches || reduced.matches || document.body.dataset.reducedMotion === 'true' || suspended) return;
    if (!frame) frame = requestAnimationFrame(update);
    clearTimeout(idleTimer);
    idleTimer = setTimeout(() => {
      if (frame) { cancelAnimationFrame(frame); update(); }
      scrolling = false; travel = 0; render();
    }, 300);
  }, { passive: true, signal: lifetime.signal });
  document.addEventListener('keydown', () => { keyboard = true; render(); }, { capture: true, signal: lifetime.signal });
  document.addEventListener('pointerdown', () => { keyboard = false; }, { capture: true, passive: true, signal: lifetime.signal });
  document.addEventListener('focusin', render, { signal: lifetime.signal });
  document.addEventListener('focusout', () => { if (!suspended) requestAnimationFrame(render); }, { signal: lifetime.signal });
  menu.addEventListener('toggle', render, { signal: lifetime.signal });
  mobile.addEventListener('change', reset, { signal: lifetime.signal });
  reduced.addEventListener('change', reset, { signal: lifetime.signal });
  const criticalState = new MutationObserver(render);
  criticalState.observe(document.body, { attributes: true, subtree: true, attributeFilter: ['aria-busy', 'data-busy', 'open'] });
  const preferences = new MutationObserver(reset);
  preferences.observe(document.body, { attributes: true, attributeFilter: ['data-reduced-motion'] });
  window.addEventListener('pagehide', event => {
    suspended = true; clearTimeout(idleTimer); cancelAnimationFrame(frame); frame = 0;
    if (!event.persisted) { lifetime.abort(); preferences.disconnect(); criticalState.disconnect(); }
  });
  window.addEventListener('pageshow', () => { suspended = false; reset(); });
  reset();
})();
