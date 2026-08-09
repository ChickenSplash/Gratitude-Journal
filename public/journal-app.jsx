/* The UI. Everything that isn't rendering lives in journal-core.js and
   arrives through the `Journal` global below. */
const { useState, useEffect, useRef, useCallback, useMemo } = React;

const {
  KEYS, api, local,
  newId, byNewest, loadLegacyEntries, downloadEntries, readEntriesFile,
  formatDate, plural,
} = Journal;

const SLOTS = 3;        // gratitude lines per entry
const TOAST_MS = 2500;

/* ═══ Hooks ═══════════════════════════════════════════════════════ */

/** A message that clears itself, and cleans up if the app unmounts first. */
function useToast() {
  const [message, setMessage] = useState('');
  const timer = useRef(null);

  useEffect(() => () => clearTimeout(timer.current), []);

  const flash = useCallback(text => {
    setMessage(text);
    clearTimeout(timer.current);
    timer.current = setTimeout(() => setMessage(''), TOAST_MS);
  }, []);

  return [message, flash];
}

function useTheme() {
  const [theme, setTheme] = useState(() => {
    const stored = local.get(KEYS.theme);
    if (stored === 'light' || stored === 'dark') return stored;
    return window.matchMedia?.('(prefers-color-scheme: dark)').matches
      ? 'dark'
      : 'light';
  });

  useEffect(() => {
    document.documentElement.dataset.theme = theme;
    local.set(KEYS.theme, theme);
  }, [theme]);

  const toggle = useCallback(
    () => setTheme(t => (t === 'dark' ? 'light' : 'dark')),
    []
  );

  return [theme, toggle];
}

/** Who's signed in, what they've written, and every way to change it.
 *
 *  Each action has one branch per storage backend: the server for members,
 *  plain React state for guests. Nothing above this hook needs to know which
 *  of the two it's talking to. */
function useJournal(flash) {
  const [user, setUser] = useState(undefined); // undefined = still checking
  const [entries, setEntries] = useState([]);
  const [carriedOver, setCarriedOver] = useState(0); // from the old version

  const signedIn = Boolean(user);

  /** Drops to guest state, seeded with anything the old version left behind. */
  const resetToGuest = useCallback(() => {
    const legacy = loadLegacyEntries();
    setEntries(legacy);
    setCarriedOver(legacy.length);
  }, []);

  /* Who am I? Runs once; the cookie decides. */
  useEffect(() => {
    let live = true;

    (async () => {
      try {
        const { user } = await api.me();
        if (!live) return;
        setUser(user);

        if (!user) return resetToGuest();

        const { entries: mine } = await api.list();
        if (live) setEntries(mine);
      } catch {
        if (!live) return;
        setUser(null); // server unreachable — degrade to guest mode
        resetToGuest();
      }
    })();

    return () => { live = false; };
  }, [resetToGuest]);

  /** Signs in or registers, handing over anything written as a guest.
   *
   *  Bad credentials throw so the form can show why. A failed hand-off must
   *  not: the session already exists by then, and pretending we're still a
   *  guest would be a lie. */
  const signIn = async (mode, credentials) => {
    const { user } = mode === 'register'
      ? await api.register(credentials)
      : await api.login(credentials);

    const guestEntries = entries;
    let adopted = 0;
    let mine;

    try {
      if (guestEntries.length > 0) {
        ({ added: adopted, entries: mine } = await api.bulk(guestEntries));
      } else {
        ({ entries: mine } = await api.list());
      }
    } catch {
      mine = [];
    }

    if (adopted > 0) {
      local.remove(KEYS.legacyEntries); // safely in an account now
      setCarriedOver(0);
    }

    setUser(user);
    setEntries(mine);
    flash(
      adopted > 0
        ? `Signed in — ${adopted} ${plural(adopted)} saved to your account.`
        : `Signed in as ${user.username}.`
    );
  };

  const signOut = async () => {
    try { await api.logout(); } catch { /* the cookie is gone either way */ }
    setUser(null);
    resetToGuest();
    flash('Signed out.');
  };

  /** Returns true when the entry was kept, so the form knows to clear. */
  const addEntry = async items => {
    const entry = { id: newId(), date: new Date().toISOString(), items };

    if (!signedIn) {
      setEntries(prev => [entry, ...prev]);
      flash('Saved for this visit only.');
      return true;
    }

    try {
      const { entry: saved } = await api.create(entry);
      setEntries(prev => [saved, ...prev]);
      flash('Entry saved.');
      return true;
    } catch (err) {
      flash(err.message);
      return false;
    }
  };

  const removeEntry = async id => {
    if (signedIn) {
      try {
        await api.remove(id);
      } catch (err) {
        flash(err.message);
        return;
      }
    }
    setEntries(prev => prev.filter(e => e.id !== id));
  };

  /** Adds entries from an exported file, skipping ones already here. */
  const importFile = async file => {
    let incoming;
    try {
      incoming = await readEntriesFile(file);
    } catch {
      flash("Couldn't read that file — is it a journal export?");
      return;
    }

    if (signedIn) {
      try {
        const { added, entries: mine } = await api.bulk(incoming);
        setEntries(mine);
        flash(
          added === 0
            ? 'Nothing new in that file.'
            : `Imported ${added} ${plural(added)}.`
        );
      } catch (err) {
        flash(err.message);
      }
      return;
    }

    const seen = new Set(entries.map(e => e.id));
    const fresh = incoming.filter(e => !seen.has(e.id));

    if (fresh.length === 0) {
      flash(
        incoming.length === 0
          ? 'No entries found in that file.'
          : 'Those entries are already in your journal.'
      );
      return;
    }

    setEntries(prev => [...prev, ...fresh].sort(byNewest));
    flash(`Imported ${fresh.length} ${plural(fresh.length)}.`);
  };

  return {
    user, signedIn, entries, carriedOver,
    ready: user !== undefined,
    signIn, signOut, addEntry, removeEntry, importFile,
    exportEntries: () => downloadEntries(entries),
  };
}

/* ═══ Components ══════════════════════════════════════════════════ */

function TopBar({ user, authOpen, onToggleAuth, onSignOut, theme, onToggleTheme }) {
  return (
    <div className="topbar">
      <span className="who">
        {user
          ? <>Signed in as <strong>{user.username}</strong></>
          : 'Guest'}
      </span>

      {user ? (
        <button type="button" className="btn btn-icon" onClick={onSignOut}>
          Sign out
        </button>
      ) : (
        <button
          type="button"
          className="btn btn-icon"
          onClick={onToggleAuth}
          aria-expanded={authOpen}
        >
          Sign in
        </button>
      )}

      <button
        type="button"
        className="btn btn-icon"
        onClick={onToggleTheme}
        aria-pressed={theme === 'dark'}
      >
        {theme === 'dark' ? '☀ Light' : '☾ Dark'}
      </button>
    </div>
  );
}

function AuthPanel({ mode, setMode, onSubmit, onCancel, pendingCount }) {
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const registering = mode === 'register';

  const submit = async event => {
    event.preventDefault();
    setError('');
    setBusy(true);

    try {
      await onSubmit(mode, { username, password });
    } catch (err) {
      setError(err.message);
      setBusy(false);
    }
  };

  const switchTo = next => () => { setMode(next); setError(''); };

  return (
    <section className="card" aria-label="Account">
      <div className="tabs" role="tablist">
        <button type="button" role="tab" aria-selected={!registering} onClick={switchTo('login')}>
          Sign in
        </button>
        <button type="button" role="tab" aria-selected={registering} onClick={switchTo('register')}>
          Create account
        </button>
      </div>

      <form onSubmit={submit}>
        <div className="field">
          <label htmlFor="username">Username</label>
          <input
            id="username"
            name="username"
            autoComplete="username"
            value={username}
            onChange={e => setUsername(e.target.value)}
            placeholder="grateful.human"
            required
          />
        </div>

        <div className="field">
          <label htmlFor="password">Password</label>
          <input
            id="password"
            name="password"
            type="password"
            autoComplete={registering ? 'new-password' : 'current-password'}
            value={password}
            onChange={e => setPassword(e.target.value)}
            placeholder={registering ? 'At least 8 characters' : ''}
            required
          />
        </div>

        {error && <p className="form-error" role="alert">{error}</p>}

        <button type="submit" className="btn btn-primary" disabled={busy}>
          {busy ? 'One moment…' : registering ? 'Create account' : 'Sign in'}
        </button>
      </form>

      <p className="auth-note">
        {pendingCount > 0 && (
          <>
            Your {pendingCount} unsaved {plural(pendingCount)} will
            move into your account.<br />
          </>
        )}
        <button type="button" className="btn btn-quiet" onClick={onCancel}>
          Carry on as a guest
        </button>
      </p>
    </section>
  );
}

/** The three gratitude lines. Owns the drafts: nothing above here cares what
 *  is half-typed, only about the finished entry. */
function EntryComposer({ onSave }) {
  const [drafts, setDrafts] = useState(() => Array(SLOTS).fill(''));
  const firstField = useRef(null);

  const filled = useMemo(
    () => drafts.map(d => d.trim()).filter(Boolean),
    [drafts]
  );

  const updateDraft = (index, value) =>
    setDrafts(prev => prev.map((d, i) => (i === index ? value : d)));

  const save = async () => {
    if (filled.length === 0) return;
    if (!await onSave(filled)) return; // failed — leave what was typed alone

    setDrafts(Array(SLOTS).fill(''));
    firstField.current?.focus();
  };

  /* Ctrl/Cmd+Enter from any textarea saves. */
  const onKeyDown = event => {
    if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
      event.preventDefault();
      save();
    }
  };

  return (
    <section className="card" aria-label="New entry">
      {drafts.map((value, index) => (
        <div className="field" key={index}>
          <label htmlFor={`gratitude-${index}`}>{index + 1}.</label>
          <textarea
            id={`gratitude-${index}`}
            ref={index === 0 ? firstField : null}
            value={value}
            onChange={e => updateDraft(index, e.target.value)}
            onKeyDown={onKeyDown}
            placeholder="I'm grateful for..."
          />
        </div>
      ))}

      <button
        type="button"
        className="btn btn-primary"
        onClick={save}
        disabled={filled.length === 0}
      >
        Save entry
      </button>

      <p className="hint">
        <kbd>Ctrl</kbd> + <kbd>Enter</kbd> saves
      </p>
    </section>
  );
}

function EntryCard({ entry, onDelete }) {
  const [confirming, setConfirming] = useState(false);
  const label = formatDate(entry.date);

  return (
    <article className="entry">
      <header>
        <time dateTime={entry.date}>{label}</time>

        {confirming ? (
          <div className="confirm">
            <span>Delete this entry?</span>
            <button
              type="button"
              className="btn btn-icon btn-danger"
              onClick={() => onDelete(entry.id)}
            >
              Delete
            </button>
            <button
              type="button"
              className="btn btn-icon"
              onClick={() => setConfirming(false)}
            >
              Keep
            </button>
          </div>
        ) : (
          <button
            type="button"
            className="btn btn-icon"
            onClick={() => setConfirming(true)}
          >
            Delete<span className="sr-only"> entry from {label}</span>
          </button>
        )}
      </header>

      <ol>
        {entry.items.map((item, i) => (
          <li key={i}>{item}</li>
        ))}
      </ol>
    </article>
  );
}

/** Show/hide, export and import. Import is offered even with an empty
 *  journal — it's how you get entries back after a reinstall. */
function HistoryControls({ count, showing, onToggle, onExport, onImport }) {
  const fileInput = useRef(null);

  const pick = event => {
    const file = event.target.files?.[0];
    event.target.value = ''; // so the same file can be picked again
    if (file) onImport(file);
  };

  return (
    <div className="history-bar">
      {count > 0 && (
        <>
          <button
            type="button"
            className="btn btn-quiet"
            onClick={onToggle}
            aria-expanded={showing}
            aria-controls="history"
          >
            {showing ? 'Hide past entries' : 'View past entries'} ({count})
          </button>
          <button type="button" className="btn btn-icon" onClick={onExport}>
            Export
          </button>
        </>
      )}

      <button
        type="button"
        className="btn btn-icon"
        onClick={() => fileInput.current?.click()}
      >
        Import
      </button>
      <input
        type="file"
        accept="application/json,.json"
        ref={fileInput}
        onChange={pick}
        className="sr-only"
        aria-label="Import entries from a JSON file"
      />
    </div>
  );
}

function History({ entries, onDelete }) {
  return (
    <section className="history" id="history">
      <h2>Past entries</h2>
      {entries.map(entry => (
        <EntryCard key={entry.id} entry={entry} onDelete={onDelete} />
      ))}
    </section>
  );
}

/** What a guest stands to lose, and how to stop losing it. */
function GuestNote({ carriedOver, onCreateAccount }) {
  return (
    <p className="empty">
      {carriedOver > 0
        ? `${carriedOver} ${plural(carriedOver)} from the earlier version of ` +
          'this app are still on this device. New ones vanish when you close ' +
          'the tab. '
        : "You're writing as a guest, so entries vanish when you close this tab. "}
      <button type="button" className="btn btn-quiet" onClick={onCreateAccount}>
        Create an account
      </button>{' '}
      to keep them.
    </p>
  );
}

/* ═══ App ═════════════════════════════════════════════════════════ */

function GratitudeJournal() {
  const [theme, toggleTheme] = useTheme();
  const [toast, flash] = useToast();
  const journal = useJournal(flash);

  const [authMode, setAuthMode] = useState(null); // null | 'login' | 'register'
  const [showHistory, setShowHistory] = useState(false);

  const { user, signedIn, entries, carriedOver } = journal;

  const signIn = async (mode, credentials) => {
    await journal.signIn(mode, credentials);
    setAuthMode(null);
  };

  const signOut = async () => {
    await journal.signOut();
    setShowHistory(false);
  };

  /* Nothing renders until the session check settles, so the header can't
     flicker from "Sign in" to a username. */
  if (!journal.ready) {
    return (
      <div className="shell">
        <p className="empty">Opening your journal…</p>
      </div>
    );
  }

  return (
    <div className="shell">
      <TopBar
        user={user}
        authOpen={authMode !== null}
        onToggleAuth={() => setAuthMode(m => (m ? null : 'login'))}
        onSignOut={signOut}
        theme={theme}
        onToggleTheme={toggleTheme}
      />

      <header className="masthead">
        <h1>Gratitude Journal</h1>
        <p>What are you grateful for today?</p>
      </header>

      {/* Announced to screen readers without stealing focus. */}
      <div role="status" aria-live="polite">
        {toast && <div className="toast">{toast}</div>}
      </div>

      {authMode && !signedIn && (
        <AuthPanel
          mode={authMode}
          setMode={setAuthMode}
          onSubmit={signIn}
          onCancel={() => setAuthMode(null)}
          pendingCount={entries.length}
        />
      )}

      <EntryComposer onSave={journal.addEntry} />

      {entries.length === 0 && (
        <p className="empty">Your first entry will appear here.</p>
      )}

      <HistoryControls
        count={entries.length}
        showing={showHistory}
        onToggle={() => setShowHistory(v => !v)}
        onExport={journal.exportEntries}
        onImport={journal.importFile}
      />

      {entries.length > 0 && showHistory && (
        <History entries={entries} onDelete={journal.removeEntry} />
      )}

      {!signedIn && (
        <GuestNote
          carriedOver={carriedOver}
          onCreateAccount={() => setAuthMode('register')}
        />
      )}
    </div>
  );
}

ReactDOM.createRoot(document.getElementById('root')).render(<GratitudeJournal />);
