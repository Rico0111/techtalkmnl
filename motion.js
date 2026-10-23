/* TECHTALK SIGNAL / MOTION — progressive enhancement; functional controllers stay in script.js. */
(() => {
  'use strict';
  const root = document.documentElement;
  if (root.dataset.signalMounted) return;
  root.dataset.signalMounted = 'true';
  const css = getComputedStyle(root);
  const duration = name => parseFloat(css.getPropertyValue(name)) || 0;
  const motion = Object.freeze({
    fast: duration('--motion-fast'), scene: duration('--motion-scene'), entry: duration('--motion-entry'),
    ease: css.getPropertyValue('--motion-ease').trim() || 'ease-out',
    distance: duration('--motion-distance'), tilt: duration('--motion-tilt'), perspective: 850
  });
  const reduced = matchMedia('(prefers-reduced-motion: reduce)');
  const precise = matchMedia('(hover: hover) and (pointer: fine) and (min-width: 768px)');
  const animations = new Set();
  const seen = new WeakSet();
  const lifetime = new AbortController();
  let featureCleanups = [];
  let navigationCleanup = () => {};
  let entered = false;

  function play(element, frames, options = {}) {
    if (!element?.animate || reduced.matches || document.hidden) return;
    try {
      const animation = element.animate(frames, { duration: motion.scene, easing: motion.ease, fill: 'none', ...options });
      animations.add(animation);
      animation.finished.then(() => animations.delete(animation), () => animations.delete(animation));
      return animation;
    } catch {
      // Every target already has a complete static style; initialization cannot hide content.
    }
  }

  function entrance() {
    if (entered) return;
    entered = true;
    root.dataset.signalEntry = reduced.matches || document.hidden ? 'skipped' : 'played';
    if (reduced.matches || document.hidden) return;
    document.querySelectorAll('.hero-word').forEach((word, i) => play(word,
      [
        { clipPath: 'inset(0 0 100% 0)', transform: `translateY(${motion.distance}px)` },
        { clipPath: 'inset(0 0 0 0)', transform: 'translateY(0)' }
      ],
      { duration: motion.entry - 120, delay: i * 110 }));
    play(document.querySelector('.entry-pulse'), [
      { transform: 'scale(.6)', opacity: .75 }, { transform: 'scale(1.9)', opacity: 0 }
    ], { duration: motion.entry });
    play(document.querySelector('.signal-trace-fill'), [
      { transform: 'scaleY(0)' }, { transform: 'scaleY(1)' }
    ], { duration: motion.entry - 160, delay: 120 });
  }

  function navigation() {
    if (typeof IntersectionObserver !== 'function') return () => {};
    const links = [...document.querySelectorAll('.quick-nav a')];
    const sections = links.map(link => document.querySelector(link.getAttribute('href'))).filter(Boolean);
    const ratios = new Map();
    const events = new AbortController();
    const select = id => links.forEach(link => {
      if (link.hash === `#${id}`) link.setAttribute('aria-current', 'location');
      else link.removeAttribute('aria-current');
    });
    links.forEach(link => link.addEventListener('click', () => select(link.hash.slice(1)), { signal: events.signal }));
    const observer = new IntersectionObserver(entries => {
      if (document.hidden) return;
      entries.forEach(entry => ratios.set(entry.target, entry.isIntersecting ? entry.intersectionRatio : 0));
      const current = [...ratios].sort((a, b) => b[1] - a[1])[0];
      if (current?.[1] > 0) select(current[0].id);
      else links.forEach(link => link.removeAttribute('aria-current'));
    }, { rootMargin: '-8% 0px -12% 0px', threshold: [0, .15, .35, .6] });
    sections.forEach(section => observer.observe(section));
    return () => { observer.disconnect(); events.abort(); };
  }

  function scenes() {
    if (typeof IntersectionObserver !== 'function') return () => {};
    const observer = new IntersectionObserver(entries => {
      entries.forEach(entry => {
        if (!entry.isIntersecting || document.hidden || seen.has(entry.target)) return;
        seen.add(entry.target);
        observer.unobserve(entry.target);
        const rule = entry.target.querySelector('.scene-rule');
        play(rule, [{ transform: 'scaleX(.12)' }, { transform: 'scaleX(1)' }]);
        // Editorial reveals: a clip-path wipe for poster headings, a short stagger for cards.
        entry.target.querySelectorAll('.section-heading h2, .contact-heading, .reviews-showcase h2, .meetup-heading h2').forEach((heading, i) => play(heading, [
          { clipPath: 'inset(0 0 100% 0)', opacity: .35 },
          { clipPath: 'inset(0 0 0 0)', opacity: 1 }
        ], { duration: motion.scene, delay: i * 80 }));
        entry.target.querySelectorAll('.category-card, .unit-card, .link-card, .location-chip').forEach((card, i) => play(card, [
          { transform: `translateY(${motion.distance}px)`, opacity: .35 },
          { transform: 'translateY(0)', opacity: 1 }
        ], { duration: motion.scene, delay: 40 + i * 55 }));
        if (entry.target.id === 'available-units') {
          entry.target.querySelectorAll('.product-scan').forEach((scan, i) => play(scan, [
            { transform: 'translateX(-100%)', opacity: 0 },
            { transform: 'translateX(0)', opacity: .6, offset: .5 },
            { transform: 'translateX(100%)', opacity: 0 }
          ], { delay: i * 80, duration: motion.scene }));
        } else if (entry.target.id === 'carousell-reviews') {
          play(entry.target.querySelector('.review-slide[aria-hidden="false"] .review-quote'), [
            { transform: 'scale(.82)', opacity: .6 }, { transform: 'scale(1)', opacity: 1 }
          ]);
        }
      });
    }, { threshold: .08 });
    document.querySelectorAll('#contact, #carousell-reviews, #available-units, #meetups, .category-section').forEach(section => observer.observe(section));
    return () => observer.disconnect();
  }

  function products() {
    if (!precise.matches) return () => {};
    const events = new AbortController();
    const options = { signal: events.signal, passive: true };
    let frame = 0;
    let active = null;
    let bounds = null;
    let point = null;
    const reset = () => {
      cancelAnimationFrame(frame); frame = 0;
      if (active) {
        ['--product-transform', '--light-x', '--light-y', '--light-opacity'].forEach(name => active.style.removeProperty(name));
        delete active.dataset.depthActive;
      }
      active = bounds = point = null;
    };
    const render = () => {
      frame = 0;
      if (!active || !bounds || !point || reduced.matches || document.hidden) return;
      const x = Math.max(0, Math.min(1, (point.x - bounds.left) / bounds.width));
      const y = Math.max(0, Math.min(1, (point.y - bounds.top) / bounds.height));
      active.style.setProperty('--product-transform', `perspective(${motion.perspective}px) rotateX(${((.5 - y) * motion.tilt).toFixed(2)}deg) rotateY(${((x - .5) * motion.tilt).toFixed(2)}deg) scale(1.025)`);
      active.style.setProperty('--light-x', `${(x * 100).toFixed(1)}%`);
      active.style.setProperty('--light-y', `${(y * 100).toFixed(1)}%`);
      active.style.setProperty('--light-opacity', '.8');
    };
    document.querySelectorAll('.unit-card').forEach(card => {
      card.addEventListener('pointerenter', event => {
        if (event.pointerType !== 'mouse' || card.contains(document.activeElement)) return;
        reset();
        active = card;
        // One measurement per entry, never a layout read inside the frame callback.
        bounds = card.querySelector('.unit-image').getBoundingClientRect();
        active.dataset.depthActive = 'true';
      }, options);
      card.addEventListener('pointermove', event => {
        if (active !== card || event.pointerType !== 'mouse') return;
        point = { x: event.clientX, y: event.clientY };
        if (!frame) frame = requestAnimationFrame(render);
      }, options);
      card.addEventListener('pointerleave', reset, options);
      card.addEventListener('focusin', reset, options);
    });
    document.getElementById('unitsSlider')?.addEventListener('scroll', reset, options);
    // Native document scrolling also invalidates cached bounds; no scroll geometry reads.
    window.addEventListener('scroll', reset, options);
    window.addEventListener('resize', reset, options);
    const observer = typeof IntersectionObserver === 'function' ? new IntersectionObserver(entries => {
      if (entries.some(entry => !entry.isIntersecting)) reset();
    }) : null;
    const section = document.getElementById('available-units');
    if (section) observer?.observe(section);
    return () => { reset(); observer?.disconnect(); events.abort(); };
  }

  function stop() {
    featureCleanups.forEach(cleanup => cleanup());
    featureCleanups = [];
    navigationCleanup(); navigationCleanup = () => {};
    animations.forEach(animation => animation.cancel());
    animations.clear();
  }

  function mount() {
    stop();
    entrance();
    if (document.hidden) return;
    try { navigationCleanup = navigation(); } catch { /* Anchors remain native. */ }
    if (reduced.matches) return;
    for (const setup of [scenes, products]) {
      try { featureCleanups.push(setup()); } catch { /* Static surfaces remain usable. */ }
    }
  }

  reduced.addEventListener('change', mount, { signal: lifetime.signal });
  precise.addEventListener('change', mount, { signal: lifetime.signal });
  document.addEventListener('visibilitychange', () => document.hidden ? stop() : mount(), { signal: lifetime.signal });
  window.addEventListener('pagehide', stop, { signal: lifetime.signal });
  window.addEventListener('pageshow', event => { if (event.persisted) mount(); }, { signal: lifetime.signal });
  mount();
})();
