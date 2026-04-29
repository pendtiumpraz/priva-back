#!/usr/bin/env bash
# =============================================================================
# PRIVASIMU NEXUS — On-Prem Installer
# =============================================================================
# First-run setup wizard for on-prem deployments. Bootstraps the docker-compose
# stack defined in backend/docker/docker-compose.onprem.yml, runs migrations,
# creates the root user, and registers a default DatabasePool pointing at the
# tenant-db container so superadmin can immediately provision FI tenants.
#
# Idempotent: safe to re-run; will skip steps that already succeeded.
#
# Usage:
#   cd /opt/privasimu
#   bash backend/scripts/install-onprem.sh

set -euo pipefail

# Colors
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; NC='\033[0m'

log()  { echo -e "${BLUE}[install]${NC} $1"; }
ok()   { echo -e "${GREEN}[ ok ]${NC} $1"; }
warn() { echo -e "${YELLOW}[warn]${NC} $1"; }
err()  { echo -e "${RED}[err ]${NC} $1" >&2; }

# Locate repo root by climbing from this script
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(dirname "$SCRIPT_DIR")"
REPO_ROOT="$(dirname "$BACKEND_DIR")"
COMPOSE_FILE="$BACKEND_DIR/docker/docker-compose.onprem.yml"
ENV_EXAMPLE="$BACKEND_DIR/.env.onprem.example"
ENV_FILE="$BACKEND_DIR/.env.onprem"

# ─── Pre-flight checks ──────────────────────────────────────────────────────
log "Checking dependencies..."
for cmd in docker; do
  if ! command -v "$cmd" >/dev/null 2>&1; then
    err "$cmd not found. Install Docker first."
    exit 1
  fi
done

if ! docker compose version >/dev/null 2>&1; then
  err "docker compose plugin not available (need Docker 20.10+)."
  exit 1
fi

ok "Docker $(docker --version | awk '{print $3}' | tr -d ',') ready"

# ─── .env.onprem setup ──────────────────────────────────────────────────────
if [[ ! -f "$ENV_FILE" ]]; then
  log "Creating .env.onprem from example..."
  cp "$ENV_EXAMPLE" "$ENV_FILE"

  # Generate APP_KEY
  APP_KEY="base64:$(openssl rand -base64 32)"
  if grep -q '^APP_KEY=$' "$ENV_FILE"; then
    sed -i.bak "s|^APP_KEY=$|APP_KEY=${APP_KEY}|" "$ENV_FILE" && rm "${ENV_FILE}.bak"
  fi

  # Generate random passwords for any required REQUIRED slots that are still empty
  for var in LANDLORD_DB_PASSWORD TENANT_DB_PROVISIONER_PASSWORD MINIO_ROOT_PASSWORD; do
    if grep -q "^${var}=$" "$ENV_FILE"; then
      pwd_val="$(openssl rand -base64 24 | tr -d '/+=' | head -c 32)"
      sed -i.bak "s|^${var}=$|${var}=${pwd_val}|" "$ENV_FILE" && rm "${ENV_FILE}.bak"
    fi
  done

  ok "Generated .env.onprem with random passwords + APP_KEY"
  warn "Review $ENV_FILE before continuing — adjust APP_URL, ports, license keys, etc."
  read -p "Continue with these defaults? [y/N] " -n 1 -r REPLY
  echo
  if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    log "Edit $ENV_FILE then re-run this script."
    exit 0
  fi
else
  ok ".env.onprem exists"
fi

# Validate required vars are non-empty
log "Validating required env vars..."
set -a; source "$ENV_FILE"; set +a
for var in APP_KEY LANDLORD_DB_PASSWORD TENANT_DB_PROVISIONER_PASSWORD MINIO_ROOT_PASSWORD; do
  if [[ -z "${!var:-}" ]]; then
    err "$var is empty. Set it in $ENV_FILE then re-run."
    exit 1
  fi
done
ok "Required env vars present"

# ─── Bring up stack ─────────────────────────────────────────────────────────
log "Building and starting Docker stack..."
docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" up -d --build

log "Waiting for landlord DB to be healthy..."
for i in {1..30}; do
  if docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" ps landlord-db | grep -q healthy; then
    ok "Landlord DB ready"
    break
  fi
  sleep 2
  if [[ $i -eq 30 ]]; then
    err "Landlord DB did not become healthy in 60s. Check 'docker compose logs landlord-db'."
    exit 1
  fi
done

log "Waiting for tenant DB to be healthy..."
for i in {1..30}; do
  if docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" ps tenant-db | grep -q healthy; then
    ok "Tenant DB ready"
    break
  fi
  sleep 2
  if [[ $i -eq 30 ]]; then
    err "Tenant DB did not become healthy. Check 'docker compose logs tenant-db'."
    exit 1
  fi
done

# ─── Run migrations on landlord DB ──────────────────────────────────────────
log "Running landlord DB migrations + seeders..."
docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" exec -T backend php artisan migrate --force --no-interaction
docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" exec -T backend php artisan db:seed --force --no-interaction
ok "Landlord migrated + seeded"

# ─── Storage symlink ───────────────────────────────────────────────────────
log "Creating storage symlink..."
docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" exec -T backend php artisan storage:link 2>&1 | tail -1 || true
ok "Storage linked"

# ─── Root user ─────────────────────────────────────────────────────────────
log "Checking for root user..."
ROOT_EXISTS=$(docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" exec -T backend php artisan tinker --execute="echo App\\Models\\User::where('role','root')->count();" 2>/dev/null | tail -1 | tr -d '\r\n ')
if [[ "$ROOT_EXISTS" == "0" ]]; then
  warn "No root user. Create one now:"
  read -p "  Email: " ROOT_EMAIL
  read -s -p "  Password (min 12 chars): " ROOT_PASSWORD; echo
  read -p "  Display name [Platform Owner]: " ROOT_NAME
  ROOT_NAME="${ROOT_NAME:-Platform Owner}"

  docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" exec -T backend \
    php artisan root:create "$ROOT_EMAIL" "$ROOT_PASSWORD" --name="$ROOT_NAME"
  ok "Root user '$ROOT_EMAIL' created"
else
  ok "Root user already exists (skipping)"
fi

# ─── Register default DatabasePool pointing at tenant-db ────────────────────
log "Registering default tenant database pool..."
POOL_EXISTS=$(docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" exec -T backend \
  php artisan tinker --execute="echo App\\Models\\DatabasePool::where('name','OnPrem Tenant Cluster')->count();" 2>/dev/null | tail -1 | tr -d '\r\n ')

if [[ "$POOL_EXISTS" == "0" ]]; then
  docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" exec -T backend php artisan tinker --execute="
\$pool = new App\\Models\\DatabasePool();
\$pool->name = 'OnPrem Tenant Cluster';
\$pool->description = 'Auto-registered by install-onprem.sh — points at tenant-db container.';
\$pool->engine = 'pgsql';
\$pool->host = 'tenant-db';
\$pool->port = 5432;
\$pool->provisioner_user = '${TENANT_DB_PROVISIONER_USER}';
\$pool->provisioner_password = '${TENANT_DB_PROVISIONER_PASSWORD}';
\$pool->sslmode = 'disable';
\$pool->region = 'on-prem';
\$pool->status = 'active';
\$pool->metadata = ['source' => 'install-onprem.sh'];
\$pool->save();
echo 'Pool created: ' . \$pool->id . PHP_EOL;
" 2>&1 | tail -2
  ok "Tenant DB pool registered"
else
  ok "Tenant DB pool already registered"
fi

# ─── Register default StoragePool pointing at MinIO ────────────────────────
log "Registering default MinIO storage pool..."
STORAGE_EXISTS=$(docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" exec -T backend \
  php artisan tinker --execute="echo App\\Models\\StoragePool::where('name','OnPrem MinIO Default')->count();" 2>/dev/null | tail -1 | tr -d '\r\n ')

if [[ "$STORAGE_EXISTS" == "0" ]]; then
  # Create the bucket first via MinIO client
  log "Creating 'privasimu' bucket on MinIO..."
  docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" exec -T minio sh -c "
    mc alias set local http://localhost:9000 ${MINIO_ROOT_USER} ${MINIO_ROOT_PASSWORD} 2>/dev/null
    mc mb local/privasimu 2>/dev/null || true
  " 2>&1 | tail -1 || warn "Bucket creation skipped (may already exist)"

  docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" exec -T backend php artisan tinker --execute="
\$pool = new App\\Models\\StoragePool();
\$pool->name = 'OnPrem MinIO Default';
\$pool->description = 'Auto-registered by install-onprem.sh — points at minio container.';
\$pool->driver = 'minio';
\$pool->endpoint = 'http://minio:9000';
\$pool->region = 'us-east-1';
\$pool->bucket = 'privasimu';
\$pool->access_key = '${MINIO_ROOT_USER}';
\$pool->secret_key = '${MINIO_ROOT_PASSWORD}';
\$pool->use_path_style_endpoint = true;
\$pool->is_default = true;
\$pool->status = 'active';
\$pool->save();
echo 'Storage pool created: ' . \$pool->id . PHP_EOL;
" 2>&1 | tail -2
  ok "MinIO storage pool registered as default"
else
  ok "Storage pool already registered"
fi

# ─── License activation (online mode) ──────────────────────────────────────
if [[ "${LICENSE_MODE:-online}" == "online" && -n "${LICENSE_KEY:-}" ]]; then
  log "Activating license..."
  docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" exec -T backend \
    php artisan license:activate "$LICENSE_KEY" 2>&1 | tail -3 || warn "License activation skipped (verify manually)"
fi

# ─── Done ──────────────────────────────────────────────────────────────────
echo
ok "Installation complete!"
echo
echo "Access:"
echo "  Web UI:        ${APP_URL:-http://localhost}"
echo "  API:           ${APP_URL:-http://localhost}/api"
echo "  MinIO Console: http://localhost:${MINIO_CONSOLE_PORT:-9001}  (login: $MINIO_ROOT_USER)"
echo
echo "Next steps:"
echo "  1. Login as root user."
echo "  2. Go to /platform-admin/database-pools to verify 'OnPrem Tenant Cluster' is listed."
echo "  3. Onboard your first tenant via /register or admin tools."
echo "  4. Enable Tier 2 isolation: /platform-admin/tenants → 'Aktifkan Isolation'."
echo
echo "Logs: docker compose -f $COMPOSE_FILE logs -f"
echo "Stop: docker compose -f $COMPOSE_FILE down"
