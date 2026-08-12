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
     The preference lives in localStorage, and an inline script in <head>
     applies it before the first paint. The preference — not the attribute
     currently on <html> — is the source of truth here, because that attribute
     does not survive a page swap; see the observer below. */
  const readTheme = () => document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light';

  function preferredTheme() {
    let stored = null;
    try { stored = localStorage.getItem(THEME_KEY); } catch { /* private browsing */ }

    if (stored === 'light' || stored === 'dark') return stored;

    return matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }

  function applyTheme(theme) {
    // Stored first: syncTheme() reads the preference back, and the observer
    // below can call it as soon as the attribute changes.
    try { localStorage.setItem(THEME_KEY, theme); } catch { /* private browsing */ }
    document.documentElement.dataset.theme = theme;
    labelTheme();
  }

  function syncTheme() {
    const theme = preferredTheme();

    if (document.documentElement.dataset.theme !== theme) {
      document.documentElement.dataset.theme = theme;
    }

    labelTheme();
  }

  function labelTheme() {
    const dark = readTheme() === 'dark';

    document.querySelectorAll('[data-theme-toggle]').forEach(button => {
      button.setAttribute('aria-pressed', String(dark));
      button.textContent = dark ? '☀ Light' : '☾ Dark';
    });
  }

  /* Every wire:navigate swap — the auth tabs, and the redirect after signing in
     — copies the incoming document's <html> attributes over the live ones, so
     the layout's `data-theme="light"` lands back on the page and the palette
     resets. The server cannot render anything better, since the preference is
     only ever known to the browser, so put it back the moment it is overwritten.
     A MutationObserver runs before the next paint, so the light palette never
     becomes visible, and it is not tied to any particular swap mechanism —
     forward navigation, the back button and a restored snapshot all take the
     same route through <html>. Re-applying the same value is a no-op, so the
     toggle's own writes cannot loop back through here. */
  new MutationObserver(syncTheme).observe(document.documentElement, {
    attributes: true,
    attributeFilter: ['data-theme'],
  });

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
    syncTheme();

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
