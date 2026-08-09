import path from 'node:path';
import { fileURLToPath } from 'node:url';
import express from 'express';
import { attachUser } from './auth.js';
import { api } from './routes.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const PUBLIC_DIR = path.join(__dirname, '..', 'public');

export function createApp() {
  const app = express();

  app.disable('x-powered-by');
  app.set('trust proxy', process.env.TRUST_PROXY === 'true'); // behind a reverse proxy

  /* Body parsing and the session lookup are scoped to /api: static assets
     shouldn't cost a database read each. */
  app.use('/api', express.json({ limit: '2mb' }), attachUser, api);

  app.get('/healthz', (_req, res) => res.json({ ok: true }));

  /* Nothing in public/ carries a content hash in its name, so none of it can
     be cached by URL: an hour-old journal-app.jsx against a fresh index.html
     is a broken page. `no-cache` still allows a conditional request, so the
     usual answer is a 304 with no body. Swap this for a long max-age once the
     build step in the README's "Dropping Babel" gives assets fingerprints. */
  app.use(express.static(PUBLIC_DIR, {
    extensions: ['html'],
    setHeaders: res => res.setHeader('Cache-Control', 'no-cache'),
  }));

  app.use((_req, res) => res.status(404).json({ error: 'Not found.' }));

  // Last line of defence: never leak a stack trace to the client.
  app.use((err, _req, res, _next) => {
    const status = Number(err?.status ?? err?.statusCode) || 500;

    // Malformed JSON and oversized bodies are the client's problem, not a bug.
    if (status >= 400 && status < 500) {
      return res.status(status).json({ error: 'That request could not be read.' });
    }

    console.error(err);
    res.status(500).json({ error: 'Something went wrong.' });
  });

  return app;
}
