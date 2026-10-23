/* Own-account presentation only. Passwords, preferences and images are never stored locally. */
(() => {
  'use strict';
  const forms = [...document.querySelectorAll('[data-account-form]')];
  if (!forms.length) return;
  let submitting = false;
  const saved = new Map(forms.map(form => [form, JSON.parse(form.dataset.savedValues || '{}')]));
  const serialize = data => [...data.entries()].map(([key, value]) => [key, value instanceof File ? (value.size ? `${value.name}:${value.size}:${value.lastModified}` : '') : value]).sort(([a], [b]) => a.localeCompare(b)).map(pair => JSON.stringify(pair)).join('|');
  const baselines = new Map(forms.map(form => {
    const data = new FormData(form);
    for (const [key, value] of Object.entries(saved.get(form))) {
      data.delete(key); if (value !== null) data.append(key, value);
    }
    for (const field of form.querySelectorAll('input[type="password"]')) { data.delete(field.name); data.append(field.name, ''); }
    return [form, serialize(data)];
  }));
  const dirty = form => serialize(new FormData(form)) !== baselines.get(form);
  const buttons = new Map(forms.map(form => { const button = form.querySelector('[type="submit"]'); return [button, button.textContent]; }));
  window.addEventListener('beforeunload', event => {
    if (!submitting && forms.some(dirty)) { event.preventDefault(); event.returnValue = ''; }
  });
  const photo = document.querySelector('#profile-avatar');
  const preview = document.querySelector('#avatar-preview');
  const feedback = document.querySelector('#avatar-feedback');
  let objectURL;
  const clearPreview = () => {
    if (objectURL) URL.revokeObjectURL(objectURL);
    objectURL = null; preview.hidden = true; preview.removeAttribute('src'); feedback.textContent = '';
  };
  photo.addEventListener('change', () => {
    clearPreview(); photo.setCustomValidity(''); photo.removeAttribute('aria-invalid');
    const file = photo.files[0]; if (!file) return;
    if (file.size > 2 * 1024 * 1024 || !['image/jpeg','image/png','image/webp'].includes(file.type)) {
      photo.setCustomValidity('Choose a JPEG, PNG or WebP photograph no larger than 2 MB.');
      feedback.textContent = photo.validationMessage; photo.setAttribute('aria-invalid','true'); return;
    }
    objectURL = URL.createObjectURL(file); preview.src = objectURL; preview.hidden = false;
    feedback.textContent = `${file.name} selected. Save your profile to upload it.`;
  });
  const newPassword = document.querySelector('#password-new_password');
  const confirmation = document.querySelector('#password-confirm_password');
  const matchPasswords = () => {
    confirmation.setCustomValidity(confirmation.value && confirmation.value !== newPassword.value ? 'The new passwords do not match.' : '');
    if (confirmation.validity.valid) { confirmation.removeAttribute('aria-invalid'); const error = document.getElementById('password-confirm_password-error'); if (error) error.hidden = true; }
  };
  newPassword.addEventListener('input', matchPasswords); confirmation.addEventListener('input', matchPasswords);
  for (const form of forms) {
    form.addEventListener('invalid', event => {
      event.target.setAttribute('aria-invalid','true');
      const error = document.getElementById(`${event.target.id}-error`);
      if (error) { error.textContent = event.target.validationMessage; error.hidden = false; }
    }, true);
    form.addEventListener('input', event => {
      if (event.target.validity?.valid) {
        event.target.removeAttribute('aria-invalid');
        const error = document.getElementById(`${event.target.id}-error`); if (error) error.hidden = true;
      }
    });
    form.addEventListener('reset', event => {
      event.preventDefault();
      const defaults = saved.get(form);
      for (const field of form.querySelectorAll('input:not([type="hidden"]),select,textarea')) {
        if (field.type === 'checkbox') field.checked = defaults[field.name] === '1';
        else field.value = defaults[field.name] ?? '';
        field.setCustomValidity(''); field.removeAttribute('aria-invalid');
        const error = document.getElementById(`${field.id}-error`); if (error) { error.hidden = true; error.textContent = ''; }
      }
      if (form.contains(photo)) clearPreview();
    });
    form.addEventListener('submit', event => {
      if (submitting) { event.preventDefault(); return; }
      if (forms.some(other => other !== form && dirty(other)) && !confirm('Saving this section reloads the page. Unsaved changes in other sections will be lost. Continue?')) { event.preventDefault(); return; }
      submitting = true; form.setAttribute('aria-busy','true');
      const button = form.querySelector('[type="submit"]'); button.disabled = true; button.textContent = 'Saving…';
    });
  }
  window.addEventListener('pageshow', () => {
    submitting = false;
    for (const form of forms) form.removeAttribute('aria-busy');
    for (const [button, label] of buttons) { button.disabled = false; button.textContent = label; }
  });
})();
