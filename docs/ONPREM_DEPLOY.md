# On-Prem Deployment Guide

> **Audience**: Privasimu deployment engineers and the client's IT team. For installations where Privasimu runs in the **client's own datacenter** instead of the AWS-hosted SaaS — typical for banks under POJK 38/2016 strictest interpretation, or government clients who require air-gapped operation.

## When to use this

| Scenario | Use this guide? |
|---|---|
| Bank insists data + app stay on their infra | ✅ |
| Multifinance with on-prem DC + dedicated VLAN | ✅ |
| Government tenant with air-gapped network | ✅ |
| Regular SMB / startup / mid-market | ❌ Use SaaS instead |
| Client wants Privasimu-hosted but their own AWS account | ❌ See AWS BYO account in `PRODUCTION_DEPLOY.md` |

For the SaaS multi-tenant deployment in AWS, see `PRODUCTION_DEPLOY.md` instead.

## What's different from SaaS

| Aspect | SaaS (AWS) | On-Prem (this guide) |
|---|---|---|
| Hosting | Privasimu's AWS account | Client's datacenter |
| DB | RDS Postgres + RDS Tenant Cluster | Containerized Postgres × 2 (landlord + tenant) |
| Storage | AWS S3 | MinIO (S3-compatible, self-hosted) |
| Backup | Automated RDS snapshots | **Client's responsibility** — pg_dump + filesystem backup |
| Monitoring | CloudWatch | **Client's responsibility** — Prometheus/Grafana exporter recommended |
| HA | Multi-AZ failover | Configure Postgres streaming replication if HA required |
| Patching | Privasimu manages | **Joint** — app patches by Privasimu, OS by client |
| License | License-manager phone-home | Online or offline (air-gapped) mode |
| Multi-tenancy | One install hosts N tenants | Usually 1 install = 1 client (though can host multiple internal "departments" as tenants) |

## Prerequisites

Client must provide:

- [ ] Linux server (Ubuntu 22.04 LTS or RHEL 9 recommended)
- [ ] **Minimum hardware**: 4 vCPU, 16 GB RAM, 100 GB SSD (small deployment, < 50 internal departments as tenants)
- [ ] **Recommended hardware**: 8 vCPU, 32 GB RAM, 500 GB SSD (production)
- [ ] Docker 20.10+ + Docker Compose plugin
- [ ] DNS entry for the planned URL (`privasimu.bank-internal.local` or similar)
- [ ] (Optional but recommended) TLS certificate from internal CA for HTTPS
- [ ] Outbound internet access to `license.privasimu.com` for activation, OR an offline license token from Privasimu Sales

## Installation

### 1. Clone the repository

```bash
sudo mkdir -p /opt/privasimu
sudo chown $USER:$USER /opt/privasimu
cd /opt/privasimu
git clone https://github.com/pendtiumpraz/priva-back.git backend
git clone https://github.com/pendtiumpraz/priva-front.git frontend
```

> Air-gapped install: Privasimu Engineering ships a tarball release containing pre-built Docker images + the repos. Skip the git clone, untar instead.

### 2. Run the installer

```bash
cd /opt/privasimu
bash backend/scripts/install-onprem.sh
```

The wizard:

1. Verifies Docker is installed and running.
2. Creates `backend/.env.onprem` from the example template, populating random passwords for landlord DB / tenant DB / MinIO admin.
3. Generates `APP_KEY` for Laravel.
4. Builds and starts the docker-compose stack:
   - `landlord-db` — Postgres 16 for platform metadata
   - `tenant-db` — Postgres 16 for per-tenant databases
   - `redis` — cache + queue
   - `minio` — S3-compatible storage
   - `backend` — Laravel API
   - `queue-worker` — separate container for async jobs
   - `frontend` — Next.js
   - `nginx` — reverse proxy
5. Runs migrations + seeders on landlord DB.
6. Prompts for the root user's email + password, runs `php artisan root:create`.
7. Auto-registers the `tenant-db` container as a `DatabasePool` named "OnPrem Tenant Cluster".
8. Auto-registers MinIO as the default `StoragePool`.
9. Activates the license key (online or offline mode).

After completion the wizard prints access URLs and login instructions.

### 3. Verify

Login at the printed URL with the root user. Sidebar should show the `Superadmin` section with:
- Database Pools — verify "OnPrem Tenant Cluster" is listed, status active
- Storage Pools — verify "OnPrem MinIO Default" is the default
- Tenant Isolation — list of all tenants (only root org initially)
- Change Requests — empty queue

## Configuration

### Custom domain + TLS

Edit `backend/.env.onprem`:
```
APP_URL=https://privasimu.bank-internal.local
PUBLIC_API_URL=https://privasimu.bank-internal.local/api
```

Edit `backend/docker/nginx-onprem.conf` and uncomment the HTTPS server block. Mount cert paths:
```yaml
nginx:
  volumes:
    - ./nginx-onprem.conf:/etc/nginx/conf.d/default.conf:ro
    - /etc/ssl/certs/privasimu.crt:/etc/nginx/certs/fullchain.pem:ro
    - /etc/ssl/private/privasimu.key:/etc/nginx/certs/privkey.pem:ro
```

Restart: `docker compose -f backend/docker/docker-compose.onprem.yml up -d nginx`

### Air-gapped license activation

Set in `.env.onprem`:
```
LICENSE_MODE=offline
LICENSE_TOKEN_FILE=/etc/privasimu/license.token
```

Privasimu Sales provides the static token file. Mount it into the backend container:
```yaml
backend:
  volumes:
    - /etc/privasimu/license.token:/etc/privasimu/license.token:ro
```

Token is signed by Privasimu's license-manager private key; backend verifies on boot. Token has a hard expiry — request renewal before it lapses.

### Connecting to client's existing Postgres

If the client wants to use their existing Postgres cluster instead of the bundled `tenant-db` container:

1. Edit `backend/.env.onprem` — point provisioner credentials at their cluster:
   ```
   TENANT_DB_PROVISIONER_HOST=their-postgres.internal.local
   TENANT_DB_PROVISIONER_PORT=5432
   TENANT_DB_PROVISIONER_USER=privasimu_provisioner
   TENANT_DB_PROVISIONER_PASSWORD=<from their DBA>
   ```
2. Their DBA must create the provisioner user with `CREATEDB` + `CREATEROLE`:
   ```sql
   CREATE USER privasimu_provisioner WITH PASSWORD '...' CREATEDB CREATEROLE;
   ```
3. Comment out the `tenant-db` service in `docker-compose.onprem.yml`.
4. In Privasimu UI, edit the auto-registered `OnPrem Tenant Cluster` pool to point at their host instead of `tenant-db`.

Same applies for using their existing Postgres for the landlord DB — but typically the bundled `landlord-db` container is fine even for clients with strict separation requirements.

### BYOS (use client's existing S3 / MinIO)

If client has their own object storage:
1. `/platform-admin/storage-pools` → **Tambah Pool** with their endpoint + credentials
2. Click the star to mark it default
3. Disable or delete the auto-registered MinIO pool
4. Optionally remove the `minio` service from the docker-compose to free RAM

## Day-2 Operations (Client Responsibility)

### Backups

The bundled stack does NOT auto-backup. Set up a host cron:

```bash
# /etc/cron.daily/privasimu-backup
#!/bin/bash
DATE=$(date +%Y%m%d)
BACKUP_DIR=/opt/privasimu/backups
mkdir -p "$BACKUP_DIR/$DATE"

# Landlord
docker compose -f /opt/privasimu/backend/docker/docker-compose.onprem.yml exec -T landlord-db \
  pg_dump -U privasimu_landlord privasimu_landlord | gzip > "$BACKUP_DIR/$DATE/landlord.sql.gz"

# Each tenant DB (introspect from landlord)
docker compose -f /opt/privasimu/backend/docker/docker-compose.onprem.yml exec -T landlord-db \
  psql -U privasimu_landlord -d privasimu_landlord -t -c \
  "SELECT DISTINCT split_part(tenant_db_config::json->>'database', '\"', 4) FROM organizations WHERE tenant_db_state='isolated'" | \
while read DB; do
  [ -z "$DB" ] && continue
  docker compose -f /opt/privasimu/backend/docker/docker-compose.onprem.yml exec -T tenant-db \
    pg_dump -U privasimu_provisioner "$DB" | gzip > "$BACKUP_DIR/$DATE/tenant_$DB.sql.gz"
done

# MinIO bucket via mc
docker compose -f /opt/privasimu/backend/docker/docker-compose.onprem.yml exec -T minio \
  mc mirror local/privasimu /backups/minio/$DATE/

# Retention: keep 30 days
find "$BACKUP_DIR" -maxdepth 1 -type d -mtime +30 -exec rm -rf {} \;
```

Adjust to off-site backup target (NAS, tape, S3-on-prem appliance).

### Logs

Backend logs flow to `${BACKEND_LOGS_HOST_PATH}` (default `/opt/privasimu/logs`). Pipe into the client's SIEM (Splunk, QRadar, Wazuh, ELK):

- File path: `/opt/privasimu/logs/laravel.log`
- Format: structured JSON when `LOG_CHANNEL=stack` includes the `json` channel
- Audit log queries via API: `GET /api/audit-logs` (root only)

### Monitoring

Recommended setup:
- **Prometheus node_exporter** on the host (CPU/RAM/disk)
- **postgres_exporter** sidecar for both DBs (lag, connections, slow queries)
- **redis_exporter** for cache/queue depth
- **MinIO built-in Prometheus endpoint** for storage usage

Scrape into client's Grafana stack with the dashboards Privasimu publishes (request from Privasimu Engineering — JSON exports for the standard panels).

### Updating to a new Privasimu version

```bash
cd /opt/privasimu/backend && git fetch && git checkout <new-tag>
cd /opt/privasimu/frontend && git fetch && git checkout <new-tag>

# Rebuild and apply migrations
docker compose -f /opt/privasimu/backend/docker/docker-compose.onprem.yml up -d --build backend frontend
docker compose -f /opt/privasimu/backend/docker/docker-compose.onprem.yml exec backend \
  php artisan migrate --force
# Migrations on every isolated tenant DB:
docker compose -f /opt/privasimu/backend/docker/docker-compose.onprem.yml exec backend \
  php artisan tenants:migrate
```

Schedule during a maintenance window. Check Privasimu's release notes for breaking-change advisories before applying.

### Provisioning a new tenant

Same flow as SaaS:
1. Tenant onboards via signup form (or admin manual create)
2. Login as root → `/platform-admin/tenants` → find new tenant → **Aktifkan Isolation**
3. Pick the on-prem tenant cluster pool → confirm

Tenant DB created in seconds; migrations run; cutover atomic. The new tenant immediately operates on its dedicated DB.

## Sizing & Capacity Planning

Approximate scaling guidance:

| # of tenants | Suggested setup |
|---|---|
| 1-5 | Single host, default sizing (4 vCPU/16 GB) |
| 5-20 | 8 vCPU/32 GB, separate volumes for landlord-db and tenant-db |
| 20-50 | Move tenant-db to its own host with PgBouncer in front |
| 50+ | Reconsider: probably worth migrating to SaaS or a more robust HA setup |

Single Postgres instance can host hundreds of databases; the bottleneck usually appears in connection pool depth, not storage. Add PgBouncer if connection count climbs above 200.

## Troubleshooting

### Installer fails with "Docker not found"
Install Docker:
```bash
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker $USER
newgrp docker
```

### Containers start but backend can't connect to landlord-db
Check landlord-db logs:
```bash
docker compose -f backend/docker/docker-compose.onprem.yml logs landlord-db | tail -30
```
Common: password mismatch (`POSTGRES_PASSWORD` env not in sync with backend's `DB_PASSWORD`). Fix `.env.onprem` and restart.

### "License activation failed"
- Online mode: confirm host has internet to `license.privasimu.com:443`
- Offline mode: verify token file exists and is mounted into backend container
- Either: contact Privasimu Sales with your installation ID (`SELECT id FROM app_settings WHERE key='installation_id'` from landlord-db)

### Tenant provisioning stuck at "migrating" state
Check the queue worker:
```bash
docker compose -f backend/docker/docker-compose.onprem.yml logs queue-worker | tail -50
```
Look for `MigrateTenantDataJob` errors. Most common cause: the tenant DB already exists from a failed previous attempt — drop it manually:
```bash
docker compose -f backend/docker/docker-compose.onprem.yml exec tenant-db \
  psql -U privasimu_provisioner -c 'DROP DATABASE "privasimu_tenant_<uuid>"'
```
Then reset the tenant via UI: `/platform-admin/tenants` → failed tenant → **Reset**.

### MinIO bucket can't be reached from the app
The app uses `http://minio:9000` (Docker network) not `localhost:9000`. If you customized `MINIO_PORT`, the app config doesn't change — backend always uses internal hostname `minio:9000`. Don't edit the storage pool to use `localhost`.

### Update broke something
Roll back: checkout the previous tag in both repos, run migrations down only if Privasimu's release notes specify (some migrations are not safely reversible). Restore from backup if needed.

## Reference

- `backend/docker/docker-compose.onprem.yml` — full stack definition
- `backend/docker/nginx-onprem.conf` — reverse proxy config
- `backend/.env.onprem.example` — env template
- `backend/scripts/install-onprem.sh` — installer wizard
- `backend/docs/PRODUCTION_DEPLOY.md` — sister doc for AWS SaaS
- `BYODB.md` (repo root) — tenancy design rationale
