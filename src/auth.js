import crypto from 'node:crypto';
import { users, sessions } from './db.js';

const COOKIE = 'gj_session';
const SESSION_TTL_MS = 30 * 24 * 60 * 60 * 1000; // 30 days
const SECURE_COOKIE = process.env.COOKIE_SECURE === 'true';

/* ─── Password hashing ─────────────────────────────────────────────
   scrypt ships with Node, so there's no native bcrypt/argon2 build to
   fight with in Docker. Format: scrypt$N$r$p$salt$hash (all base64).  */
const SCRYPT = { N: 16384, r: 8, p: 1, keylen: 64 };

export function hashPassword(password) {
  const salt = crypto.randomBytes(16);
  const key = crypto.scryptSync(password, salt, SCRYPT.keylen, {
    N: SCRYPT.N, r: SCRYPT.r, p: SCRYPT.p, maxmem: 64 * 1024 * 1024,
  });
  return [
    'scrypt', SCRYPT.N, SCRYPT.r, SCRYPT.p,
    salt.toString('base64'), key.toString('base64'),
  ].join('$');
}

export function verifyPassword(password, stored) {
  try {
    const [scheme, N, r, p, saltB64, hashB64] = stored.split('$');
    if (scheme !== 'scrypt') return false;

    const expected = Buffer.from(hashB64, 'base64');
    const actual = crypto.scryptSync(
      password, Buffer.from(saltB64, 'base64'), expected.length,
      { N: +N, r: +r, p: +p, maxmem: 64 * 1024 * 1024 }
    );
    return crypto.timingSafeEqual(expected, actual);
  } catch {
    return false;
  }
}

/* ─── Cookies ──────────────────────────────────────────────────────
   Hand-parsed to keep the dependency list at two packages.           */
function readCookie(req, name) {
  const header = req.headers.cookie;
  if (!header) return null;

  for (const part of header.split(';')) {
    const eq = part.indexOf('=');
    if (eq === -1) continue;
    if (part.slice(0, eq).trim() === name) {
      return decodeURIComponent(part.slice(eq + 1).trim());
    }
  }
  return null;
}

function setSessionCookie(res, id, maxAgeMs) {
  const bits = [
    `${COOKIE}=${id}`,
    'Path=/',
    'HttpOnly',
    'SameSite=Lax', // blocks the cross-site POSTs that CSRF relies on
    `Max-Age=${Math.floor(maxAgeMs / 1000)}`,
  ];
  if (SECURE_COOKIE) bits.push('Secure');
  res.setHeader('Set-Cookie', bits.join('; '));
}

function clearSessionCookie(res) {
  res.setHeader(
    'Set-Cookie',
    `${COOKIE}=; Path=/; HttpOnly; SameSite=Lax; Max-Age=0`
  );
}

/* ─── Sessions ─────────────────────────────────────────────────── */
export function startSession(res, userId) {
  const id = crypto.randomBytes(32).toString('base64url');
  sessions.create(id, userId, Date.now() + SESSION_TTL_MS);
  setSessionCookie(res, id, SESSION_TTL_MS);
  return id;
}

export function endSession(req, res) {
  const id = readCookie(req, COOKIE);
  if (id) sessions.destroy(id);
  clearSessionCookie(res);
}

/** Attaches req.user when the cookie maps to a live session. Never rejects —
 *  guests are a first-class state here, not an error. */
export function attachUser(req, _res, next) {
  req.user = null;
  const id = readCookie(req, COOKIE);
  if (!id) return next();

  const session = sessions.find(id);
  if (!session) return next();

  if (session.expires_at < Date.now()) {
    sessions.destroy(id);
    return next();
  }

  req.user = users.findById(session.user_id) ?? null;
  next();
}

export function requireUser(req, res, next) {
  if (!req.user) return res.status(401).json({ error: 'Not signed in.' });
  next();
}

/* ─── Login throttling ─────────────────────────────────────────────
   In-memory and per-process — fine for a self-hosted single container.
   Behind multiple replicas this would need to live in the database.   */
const attempts = new Map();
const MAX_ATTEMPTS = 8;
const WINDOW_MS = 15 * 60 * 1000;

export function throttle(key) {
  const now = Date.now();
  const record = attempts.get(key);

  if (!record || now > record.resetAt) {
    attempts.set(key, { count: 1, resetAt: now + WINDOW_MS });
    return true;
  }
  record.count += 1;
  return record.count <= MAX_ATTEMPTS;
}

export const clearThrottle = key => attempts.delete(key);

/* Housekeeping: drop expired sessions and stale throttle records hourly. */
export function startJanitor() {
  const timer = setInterval(() => {
    sessions.prune();
    const now = Date.now();
    for (const [key, record] of attempts) {
      if (now > record.resetAt) attempts.delete(key);
    }
  }, 60 * 60 * 1000);

  timer.unref();
  return timer;
}
