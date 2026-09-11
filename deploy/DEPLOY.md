# VRemote — Production deploy (vremote.vplus.vn)

CI/CD: **manual** deploy from `main` via `.github/workflows/deploy-production.yml`
(Actions → *Deploy Production* → Run workflow → branch `main` → confirm `deploy`).
Atomic **releases / current / shared** layout over SSH (port `22022`).

## Serving model

| Path | Serves | Backend |
|---|---|---|
| `/` | Console SPA (static) | nginx → `current/console/dist` |
| `/api/...` | Laravel backend | nginx → `127.0.0.1:8080` (internal nginx + PHP-FPM) |
| `/signaling`, `/agent` | Signaling WebSocket (control only) | nginx → `127.0.0.1:8787` |
| `/downloads/...` | Signed Android APK | nginx → `shared/downloads` |

Frontend is served **directly from the domain root** (like the customer web); backend lives under the `/api` sub-path. The signaling internal port `127.0.0.1:8788` is never exposed. Platform identity/SSO is the **shared** platform service (same instance as `cab.sab.com.vn`) via `VREMOTE_PLATFORM_URL` — not deployed here.

## Release layout on the server

```text
/var/www/vremote.vplus.vn/            # DEPLOY_PATH
├── releases/
│   └── 20260911xxxxxx-<sha>/         # backend/ console/dist signaling/{dist,node_modules}
├── current -> releases/<latest>      # atomic symlink flipped at the end of a deploy
└── shared/                           # persistent, survives releases
    ├── .env                          # backend env  (from backend/.env.example)
    ├── signaling.env                 # signaling env (from signaling/.env.example)
    ├── backend-storage/              # Laravel storage (logs, framework cache)
    └── downloads/                    # vremote-agent.apk
```

The deploy symlinks `shared/.env` → `current/backend/.env` and `shared/backend-storage` → `current/backend/storage` on every release, then flips `current`.

## Database

MongoDB. Database **`vremote`**, user **`vremote_user`** (auth source `vremote`).

```javascript
// mongosh "mongodb://127.0.0.1:<MONGODB_PORT>"
use vremote
db.createUser({
  user: "vremote_user",
  pwd:  "<DB_PASSWORD>",
  roles: [ { role: "readWrite", db: "vremote" }, { role: "dbAdmin", db: "vremote" } ]
})
```

Backend reads `MONGODB_HOST/PORT/DATABASE/USERNAME/PASSWORD/AUTHENTICATION_DATABASE`
(see `backend/.env.example`; `${SERVICES}` = `vremote`).

## GitHub Actions — secrets & variables

**Secrets** (Settings → Secrets and variables → Actions → Secrets):

| Secret | Purpose |
|---|---|
| `SERVER_PROD_SSH_KEY` | Private SSH key of the deploy user (PEM) |
| `COMPOSER_TOKEN` | GitHub token for private `vplus-platform/core-lib` |
| `SERVER_PROD_KNOWN_HOSTS` | *(optional)* pinned host key; otherwise `ssh-keyscan` is used |

**Variables** (override the defaults baked into the workflow):

| Variable | Default | Purpose |
|---|---|---|
| `SERVER_PROD_HOST` | `vremote.vplus.vn` | SSH host |
| `SERVER_PROD_USER` | `deploy` | SSH/deploy user |
| `SERVER_PROD_PORT` | `22022` | SSH port |
| `DEPLOY_PATH` | `/var/www/vremote.vplus.vn` | Deploy root |

App secrets (session/signaling/TURN secrets, DB password, VPlus keys) live **only** in `shared/.env` on the server — never in GitHub or Git.

## Server prerequisites (one-time)

1. Packages: `nginx`, `php8.3-fpm` + `php8.3-mongodb`, Node.js (for signaling), MongoDB (or reachable), `coturn`, `rsync`.
2. Deploy user (e.g. `deploy`) + a `vremote` system user for the signaling service:
   ```bash
   sudo adduser --system --group --no-create-home vremote
   ```
3. Directory + ownership:
   ```bash
   sudo mkdir -p /var/www/vremote.vplus.vn/{releases,shared/downloads,shared/backend-storage}
   sudo chown -R deploy:deploy /var/www/vremote.vplus.vn
   ```
4. Create `shared/.env` from `backend/.env.example` and `shared/signaling.env` from `signaling/.env.example` (fill real secrets; matching `VREMOTE_SESSION_SECRET` and `VREMOTE_SIGNALING_INTERNAL_SECRET` on both).
5. nginx: install `deploy/nginx/vremote.vplus.vn.conf`, provision TLS (certbot), enable the site.
6. systemd: install `deploy/systemd/vremote-signaling.service`, then `sudo systemctl enable --now vremote-signaling`.
7. Grant the deploy user NOPASSWD sudo for exactly the reload commands used by the workflow:
   ```
   # /etc/sudoers.d/vremote-deploy
   deploy ALL=(root) NOPASSWD: /usr/bin/systemctl restart vremote-signaling, \
     /usr/bin/systemctl reload php8.3-fpm, /usr/bin/systemctl restart php8.3-fpm, \
     /usr/bin/systemctl reload nginx
   ```
8. DNS `A`/`AAAA` for `vremote.vplus.vn` → server; open `443` and TURN `3478`.

## Deploy

1. Merge to `main`.
2. Actions → **Deploy Production** → *Run workflow* → branch `main` → `confirm = deploy`.
3. The workflow builds backend/console/signaling, uploads a new release, links shared state, runs `migrate --force`, flips `current`, restarts signaling, reloads php-fpm + nginx, and checks `GET /api/health` returns `200`.

Rollback: point `current` at the previous release and reload:
```bash
ln -sfn /var/www/vremote.vplus.vn/releases/<previous> /var/www/vremote.vplus.vn/current
sudo systemctl restart vremote-signaling && sudo systemctl reload php8.3-fpm nginx
```

## Notes

- `config:cache` is deliberately **not** enabled yet: request-path `env()` reads (VPlus keys, TURN secret) must move into config first — DSG-VREMOTE-002 §4. The deploy runs `optimize:clear`.
- `php artisan migrate` is a no-op until the v0.2.0 MongoDB migrations land; the step is tolerant (`|| true`) meanwhile.
- Android agent WSS: `wss://vremote.vplus.vn/agent`. Place the signed APK at `shared/downloads/vremote-agent.apk`.
