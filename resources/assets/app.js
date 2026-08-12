/* The two things the page does that the server has no opinion about: which
   palette you prefer, and clearing a message once you've had time to read it.

   Plain JavaScript with no build step, loaded once and kept alive across
   wire:navigate page swaps — hence the delegated listeners and the
   `livewire:navigated` hook rather than anything bound at load time. */
(() => {
  'use strict';

  const THEME_KEY = 'gratitudeTheme';
  const TOAST_MS = 2500;

  /* ─── Theme ──────────────────────────────────────────────────────
     The stored value is applied by an inline script in <head>, before the
     first paint. All that's left here is the toggle and its label. */
  const readTheme = () => document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light';

  function applyTheme(theme) {
    document.documentElement.dataset.theme = theme;
    try { localStorage.setItem(THEME_KEY, theme); } catch { /* private browsing */ }
    labelTheme();
  }

  function labelTheme() {
    const dark = readTheme() === 'dark';

    document.querySelectorAll('[data-theme-toggle]').forEach(button => {
      button.setAttribute('aria-pressed', String(dark));
      button.textContent = dark ? '☀ Light' : '☾ Dark';
    });
  }

  document.addEventListener('click', event => {
    if (event.target.closest('[data-theme-toggle]')) {
      applyTheme(readTheme() === 'dark' ? 'light' : 'dark');
    }
  });

  /* ─── Toast ──────────────────────────────────────────────────────
     Two sources, one region: a `toast` event dispatched by a Livewire
     component, and a flash message rendered into the region by the layout
     after a redirect. */
  let timer = null;

  function flash(message) {
    const region = document.querySelector('[data-toast-region]');
    if (!region || !message) return;

    region.innerHTML = '';
    const toast = document.createElement('div');
    toast.className = 'toast';
    toast.textContent = message;
    region.append(toast);

    clearTimeout(timer);
    timer = setTimeout(() => toast.remove(), TOAST_MS);
  }

  window.addEventListener('toast', event => flash(event.detail?.message));

  function onPageReady() {
    labelTheme();

    const region = document.querySelector('[data-toast-region]');
    if (region?.dataset.toast) {
      const message = region.dataset.toast;
      region.dataset.toast = ''; // so a morph can't replay it
      flash(message);
    }
  }

  document.addEventListener('DOMContentLoaded', onPageReady);
  document.addEventListener('livewire:navigated', onPageReady);
})();
