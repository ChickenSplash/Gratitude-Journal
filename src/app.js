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

  app.use(express.static(PUBLIC_DIR, {
    extensions: ['html'],
    maxAge: process.env.NODE_ENV === 'production' ? '1h' : 0,
    setHeaders(res, filePath) {
      /* The HTML is the app shell — caching it for an hour would leave people
         on a stale build after a deploy. Fingerprinted assets can still cache. */
      if (filePath.endsWith('.html')) res.setHeader('Cache-Control', 'no-cache');
    },
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
