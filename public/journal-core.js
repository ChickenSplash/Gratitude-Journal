/* Everything the journal does that isn't rendering: talking to the API,
   touching localStorage, and tidying dates and entries.

   Plain JavaScript with no JSX, so this file loads as an ordinary script and
   never goes near Babel. It hands the UI a single `Journal` global rather than
   scattering names across `window`. */
const Journal = (() => {
  'use strict';

  const KEYS = {
    theme: 'gratitudeTheme',
    /* Written by the version of this app that predates the server. Read once
       so an upgrade doesn't look like the journal has been wiped. */
    legacyEntries: 'gratitudeEntries',
  };

  /* ─── API ────────────────────────────────────────────────────────
     The session lives in an HttpOnly cookie, so there is no token for
     this code to hold — the browser attaches it and JS can't read it. */
  async function request(method, path, body) {
    const res = await fetch(path, {
      method,
      credentials: 'same-origin',
      headers: body ? { 'Content-Type': 'application/json' } : undefined,
      body: body ? JSON.stringify(body) : undefined,
    });

    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || 'Something went wrong.');
    return data;
  }

  const api = {
    me:       ()      => request('GET',    '/api/auth/me'),
    login:    creds   => request('POST',   '/api/auth/login', creds),
    register: creds   => request('POST',   '/api/auth/register', creds),
    logout:   ()      => request('POST',   '/api/auth/logout'),
    list:     ()      => request('GET',    '/api/entries'),
    create:   entry   => request('POST',   '/api/entries', entry),
    bulk:     entries => request('POST',   '/api/entries/bulk', { entries }),
    remove:   id      => request('DELETE', `/api/entries/${encodeURIComponent(id)}`),
  };

  /* ─── localStorage ───────────────────────────────────────────────
     It can throw outright — disabled cookies, private browsing, quota —
     so every call is wrapped rather than guarded at each call site. */
  const local = {
    get(key) {
      try { return window.localStorage.getItem(key); } catch { return null; }
    },
    set(key, value) {
      try { window.localStorage.setItem(key, value); } catch { /* ignore */ }
    },
    remove(key) {
      try { window.localStorage.removeItem(key); } catch { /* ignore */ }
    },
  };

  /* ─── Entries ──────────────────────────────────────────────────── */
  const newId = () =>
    window.crypto?.randomUUID?.() ??
    `${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;

  const byNewest = (a, b) => new Date(b.date) - new Date(a.date);

  /** Validates parsed JSON from an imported file. Anything malformed is
   *  dropped rather than allowed to crash the render. */
  function sanitiseEntries(parsed) {
    if (!Array.isArray(parsed)) return [];

    return parsed
      .filter(e => e && typeof e === 'object' && Array.isArray(e.items))
      .map(e => ({
        id: String(e.id ?? newId()),
        date: typeof e.date === 'string' ? e.date : new Date().toISOString(),
        items: e.items.filter(i => typeof i === 'string' && i.trim() !== ''),
      }))
      .filter(e => e.items.length > 0);
  }

  /** Entries left behind by the localStorage-only version, if any. */
  function loadLegacyEntries() {
    const raw = local.get(KEYS.legacyEntries);
    if (!raw) return [];

    try {
      return sanitiseEntries(JSON.parse(raw)).sort(byNewest);
    } catch {
      return [];
    }
  }

  /** Downloads the given entries as the JSON file the importer expects. */
  function downloadEntries(entries) {
    const blob = new Blob([JSON.stringify(entries, null, 2)], {
      type: 'application/json',
    });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');

    link.href = url;
    link.download = `gratitude-journal-${new Date().toISOString().slice(0, 10)}.json`;
    link.click();
    URL.revokeObjectURL(url);
  }

  /** Reads and validates a file the user picked. Throws if it isn't JSON. */
  async function readEntriesFile(file) {
    return sanitiseEntries(JSON.parse(await file.text()));
  }

  /* ─── Dates ──────────────────────────────────────────────────────
     Formatters are built once; constructing Intl objects is not cheap. */
  const withYear = new Intl.DateTimeFormat('en-GB', {
    day: 'numeric', month: 'short', year: 'numeric',
  });
  const withoutYear = new Intl.DateTimeFormat('en-GB', {
    day: 'numeric', month: 'short',
  });

  function formatDate(isoString) {
    const date = new Date(isoString);
    if (Number.isNaN(date.getTime())) return 'Unknown date';

    const today = new Date();
    const yesterday = new Date(today);
    yesterday.setDate(yesterday.getDate() - 1);

    if (date.toDateString() === today.toDateString()) return 'Today';
    if (date.toDateString() === yesterday.toDateString()) return 'Yesterday';

    return date.getFullYear() === today.getFullYear()
      ? withoutYear.format(date)
      : withYear.format(date);
  }

  /** "1 entry" / "2 entries", the app's most-repeated bit of grammar. */
  const plural = (count, one = 'entry', many = 'entries') =>
    count === 1 ? one : many;

  return {
    KEYS, api, local,
    newId, byNewest, sanitiseEntries, loadLegacyEntries,
    downloadEntries, readEntriesFile,
    formatDate, plural,
  };
})();
