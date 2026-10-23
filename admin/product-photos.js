/* Progressive enhancement: ordinary multipart selection works without JavaScript. */
(() => {
  'use strict';
  const input = document.getElementById('product_photo');
  if (!input) return;
  const editor = input.form;
  const list = document.getElementById('selected-photos');
  const feedback = document.getElementById('photo-feedback');
  const dropzone = input.closest('[data-photo-dropzone]');
  const editable = typeof DataTransfer === 'function';
  let files = [];
  let urls = [];
  const limits = {count: Number(input.dataset.maxCount), file: Number(input.dataset.maxFile), batch: Number(input.dataset.maxBatch)};
  const render = () => {
    urls.forEach(url => URL.revokeObjectURL(url)); urls = [];
    list.replaceChildren();
    let invalid = false;
    const total = files.reduce((n, file) => n + file.size, 0);
    const batchError = files.length > limits.count ? `Select no more than ${limits.count} photographs.`
      : total > limits.batch ? 'Combined upload is too large. Remove some photographs.' : '';
    files.forEach((file, index) => {
      const card = document.createElement('div'); card.className = 'selected-photo';
      const name = document.createElement('strong'); name.textContent = file.name;
      const message = document.createElement('p'); message.className = 'help-text';
      const error = file.size < 1 || file.size > limits.file ? 'File is empty or exceeds the file-size limit.'
        : file.type && !['image/jpeg','image/png','image/webp'].includes(file.type) ? 'Choose JPEG, PNG or WebP.' : '';
      if (error) { invalid = true; message.className = 'field-error'; message.textContent = error; }
      else {
        message.textContent = `${(file.size / 1048576).toFixed(1)} MB · ready for server validation`;
        const image = document.createElement('img'); image.alt = `Selected photograph ${index + 1}`;
        const url = URL.createObjectURL(file); urls.push(url); image.src = url;
        image.addEventListener('error', () => { image.hidden = true; message.textContent = 'Preview unavailable. The server will validate this image when saved.'; });
        card.append(image);
      }
      card.append(name, message);
      if (editable) {
        const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'btn btn-secondary btn-sm';
        remove.textContent = 'Remove'; remove.setAttribute('aria-label', `Remove ${file.name} from selection`);
        remove.addEventListener('click', () => {
          if (editor.getAttribute('aria-busy') === 'true') return;
          files.splice(index, 1); sync(); render(); input.focus();
        });
        card.append(remove);
      }
      list.append(card);
    });
    input.setCustomValidity(batchError || (invalid ? 'Remove or replace the invalid photographs before saving.' : ''));
    input.setAttribute('aria-invalid', String(Boolean(batchError || invalid)));
    feedback.textContent = batchError || (files.length ? `${files.length} photograph${files.length === 1 ? '' : 's'} selected. ${editable ? 'Remove any you do not want before saving.' : 'Choose files again to replace this selection.'}` : 'No new photographs selected. Saved photographs remain unchanged.');
  };
  const sync = () => {
    if (!editable) return;
    const transfer = new DataTransfer(); files.forEach(file => transfer.items.add(file)); input.files = transfer.files;
  };
  const add = selected => {
    if (editor.getAttribute('aria-busy') === 'true') return;
    files = editable ? [...files, ...Array.from(selected).filter(file => !files.includes(file))] : Array.from(selected);
    sync(); render();
    input.dispatchEvent(new Event('input', {bubbles: true}));
  };
  input.addEventListener('change', () => add(input.files));
  if (editable) {
    dropzone.addEventListener('dragover', event => { event.preventDefault(); dropzone.classList.add('is-dragging'); });
    dropzone.addEventListener('dragleave', () => dropzone.classList.remove('is-dragging'));
    dropzone.addEventListener('drop', event => { event.preventDefault(); dropzone.classList.remove('is-dragging'); add(event.dataTransfer.files); });
  }
  window.addEventListener('pagehide', () => { urls.forEach(url => URL.revokeObjectURL(url)); urls = []; });
  window.addEventListener('pageshow', () => { files = Array.from(input.files); render(); });
  document.querySelectorAll('form[id^="primary-image-"], form[id^="delete-image-"]').forEach(form => {
    form.addEventListener('submit', event => {
      if (event.defaultPrevented) return;
      if (form.dataset.busy) { event.preventDefault(); return; }
      form.dataset.busy = 'true';
      document.querySelectorAll(`button[form="${form.id}"]`).forEach(button => { button.disabled = true; });
    });
  });
})();
