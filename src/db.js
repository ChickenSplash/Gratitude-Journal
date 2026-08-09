import fs from 'node:fs';
import path from 'node:path';
import Database from 'better-sqlite3';

const DATA_DIR = process.env.DATA_DIR ?? path.join(process.cwd(), 'data');
fs.mkdirSync(DATA_DIR, { recursive: true });

export const db = new Database(path.join(DATA_DIR, 'journal.sqlite'));

/* WAL lets reads carry on during writes; the rest are sensible defaults for a
   long-lived server process rather than a one-shot CLI. */
db.pragma('journal_mode = WAL');
db.pragma('synchronous = NORMAL');
db.pragma('foreign_keys = ON');
db.pragma('busy_timeout = 5000');

/* ─── Schema ───────────────────────────────────────────────────────
   Run on every boot. CREATE ... IF NOT EXISTS makes this idempotent,
   which is all the "migration system" a single-table-ish app needs.  */
db.exec(`
  CREATE TABLE IF NOT EXISTS users (
    id            TEXT PRIMARY KEY,
    username      TEXT NOT NULL UNIQUE COLLATE NOCASE,
    password_hash TEXT NOT NULL,
    created_at    TEXT NOT NULL DEFAULT (datetime('now'))
  );

  CREATE TABLE IF NOT EXISTS sessions (
    id         TEXT PRIMARY KEY,
    user_id    TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    expires_at INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  );

  CREATE INDEX IF NOT EXISTS idx_sessions_user ON sessions(user_id);

  -- public_id is the id the browser sees and the one that lands in an export
  -- file. It is unique per account rather than globally, so two people can
  -- import the same export without one of them colliding with the other.
  CREATE TABLE IF NOT EXISTS entries (
    id         INTEGER PRIMARY KEY,
    user_id    TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    public_id  TEXT NOT NULL,
    entry_date TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  );

  CREATE INDEX IF NOT EXISTS idx_entries_user_date
    ON entries(user_id, entry_date DESC);

  -- Makes import idempotent: re-importing the same file is a no-op rather
  -- than a second copy of every entry.
  CREATE UNIQUE INDEX IF NOT EXISTS idx_entries_user_public
    ON entries(user_id, public_id);

  -- Normalised rather than a JSON blob, so the three lines of an entry are
  -- queryable (search, streaks, word counts) later on.
  CREATE TABLE IF NOT EXISTS entry_items (
    entry_id INTEGER NOT NULL REFERENCES entries(id) ON DELETE CASCADE,
    position INTEGER NOT NULL,
    body     TEXT NOT NULL,
    PRIMARY KEY (entry_id, position)
  );
`);

/* ─── Prepared statements ─────────────────────────────────────────
   Prepared once at boot; better-sqlite3 is synchronous, so these are
   plain function calls with no await in sight.                     */
const q = {
  insertUser: db.prepare(
    `INSERT INTO users (id, username, password_hash) VALUES (?, ?, ?)`
  ),
  userByName: db.prepare(`SELECT * FROM users WHERE username = ?`),
  userById: db.prepare(`SELECT id, username, created_at FROM users WHERE id = ?`),

  insertSession: db.prepare(
    `INSERT INTO sessions (id, user_id, expires_at) VALUES (?, ?, ?)`
  ),
  sessionById: db.prepare(`SELECT * FROM sessions WHERE id = ?`),
  deleteSession: db.prepare(`DELETE FROM sessions WHERE id = ?`),
  pruneSessions: db.prepare(`DELETE FROM sessions WHERE expires_at < ?`),

  insertEntry: db.prepare(
    `INSERT INTO entries (user_id, public_id, entry_date) VALUES (?, ?, ?)`
  ),
  /* Import re-runs happily: a public_id this account already holds is left
     alone instead of raising a constraint error. */
  insertEntryIfNew: db.prepare(`
    INSERT INTO entries (user_id, public_id, entry_date) VALUES (?, ?, ?)
    ON CONFLICT (user_id, public_id) DO NOTHING
  `),
  insertItem: db.prepare(
    `INSERT INTO entry_items (entry_id, position, body) VALUES (?, ?, ?)`
  ),
  entriesForUser: db.prepare(`
    SELECT e.public_id AS id, e.entry_date AS date, ei.body
    FROM entries e
    JOIN entry_items ei ON ei.entry_id = e.id
    WHERE e.user_id = ?
    ORDER BY e.entry_date DESC, e.id DESC, ei.position ASC
  `),
  deleteEntry: db.prepare(
    `DELETE FROM entries WHERE public_id = ? AND user_id = ?`
  ),
};

export const users = {
  create(id, username, passwordHash) {
    q.insertUser.run(id, username, passwordHash);
    return { id, username };
  },
  findByUsername: username => q.userByName.get(username),
  findById: id => q.userById.get(id),
};

export const sessions = {
  create: (id, userId, expiresAt) => q.insertSession.run(id, userId, expiresAt),
  find: id => q.sessionById.get(id),
  destroy: id => q.deleteSession.run(id),
  prune: () => q.pruneSessions.run(Date.now()),
};

/** Writes the lines of an entry that was just inserted at `rowId`. */
function writeItems(rowId, items) {
  items.forEach((body, i) => q.insertItem.run(rowId, i, body));
}

export const entries = {
  /** Flat join rows -> [{ id, date, items: [...] }] in one pass. */
  listForUser(userId) {
    const grouped = [];
    let current = null;

    for (const row of q.entriesForUser.all(userId)) {
      if (!current || current.id !== row.id) {
        current = { id: row.id, date: row.date, items: [] };
        grouped.push(current);
      }
      current.items.push(row.body);
    }
    return grouped;
  },

  /** One transaction per entry: an entry with no items must never exist. */
  create: db.transaction((entry, userId) => {
    const { lastInsertRowid } = q.insertEntry.run(userId, entry.id, entry.date);
    writeItems(lastInsertRowid, entry.items);
    return entry;
  }),

  /** Bulk insert used by import and by guest -> account migration.
   *  Entries this account already holds are skipped, so re-importing the
   *  same export file adds nothing. */
  createMany: db.transaction((list, userId) => {
    const added = [];

    for (const entry of list) {
      const result = q.insertEntryIfNew.run(userId, entry.id, entry.date);
      if (result.changes === 0) continue; // already in this account

      writeItems(result.lastInsertRowid, entry.items);
      added.push(entry);
    }
    return added;
  }),

  /** Scoped by user_id so one account can't delete another's rows. */
  destroy: (id, userId) => q.deleteEntry.run(id, userId).changes > 0,
};
