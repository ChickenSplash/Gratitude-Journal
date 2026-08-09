import crypto from 'node:crypto';
import { Router } from 'express';
import { users, entries } from './db.js';
import {
  hashPassword, verifyPassword, startSession, endSession,
  requireUser, throttle, clearThrottle,
} from './auth.js';

export const api = Router();

const MAX_ITEMS = 10;
const MAX_ITEM_LENGTH = 2000;
const MAX_BULK = 1000;

/* ─── Validation ─────────────────────────────────────────────────── */
function validateCredentials({ username, password }) {
  if (typeof username !== 'string' || typeof password !== 'string') {
    return 'Username and password are required.';
  }
  const name = username.trim();

  if (name.length < 3 || name.length > 32) {
    return 'Username must be 3–32 characters.';
  }
  if (!/^[a-zA-Z0-9._-]+$/.test(name)) {
    return 'Username can use letters, numbers, dots, dashes and underscores.';
  }
  if (password.length < 8 || password.length > 200) {
    return 'Password must be at least 8 characters.';
  }
  return null;
}

/** Shapes untrusted client JSON into an entry, or returns null. */
function cleanEntry(raw) {
  if (!raw || typeof raw !== 'object' || !Array.isArray(raw.items)) return null;

  const items = raw.items
    .filter(i => typeof i === 'string' && i.trim() !== '')
    .slice(0, MAX_ITEMS)
    .map(i => i.trim().slice(0, MAX_ITEM_LENGTH));

  if (items.length === 0) return null;

  const date = typeof raw.date === 'string' && !Number.isNaN(Date.parse(raw.date))
    ? new Date(raw.date).toISOString()
    : new Date().toISOString();

  /* The client's id is kept when it looks sane, so an export file re-imported
     later lands on the rows it already created rather than duplicating them.
     It is only ever unique within one account — see the schema in db.js. */
  const id = typeof raw.id === 'string' && raw.id.length > 0 && raw.id.length <= 64
    ? raw.id
    : crypto.randomUUID();

  return { id, date, items };
}

const publicUser = user => ({ id: user.id, username: user.username });

const isUniqueViolation = err =>
  typeof err?.code === 'string' && err.code.startsWith('SQLITE_CONSTRAINT');

/* ─── Auth ───────────────────────────────────────────────────────── */
api.get('/auth/me', (req, res) => {
  res.json({ user: req.user ? publicUser(req.user) : null });
});

api.post('/auth/register', (req, res) => {
  const problem = validateCredentials(req.body ?? {});
  if (problem) return res.status(400).json({ error: problem });

  const username = req.body.username.trim();

  if (users.findByUsername(username)) {
    return res.status(409).json({ error: 'That username is taken.' });
  }

  let user;
  try {
    user = users.create(
      crypto.randomUUID(), username, hashPassword(req.body.password)
    );
  } catch (err) {
    // Two registrations for the same name can race past the check above.
    if (isUniqueViolation(err)) {
      return res.status(409).json({ error: 'That username is taken.' });
    }
    throw err;
  }

  startSession(res, user.id);
  res.status(201).json({ user: publicUser(user) });
});

api.post('/auth/login', (req, res) => {
  const { username, password } = req.body ?? {};

  if (typeof username !== 'string' || typeof password !== 'string') {
    return res.status(400).json({ error: 'Username and password are required.' });
  }

  const key = `${req.ip}:${username.toLowerCase()}`;
  if (!throttle(key)) {
    return res.status(429).json({ error: 'Too many attempts. Try again later.' });
  }

  const user = users.findByUsername(username.trim());

  // Same message either way, so this can't be used to enumerate usernames.
  if (!user || !verifyPassword(password, user.password_hash)) {
    return res.status(401).json({ error: 'Wrong username or password.' });
  }

  clearThrottle(key);
  startSession(res, user.id);
  res.json({ user: publicUser(user) });
});

api.post('/auth/logout', (req, res) => {
  endSession(req, res);
  res.json({ ok: true });
});

/* ─── Entries (signed-in only) ───────────────────────────────────── */
api.get('/entries', requireUser, (req, res) => {
  res.json({ entries: entries.listForUser(req.user.id) });
});

api.post('/entries', requireUser, (req, res) => {
  const entry = cleanEntry(req.body ?? {});
  if (!entry) return res.status(400).json({ error: 'An entry needs at least one line.' });

  res.status(201).json({ entry: entries.create(entry, req.user.id) });
});

/** Bulk insert for file imports and for adopting entries written as a guest.
 *  Ids this account already holds are skipped, so re-importing the same file
 *  is harmless. */
api.post('/entries/bulk', requireUser, (req, res) => {
  const incoming = Array.isArray(req.body?.entries) ? req.body.entries : null;
  if (!incoming) return res.status(400).json({ error: 'Expected a list of entries.' });

  const cleaned = incoming.slice(0, MAX_BULK).map(cleanEntry).filter(Boolean);
  const added = entries.createMany(cleaned, req.user.id);

  res.json({ added: added.length, entries: entries.listForUser(req.user.id) });
});

api.delete('/entries/:id', requireUser, (req, res) => {
  if (!entries.destroy(req.params.id, req.user.id)) {
    return res.status(404).json({ error: 'Entry not found.' });
  }
  res.json({ ok: true });
});
