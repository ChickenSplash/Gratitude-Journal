# ─── Build stage: compile better-sqlite3's native bits ────────────
FROM node:22-bookworm-slim AS deps

RUN apt-get update \
 && apt-get install -y --no-install-recommends python3 make g++ \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --omit=dev

# ─── Runtime stage: no compilers in the final image ───────────────
FROM node:22-bookworm-slim

ENV NODE_ENV=production \
    PORT=3000 \
    DATA_DIR=/data

WORKDIR /app

COPY --from=deps /app/node_modules ./node_modules
COPY package.json server.js ./
COPY src ./src
COPY public ./public

# DATA_DIR is expected to be a mount from the host (see docker-compose.yml), so
# the SQLite file survives image rebuilds. Deliberately no VOLUME instruction:
# it would make a bare `docker run` spawn a throwaway anonymous volume here,
# quietly writing the journal somewhere nobody goes looking.
RUN mkdir -p /data && chown -R node:node /data /app

USER node
EXPOSE 3000

HEALTHCHECK --interval=30s --timeout=3s --start-period=5s \
  CMD node -e "fetch('http://127.0.0.1:3000/healthz').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))"

CMD ["node", "server.js"]
