# Deployment (Portainer)

Production and staging run as **Portainer BE Git stacks** built from this directory. GitHub Actions
redeploys them through the Portainer API and waits until the app reports the new version on
`/.well-known/browser-extension`. Decision: [ADR 0010](../../docs/adr/0010-portainer-deployments.md).

| | Production | Staging |
|---|---|---|
| Trigger | Stable (non-pre-release) GitHub release that is the **highest stable version tag**, after approval in the `production` environment. Maintenance releases of older lines only publish images | Every `develop` commit that passed CI (also manual: *Actions → Staging → Run workflow*) |
| Workflow | `publish.yml` → `deploy.yml` | `staging.yml` → `deploy.yml` |
| Images | `X.Y.Z` | `sha-<commit>` (and `develop`); version `X.Y.(Z+1)-dev.<commit>` |
| Compose files | `deploy/compose.yaml` + `deploy/compose.production.yaml` at `refs/tags/X.Y.Z` | `deploy/compose.yaml` + `deploy/compose.staging.yaml` at `refs/heads/develop` |
| Database | Production | Fresh copy of production on **every** deploy, without download jobs, files and queued messages |
| Access | Production OIDC application | Separate OIDC application, restricted to a group in the IdP |

## One-time setup

### 1. PostgreSQL
Staging uses its own database on the same server, plus a read-only role for copying production.

```sql
-- read-only access to production for the staging copy (PostgreSQL 14+)
CREATE ROLE fetchdock_prod_ro LOGIN PASSWORD '…';
GRANT CONNECT ON DATABASE <prod_db> TO fetchdock_prod_ro;
GRANT pg_read_all_data TO fetchdock_prod_ro;

-- staging owner: recreates its database on every deploy
CREATE ROLE fetchdock_staging LOGIN CREATEDB PASSWORD '…';
CREATE DATABASE fetchdock_staging OWNER fetchdock_staging;
```

`db-refresh` refuses to run when source and target are the same database.

### 2. IdP
Create a **separate OIDC application** for staging, with staging redirect URIs, and bind it to the group
that may use staging. The server checks the token audience (`OIDC_AUDIENCE`), so tokens from the
production application are rejected on staging.

### 3. Portainer stacks
Create both stacks with *Add stack → Repository*:
- Repository URL `https://github.com/FetchDock/fetchdock-server` (public, no authentication).
- Compose path `deploy/compose.yaml`, additional path `deploy/compose.production.yaml` or `deploy/compose.staging.yaml`.
- Reference: production `refs/tags/<current version>`, staging `refs/heads/develop`.
- **GitOps updates: off.** GitHub Actions decides when to deploy.
- Environment variables: see below. `DOCKER_TAG` is set by every deploy; give it an initial value.

Then remove the old production web-editor stack (stop it first; both publish the same port). Its named
volumes `caddy_data` and `caddy_config` only hold Caddy state and are recreated.

Note each stack's ID and the environment (endpoint) ID from the stack URL:
`…/#!/<endpointId>/docker/stacks/<name>?id=<stackId>`.

### 4. Reverse proxy (nginx-proxy-manager)
Point a host at `<docker host>:<HTTP_PORT>` for each stack with scheme **`http`**, with WebSockets support and
long timeouts for Mercure (Server-Sent Events). TLS ends at the proxy; the containers serve plain HTTP only
(Caddy listens on `:80` without automatic HTTPS), so `https://<docker host>:<HTTP_PORT>` does not work.

The API builds absolute URLs (e.g. the `Link` header to `/docs.jsonld` that the admin follows) from the
`X-Forwarded-Proto` header the proxy sends, if the proxy's address is in `TRUSTED_PROXIES`. If they come out
as `http://` behind HTTPS, the admin fails with a mixed-content error ("Application error: a client-side
exception has occurred").

**Behind Cloudflare:** set the SSL/TLS mode to **Full (strict)** (with a certificate on the proxy, e.g. a
Cloudflare origin certificate). In **Flexible** mode Cloudflare connects to the proxy over HTTP, and
nginx-proxy-manager then sends `X-Forwarded-Proto: http`. If you can't change the mode, add
`proxy_set_header X-Forwarded-Proto https;` to the host's *Advanced* configuration.

Check: `curl -sI https://<host>/ | grep -i '^link'` must show `https://<host>/docs.jsonld`.

### 5. GitHub
*Settings → Environments*, for `production` and `staging`:
- Secret `PORTAINER_API_KEY`: a Portainer access token (*My account → Access tokens*) of a user that can manage the stack.
- Variables `PORTAINER_URL`, `PORTAINER_STACK_ID`, `PORTAINER_ENDPOINT_ID`, `APP_URL` (public URL, e.g. `https://fetchdock.example.org`).
- `production`: add **required reviewers** and restrict deployment to tags (`*.*.*`).
- `staging`: restrict deployment to the `develop` branch.

## Stack environment variables

| Variable | Notes |
|---|---|
| `DOCKER_TAG` | Image tag. Overwritten by every deploy |
| `HTTP_PORT` | Host port for the reverse proxy, e.g. `4080` (production), `4180` (staging) |
| `SERVER_NAME` | Public host name only, e.g. `staging.fetchdock.dev` (no scheme or port). Used for the Mercure URL and trusted hosts; Caddy itself always serves `:80` |
| `CADDY_MERCURE_JWT_SECRET` | **Different** per stack |
| `CADDY_MERCURE_SUBSCRIBER_JWT_SECRET` | Optional separate key for subscriber tokens, so a leaked subscriber key can't publish. Defaults to `CADDY_MERCURE_JWT_SECRET` |
| `CADDY_MERCURE_PUBLIC_URL` | Optional, defaults to `https://${SERVER_NAME}/.well-known/mercure` |
| `TRUSTED_HOSTS` | Optional, defaults to `^(${SERVER_NAME}\|php)$`. Requests by IP (e.g. `http://10.1.4.3:4180`) get a 400 unless you add the IP here |
| `TRUSTED_PROXIES`, `CORS_ALLOW_ORIGIN` | As before. `TRUSTED_PROXIES` must include the reverse proxy |
| `POSTGRES_HOST`, `POSTGRES_PORT`, `POSTGRES_USER`, `POSTGRES_PASSWORD`, `POSTGRES_DB` | Staging: the staging role and database |
| `POSTGRES_VERSION` | Server **major** version, e.g. `17` (default `16`). Used for Doctrine's `serverVersion` and, on staging, for the `db-refresh` image: `pg_dump` must match the server |
| `OIDC_*`, `NEXT_PUBLIC_OIDC_*` | Staging: the staging OIDC application. The `NEXT_PUBLIC_OIDC_*` variables are set on the `pwa` service and read at request time, so the published image needs no rebuild |
| `GALLERY_DL_CONFIG` | Host path of the gallery-dl `config.json` |
| `DOWNLOADS_PATH` | Host path mounted at `/downloads`. Staging: a **separate** directory |
| `DOWNLOAD_ROOTS` | Optional, comma-separated. Download directories (admin: *Download directories*) must be inside these paths. Defaults to `/downloads,/mnt` in production and `/downloads` elsewhere |
| `WORKER_REPLICAS` | Default `10`; e.g. `2` on staging |
| `SOURCE_POSTGRES_HOST`, `SOURCE_POSTGRES_PORT` | Staging only; default to the staging server |
| `SOURCE_POSTGRES_USER`, `SOURCE_POSTGRES_PASSWORD`, `SOURCE_POSTGRES_DB` | Staging only: the read-only production role |

Production also mounts `/mnt`, because download destinations may point there. Staging must not: the
copied settings may point at production paths. Downloads that target such paths fail on staging.

Download directories are mounted POSIX paths (an S3 bucket through a FUSE or CSI driver, for example). The `php`
and `worker` services need **the same mounts**: the api checks a directory when an admin adds it, and the worker
writes into it. A job fails with "not mounted or not writable" when the worker can't reach its directory.
`db-refresh` resets staging to its single default directory, `/downloads`.

Workers consume `async file-moves scheduler_default`. Downloads come first. File moves (admin: *File moves*)
follow, one message per file, and the scheduler runs `app:file-moves:recover` hourly. The recovery command resumes
moves that a stopped worker left behind. Workers restart after an hour (`--time-limit`) or 100 messages. Moves to
slow storage can take hours: follow them in the admin, and cancel them there if needed.

## Operations
- **Maintenance releases:** a stable release whose tag isn't the highest stable semver tag (e.g. 0.7.5 after 0.8.0) publishes its images, but the *Check production eligibility* job skips the deploy and notes it in the run summary.
- **Roll back production:** re-run the *Production* job of an older release's *Publish Docker images* run,
  or redeploy the stack in Portainer at the older tag with that `DOCKER_TAG`. Migrations are not rolled back.
- **Refresh staging without a new commit:** *Actions → Staging → Run workflow*.
- **Staging deploy failed in `db-refresh`:** check the `db-refresh` container log in Portainer; the app
  containers stay on the previous version until it succeeds.
