(() => {
  'use strict';
  const dialog = document.getElementById('product-gallery');
  const photoLinks = document.querySelectorAll('[data-gallery-link]');
  // Image links remain usable without native dialog support or JavaScript.
  if (!dialog || typeof dialog.showModal !== 'function') return;
  const image = dialog.querySelector('[data-gallery-image]');
  const error = dialog.querySelector('[data-gallery-error]');
  const counter = dialog.querySelector('[data-gallery-counter]');
  const previous = dialog.querySelector('[data-gallery-prev]');
  const next = dialog.querySelector('[data-gallery-next]');
  const title = dialog.querySelector('#product-gallery-title');
  let links = [], position = 0, trigger = null, pointer = null;
  const show = index => {
    position = (index + links.length) % links.length;
    image.hidden = false; error.hidden = true;
    image.alt = links[position].querySelector('img')?.alt || title.textContent;
    image.src = links[position].href;
    counter.textContent = `${position + 1} / ${links.length}`;
    previous.disabled = next.disabled = links.length < 2;
  };
  photoLinks.forEach(link => {
    const thumbnail = link.querySelector('img');
    const failed = () => {
      if (link.querySelector('.photo-unavailable')) return;
      thumbnail.hidden = true;
      const label = document.createElement('span'); label.className = 'photo-unavailable'; label.textContent = 'Photo unavailable · View'; link.append(label);
    };
    thumbnail?.addEventListener('error', failed);
    if (thumbnail?.complete && thumbnail.naturalWidth === 0) failed();
    link.addEventListener('click', event => {
      if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
      event.preventDefault();
      const card = link.closest('.catalog-card');
      links = Array.from(card.querySelectorAll('[data-gallery-link]'));
      trigger = link; title.textContent = card.querySelector('h3').textContent;
      show(links.indexOf(link)); dialog.showModal(); document.documentElement.classList.add('gallery-open');
    });
  });
  image.addEventListener('error', () => { image.hidden = true; error.hidden = false; });
  previous.addEventListener('click', () => show(position - 1));
  next.addEventListener('click', () => show(position + 1));
  dialog.querySelector('[data-gallery-close]').addEventListener('click', () => dialog.close());
  dialog.addEventListener('click', event => {
    if (event.target !== dialog) return;
    const rect = dialog.getBoundingClientRect();
    if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) dialog.close();
  });
  dialog.addEventListener('close', () => {
    document.documentElement.classList.remove('gallery-open'); image.removeAttribute('src'); trigger?.focus(); pointer = null;
  });
  dialog.addEventListener('keydown', event => {
    if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
      event.preventDefault(); event.stopPropagation(); show(position + (event.key === 'ArrowRight' ? 1 : -1));
    }
  });
  const stage = dialog.querySelector('.gallery-stage');
  stage.addEventListener('pointerdown', event => { if (event.pointerType === 'touch') pointer = {x:event.clientX,y:event.clientY,id:event.pointerId}; });
  stage.addEventListener('pointercancel', () => { pointer = null; });
  stage.addEventListener('pointerup', event => {
    if (!pointer || pointer.id !== event.pointerId) return;
    const dx = event.clientX - pointer.x, dy = event.clientY - pointer.y; pointer = null;
    if (Math.abs(dx) > 48 && Math.abs(dx) > Math.abs(dy) * 1.5) show(position + (dx < 0 ? 1 : -1));
  });
})();
