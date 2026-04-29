# Production Deployment Runbook (AWS)

> **Audience**: Privasimu DevOps / Platform Engineering. Step-by-step setup for the SaaS deployment that hosts paying clients (including financial-institution tenants on Tier 2 BYODB).
>
> **Goal**: stand up production AWS infrastructure ready to onboard the first FI client by go-live date. Estimated ops time: **1-2 days** for the AWS side, plus ~1 day buffer for monitoring + verification.

## Architecture Target

```
                    ┌──────────────────────────────────────────────┐
                    │  Region: ap-southeast-3 (Jakarta)            │
                    │  REQUIRED for OJK data residency             │
                    └──────────────────────────────────────────────┘

  Public Internet
        │
        ▼
  ┌──────────┐         VPC (10.0.0.0/16)
  │   ALB    │       ┌─────────────────────────────────────┐
  │  (HTTPS) │  ───► │ Public subnets (10.0.1/2/3.0/24)   │
  └──────────┘       │ ┌──────────┐  ┌──────────┐         │
                     │ │ ECS task │  │ ECS task │   App   │
                     │ │ backend  │  │ frontend │         │
                     │ └─────┬────┘  └──────────┘         │
                     └──────-┼──────────────────────────-─┘
                             │
                     ┌───────┴───────────────────────────────┐
                     │ Private subnets (10.0.10/11/12.0/24) │
                     │ ┌───────────────────┐                │
                     │ │ RDS Postgres 16   │  Landlord DB   │
                     │ │ db.t3.medium      │  (1 instance)  │
                     │ │ Multi-AZ          │                │
                     │ └───────────────────┘                │
                     │                                       │
                     │ ┌───────────────────┐                │
                     │ │ RDS Postgres 16   │  Tenant Cluster│
                     │ │ db.t3.medium or   │  (DBs created  │
                     │ │ db.r6g.large      │   per FI tenant│
                     │ │ Multi-AZ          │   inside this) │
                     │ └───────────────────┘                │
                     │                                       │
                     │ ┌───────────────────┐                │
                     │ │ ElastiCache Redis │  Cache + Queue │
                     │ └───────────────────┘                │
                     │                                       │
                     │ ┌───────────────────┐                │
                     │ │ S3                │  Storage       │
                     │ │ privasimu-tenants │                │
                     │ └───────────────────┘                │
                     └───────────────────────────────────────┘
```

## Prerequisites

- [ ] AWS account with billing alarm at threshold (recommend $1k/month for starter)
- [ ] Region **ap-southeast-3 (Jakarta)** enabled in account (some new accounts have it disabled by default — request through AWS support)
- [ ] Route53 hosted zone for the production domain (e.g. `privasimu.com`)
- [ ] ACM certificate for the domain in ap-southeast-3 (for ALB HTTPS)
- [ ] AWS CLI v2 + Terraform 1.6+ on the operator machine (or equivalent CDK)
- [ ] IAM identity with admin access for initial setup; later replace with least-privilege roles

## Step 1 — VPC + Subnets + Security Groups

Create a VPC `privasimu-prod` (10.0.0.0/16) with:
- 3 public subnets across 3 AZs (10.0.1/2/3.0/24) for ALB + ECS tasks
- 3 private subnets (10.0.10/11/12.0/24) for RDS + ElastiCache
- 1 NAT gateway in each AZ (or 1 shared if cost-sensitive at launch)
- Internet gateway attached
- Routing tables wired so private subnets reach internet via NAT, public via IGW

**Security groups** (least-privilege):
- `sg-alb` — accept 80/443 from 0.0.0.0/0
- `sg-ecs` — accept 8000 (backend) + 3000 (frontend) from `sg-alb` only
- `sg-rds-landlord` — accept 5432 from `sg-ecs` only
- `sg-rds-tenant` — accept 5432 from `sg-ecs` only
- `sg-redis` — accept 6379 from `sg-ecs` only

## Step 2 — KMS Keys

Create two customer-managed KMS keys:

- `privasimu-rds-key` — used to encrypt RDS storage (both landlord + tenant clusters)
- `privasimu-s3-key` — used to encrypt S3 objects

Tag each `Project = privasimu, Env = prod`. Grant the relevant service principals (`rds.amazonaws.com`, `s3.amazonaws.com`).

## Step 3 — RDS Landlord Instance

Create RDS Postgres for the landlord (platform metadata):

| Setting | Value |
|---|---|
| Engine | Postgres 16 |
| Instance class | db.t3.medium (start small, scale later) |
| Storage | 50 GB gp3, encrypted with `privasimu-rds-key` |
| Multi-AZ | **Yes** |
| Master username | `privasimu_landlord_master` |
| Master password | strong random — store in **Secrets Manager** (`privasimu/landlord/master`) |
| VPC | `privasimu-prod` |
| Subnet group | private subnets |
| Security group | `sg-rds-landlord` |
| Backup retention | 35 days |
| Backup window | 17:00 UTC (00:00 WIB) |
| Maintenance window | Sunday 18:00 UTC |
| Performance Insights | enabled |
| Log exports | postgresql, upgrade |

After provisioning:
```sql
-- Connect as master and create the app DB + user
CREATE DATABASE privasimu_landlord;
CREATE USER privasimu_app WITH PASSWORD '<strong-random>';
GRANT ALL PRIVILEGES ON DATABASE privasimu_landlord TO privasimu_app;
\c privasimu_landlord
GRANT ALL PRIVILEGES ON SCHEMA public TO privasimu_app;
```

Store `privasimu_app` password in Secrets Manager (`privasimu/landlord/app`). The app reads the connection details from there at boot.

## Step 4 — RDS Tenant Cluster Instance

Create a SECOND RDS Postgres instance for hosting tenant databases:

| Setting | Value |
|---|---|
| Engine | Postgres 16 |
| Instance class | db.t3.medium (1-10 tenants) → db.r6g.large (10-100) → cluster (100+) |
| Storage | 100 GB gp3, encrypted, **autoscaling enabled** (max 1 TB) |
| Multi-AZ | **Yes** |
| Master username | `privasimu_provisioner` |
| Master password | strong random — store in Secrets Manager (`privasimu/tenant-cluster/provisioner`) |
| Subnet / SG | private subnets / `sg-rds-tenant` |
| Backup retention | 35 days |
| Performance Insights | enabled |

The `privasimu_provisioner` user is what Privasimu's `PrivasimuHostedProvisioner` connects as when creating a new tenant DB. Its IAM grants must include `rds_superuser` role on the instance:
```sql
-- After RDS creation, connect as master:
GRANT rds_superuser TO privasimu_provisioner;
```

This allows it to `CREATE DATABASE`, `CREATE USER`, and `GRANT` — see `BYODB.md` §7.

## Step 5 — ElastiCache Redis

| Setting | Value |
|---|---|
| Engine | Redis 7.x |
| Node type | cache.t3.micro (small workloads) |
| Cluster mode | disabled (single primary + replica) |
| Subnet | private |
| SG | `sg-redis` |
| Encryption in transit | enabled |
| Encryption at rest | enabled |
| Auth token | strong random, store in Secrets Manager |

## Step 6 — S3 Bucket for Storage

Bucket name: `privasimu-tenants-prod` (region: ap-southeast-3)

Settings:
- Versioning: **enabled**
- Default encryption: **SSE-KMS with `privasimu-s3-key`**
- Block all public access: **enabled**
- Lifecycle: transition to Glacier after 365 days (compliance retention)
- Object Lock: **enabled** (governance mode) — prevents accidental deletion of audit-relevant tenant files

Bucket policy: deny non-TLS access.

Create an IAM user `privasimu-s3-prod` with `s3:GetObject/PutObject/ListBucket/DeleteObject` on this bucket only. Store its access key + secret in Secrets Manager (`privasimu/s3/prod`).

## Step 7 — Secrets Manager

By the end of Step 6 you should have these secrets:

```
privasimu/landlord/master       → RDS landlord master credentials
privasimu/landlord/app          → app user credentials
privasimu/tenant-cluster/master → RDS tenant cluster master
privasimu/tenant-cluster/provisioner → provisioner user (used by Privasimu pool)
privasimu/redis/auth            → Redis AUTH token
privasimu/s3/prod               → S3 IAM user access key + secret
privasimu/app/key               → Laravel APP_KEY (base64-encoded 32 bytes)
```

ECS task IAM role needs `secretsmanager:GetSecretValue` on these ARNs.

## Step 8 — ECS / Fargate Task Definitions

Two task definitions: `privasimu-backend` and `privasimu-frontend`. Both use the Docker images built from this repo. Backend reads DB + Redis + S3 + APP_KEY from Secrets Manager via task IAM role.

Sample backend task env:
```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://app.privasimu.com
DB_CONNECTION=pgsql
DB_HOST=<rds-landlord-endpoint>
DB_PORT=5432
DB_DATABASE=privasimu_landlord
DB_USERNAME=privasimu_app
DB_PASSWORD=<from secret privasimu/landlord/app>
DB_SSLMODE=require
REDIS_HOST=<elasticache-endpoint>
REDIS_PORT=6379
REDIS_PASSWORD=<from secret privasimu/redis/auth>
QUEUE_CONNECTION=redis
CACHE_DRIVER=redis
SESSION_DRIVER=redis
FILESYSTEM_DISK=local   # tenant uploads still go to s3 via storage_pools registry
APP_KEY=<from secret privasimu/app/key>
```

ECS service: 2 backend tasks + 2 frontend tasks behind ALB. Use target tracking auto-scaling on CPU 60%.

A **third** task: `privasimu-queue-worker` running `php artisan queue:work --tries=1 --timeout=3600`. 1-2 instances; scale on queue depth.

## Step 9 — Application Load Balancer

ALB in public subnets, ACM cert for HTTPS:

- Listener 443 → target group `tg-frontend` (port 3000)
- Listener 443 path rule `/api/*` → target group `tg-backend` (port 8000)
- Health check: `/up` for backend, `/` for frontend
- Connection draining: 60s

DNS: `app.privasimu.com` ALIAS → ALB.

## Step 10 — Run Migrations + Seed

After ECS service is healthy, exec into a backend task and run:

```bash
php artisan migrate --force
php artisan db:seed --force
php artisan storage:link
php artisan root:create root@privasimu.com '<strong-password>' --name='Platform Owner'
```

These run against the landlord DB. Tenant cluster has no app schema yet — that's per-tenant work via the provisioner.

## Step 11 — Register the Tenant Cluster as a DatabasePool

Login as root → `/platform-admin/database-pools` → **Tambah Pool**:

| Field | Value |
|---|---|
| Name | `AWS RDS Jakarta Tenant Cluster` |
| Engine | PostgreSQL |
| Host | `<rds-tenant-cluster-endpoint>` |
| Port | 5432 |
| Provisioner User | `privasimu_provisioner` |
| Provisioner Password | from Secrets Manager (`privasimu/tenant-cluster/provisioner`) |
| SSL Mode | `require` |
| CA Cert | (download from AWS RDS console — `rds-ca-2019-root.pem` for ap-southeast-3) |
| Region | `ap-southeast-3` |
| Status | active |
| Max Tenants | 50 (revisit when nearing this) |

Click **Test Koneksi** to verify, then **Simpan**.

## Step 12 — Register Default Storage Pool

`/platform-admin/storage-pools` → **Tambah Pool**:

| Field | Value |
|---|---|
| Name | `AWS S3 Jakarta Default` |
| Driver | AWS S3 |
| Region | `ap-southeast-3` |
| Bucket | `privasimu-tenants-prod` |
| Access Key | from Secrets Manager (`privasimu/s3/prod`) |
| Secret Key | from Secrets Manager (`privasimu/s3/prod`) |
| Default | ✓ |

Test koneksi → Simpan.

## Step 13 — CloudWatch Alarms

Minimum set:

- RDS landlord CPU > 70% for 5m
- RDS landlord FreeableMemory < 200MB for 5m
- RDS tenant cluster CPU > 70%
- RDS tenant cluster FreeStorageSpace < 10GB
- Replication lag > 60s on either RDS
- ECS backend task running count < desired count
- ALB 5xx rate > 1% for 5m
- ElastiCache CPU > 70%

Wire SNS topic → PagerDuty/Opsgenie integration.

## Step 14 — Backup Validation

Don't trust automated backups until you've tested restore:

```bash
# Pick latest snapshot
aws rds restore-db-instance-from-db-snapshot \
  --db-instance-identifier privasimu-landlord-test-restore \
  --db-snapshot-identifier <snapshot-arn>

# Connect, verify schema + sample data, drop test instance
```

Run this drill quarterly. Document RTO + RPO in your DR runbook.

## Step 15 — Smoke Test End-to-End

1. Create a test org via the registration flow
2. Login as superadmin → `/platform-admin/tenants` → find test org → **Aktifkan Isolation**
3. Pick the AWS RDS Jakarta pool → confirm
4. Watch state cycle: shared → provisioning → migrating → isolated (~30s)
5. Login as a user of the test org → make a ROPA, upload a file
6. Verify ROPA row lives in `privasimu_tenant_<uuid>` on the tenant cluster (not landlord)
7. Verify file in S3 under `tenants/<org-id>/`
8. Run `php artisan tenants:migrate --pretend` — should report no pending migrations after step 4

## Step 16 — Production Cutover Checklist

Before pointing real traffic at the new infra:

- [ ] Backup validation drill passed (Step 14)
- [ ] CloudWatch alarms wired to PagerDuty (Step 13)
- [ ] Smoke test passed (Step 15)
- [ ] Pen-test report on file (third-party — usually 4-6 weeks lead time, start early)
- [ ] DPA template signed by client
- [ ] DR runbook published (RTO < 4h, RPO < 1h targets)
- [ ] Logs flowing to CloudWatch + retention configured
- [ ] Secrets Manager rotation scheduled (90-day for app credentials)
- [ ] Cost alarm verified ($X/month threshold tuned)

## Operational Day-2

### Adding new pools
As load grows, add more `DatabasePool` rows pointing at additional RDS instances. The `findActivePool()` resolver picks the pool with lowest `current_tenants_count`, so spread happens automatically.

### Scaling vertically
Modify the RDS instance class (db.t3.medium → db.r6g.large) during the maintenance window. Multi-AZ failover keeps downtime < 1 minute.

### Migrating tenants between pools
Not yet automated in v1. Manual procedure:
1. Dump source tenant DB: `pg_dump -h <src-pool> ... privasimu_tenant_<uuid> > backup.sql`
2. Provision tenant on target pool via API
3. Restore: `psql -h <new-pool> ... privasimu_tenant_<uuid> < backup.sql`
4. Update `organizations.tenant_db_config` + `db_pool_id` to point at new pool
5. Decrement old pool counter, increment new pool counter

### When to add a new region
Klien minta data residency di Singapore atau region lain yang berbeda dari Jakarta. Replicate Steps 1-13 in the new region; register as a separate `DatabasePool` row tagged with that region.

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `tenants:provision` fails with "permission denied for database" | Provisioner user lacks `rds_superuser` | `GRANT rds_superuser TO privasimu_provisioner` |
| Provision succeeds but `tenants:migrate` errors on FK | M4 cross-DB FK drop didn't run (older provisioning code) | Manually drop the FKs, see `BYODB.md` §7 |
| Connection timeout from app to RDS | Security group misconfigured | Verify `sg-rds-*` allows 5432 from `sg-ecs` |
| Slow tenant query after isolation | Tenant DB needs index analysis | Run `ANALYZE` in tenant DB; check `pg_stat_statements` |
| Tenant locked out at login after migration | LandlordPinned trait failure | Verify `landlord` connection alias is registered (see `AppServiceProvider::boot`) |

## Reference

- `BYODB.md` — design rationale, 3-tier model, compliance mapping
- `BYODB_plan_and_progress_tracker.md` — milestone progress + decision log
- `backend/README.md` § Tenancy & Database Isolation — runtime flow + middleware order
- `backend/docs/ONPREM_DEPLOY.md` — sister runbook for self-hosted deployments
