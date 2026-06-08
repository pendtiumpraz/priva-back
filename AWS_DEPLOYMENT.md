# Privasimu — Panduan Deploy di AWS (Frontend + Backend)

Panduan **dari awal sampai akhir** untuk men-deploy Privasimu (Laravel API + Next.js)
di AWS. Stack-nya sudah ter-Dockerize, jadi cara paling cepat & andal adalah
menjalankan `docker compose` di satu instance EC2. Tersedia juga jalur
**production-grade** (RDS + ElastiCache + S3 + ALB) untuk skala lebih besar.

> Stack yang dideploy: **MySQL 8 · Redis 7 · Laravel 12 (PHP 8.3, nginx+php-fpm+supervisor) · Next.js 16 (standalone) · Nginx reverse proxy**.
> Backend container otomatis menjalankan: `migrate --force`, seeder (opsional), **2 queue worker**, dan **scheduler** (`schedule:work`) via supervisord — lihat `backend/docker/`.

---

## Daftar Isi
1. [Arsitektur](#1-arsitektur)
2. [Prasyarat](#2-prasyarat)
3. [Jalur A — EC2 + Docker Compose (Direkomendasikan / Quickstart)](#3-jalur-a--ec2--docker-compose-direkomendasikan)
4. [HTTPS / Domain](#4-https--domain)
5. [Operasional Pasca-Deploy](#5-operasional-pasca-deploy)
6. [Update / Redeploy](#6-update--redeploy)
7. [Jalur B — Production-grade (RDS · ElastiCache · S3 · ALB · ECR)](#7-jalur-b--production-grade-managed-services)
8. [Referensi Environment Variables](#8-referensi-environment-variables)
9. [Backup & Restore](#9-backup--restore)
10. [Troubleshooting](#10-troubleshooting)
11. [Estimasi Biaya](#11-estimasi-biaya)

---

## 1. Arsitektur

### Jalur A — Single EC2 (paling sederhana, mirror dari `docker-compose.yml`)
```
                 Internet
                    │  443/80
            ┌───────▼────────┐
            │   Nginx (host  │   ← Let's Encrypt (certbot)
            │   atau container)
            └───┬────────┬───┘
        /       │        │  /api
   ┌────────────▼┐   ┌───▼─────────────┐
   │ frontend     │   │ backend (Laravel │
   │ Next.js :3000│   │ nginx+fpm :8000) │
   └──────────────┘   └───┬───────┬──────┘
                          │       │
                    ┌─────▼─┐  ┌──▼─────┐   ┌───────────────┐
                    │ MySQL │  │ Redis  │   │ queue-worker   │
                    │ :3306 │  │ :6379  │   │ (container ke-3)│
                    └───────┘  └────────┘   └───────────────┘
        Semua di 1 EC2, di-orkestrasi docker compose.
```

### Jalur B — Production (managed services)
```
Route53 → ACM/HTTPS → ALB ─┬─► ECS/EC2: frontend (Next.js)
                            └─► ECS/EC2: backend  (Laravel) ─┬─► RDS MySQL 8
                                                              ├─► ElastiCache Redis
                                                              └─► S3 (storage file/bukti)
   Image disimpan di ECR. Worker = service ECS terpisah.
```

---

## 2. Prasyarat

- **Akun AWS** + IAM user dengan akses EC2/VPC (dan RDS/ElastiCache/S3/ECR untuk Jalur B).
- **Domain** (mis. `app.privasimu.com`) — kita arahkan A record ke EC2/ALB.
- **Key pair EC2** (untuk SSH) — buat di EC2 Console → Key Pairs.
- Di lokal: `git`, `ssh`, dan (opsional) **AWS CLI** (`aws configure`).
- Kredensial AI (opsional): `OPENROUTER_API_KEY` / `OPENAI_API_KEY` / `ANTHROPIC_API_KEY`.

---

## 3. Jalur A — EC2 + Docker Compose (Direkomendasikan)

### 3.1 Launch EC2
1. **EC2 → Launch instance**
   - **AMI:** Ubuntu Server 24.04 LTS (x86_64).
   - **Type:** mulai `t3.medium` (2 vCPU / 4 GB). Untuk AI/queue berat: `t3.large`/`t3.xlarge`.
   - **Key pair:** pilih key pair kamu.
   - **Storage:** root **30–50 GB gp3** (MySQL + image Docker + storage bukti).
   - **Network:** VPC default, auto-assign public IP **enabled**.
2. **Security Group** (inbound):
   | Type  | Port | Source            | Untuk            |
   |-------|------|-------------------|------------------|
   | SSH   | 22   | **My IP**         | admin            |
   | HTTP  | 80   | 0.0.0.0/0         | web + certbot    |
   | HTTPS | 443  | 0.0.0.0/0         | web              |

   > Port 3306/6379/8000/3000 **jangan** dibuka ke publik — cukup internal Docker.
3. **(Disarankan) Elastic IP:** EC2 → Elastic IPs → Allocate → Associate ke instance,
   supaya IP tidak berubah saat reboot. Arahkan DNS domain ke IP ini (A record).

### 3.2 Pasang Docker
SSH ke instance:
```bash
ssh -i /path/key.pem ubuntu@<ELASTIC_IP>
```
Install Docker Engine + Compose plugin:
```bash
sudo apt-get update && sudo apt-get install -y ca-certificates curl git
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] \
  https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo $VERSION_CODENAME) stable" | \
  sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
sudo apt-get update
sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
sudo usermod -aG docker ubuntu && newgrp docker   # jalankan docker tanpa sudo
docker --version && docker compose version
```

### 3.3 Ambil kode & konfigurasi `.env`
```bash
git clone <URL_REPO_MONOREPO> privasimu      # repo yang berisi docker-compose.yml di root
cd privasimu
cp .env.docker.example .env
nano .env
```
Isi minimal di `.env` (lihat [§8](#8-referensi-environment-variables)):
```dotenv
COMPOSE_PROJECT_NAME=privasimu
DB_DATABASE=privasimu
DB_USERNAME=privasimu_user
DB_PASSWORD=<password-kuat>
DB_ROOT_PASSWORD=<password-root-kuat>

APP_NAME=Privasimu
APP_ENV=production
APP_DEBUG=false
APP_URL=https://app.privasimu.com         # domain produksi kamu
APP_KEY=                                   # diisi otomatis saat boot (atau generate, lihat bawah)

NEXT_PUBLIC_API_URL=/api                   # frontend memanggil API via nginx (relatif)
RUN_SEEDERS=true                           # set true HANYA saat first deploy
```

> **APP_KEY & JWT:** `entrypoint.sh` akan `php artisan key:generate --force` jika `APP_KEY` kosong.
> Untuk nilai yang **stabil** (disarankan), generate sekali lalu tempel ke `.env`:
> setelah container backend up → `docker compose exec backend php artisan key:generate --show`
> dan (jika dipakai) `docker compose exec backend php artisan jwt:secret --show`. Tempel ke `.env`, lalu `docker compose up -d` ulang.

### 3.4 Build & jalankan
```bash
docker compose up -d --build
docker compose ps          # semua harus "Up" / healthy
docker compose logs -f backend   # pantau migrate + seed pertama
```
Saat pertama kali, backend otomatis: `migrate --force` + (karena `RUN_SEEDERS=true`) `db:seed --force`
(termasuk **MenuRegistrySeeder**, dll.), lalu menjalankan fpm+nginx+2 worker+scheduler.

Setelah seed selesai, **matikan seeder** agar tidak re-seed di restart berikutnya:
```bash
sed -i 's/^RUN_SEEDERS=true/RUN_SEEDERS=false/' .env
docker compose up -d        # apply (recreate backend)
```

Buat symlink storage publik (sekali):
```bash
docker compose exec backend php artisan storage:link
```

Cek cepat (dari dalam EC2):
```bash
curl -I http://localhost           # nginx → frontend (200)
curl -s http://localhost/api/up || curl -s http://localhost/api  # backend reachable
```

Buka `http://<ELASTIC_IP>` di browser → harusnya muncul landing/login. Lanjut HTTPS.

---

## 4. HTTPS / Domain

`docker-compose.yml` mem-bind nginx ke **:80**. Untuk TLS, cara paling mudah adalah
**terminate TLS di nginx host** (di depan compose) memakai certbot.

### 4.1 Arahkan DNS
Di registrar/Route53: buat **A record** `app.privasimu.com → <ELASTIC_IP>`. Tunggu propagasi (`dig app.privasimu.com`).

### 4.2 Opsi 1 — Nginx host + Certbot (paling simpel)
Ubah port nginx **container** agar tidak rebut :80 (mis. ke 8080), lalu pasang nginx host sebagai TLS terminator yang proxy ke `127.0.0.1:8080`.

1. Edit `docker-compose.yml` service `nginx`: `ports: ["127.0.0.1:8080:80"]`, lalu `docker compose up -d`.
2. Pasang nginx + certbot di host:
```bash
sudo apt-get install -y nginx certbot python3-certbot-nginx
sudo tee /etc/nginx/sites-available/privasimu >/dev/null <<'NGINX'
server {
    listen 80;
    server_name app.privasimu.com;
    client_max_body_size 50M;            # upload dokumen/bukti
    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection 'upgrade';
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 300; proxy_send_timeout 300;
    }
}
NGINX
sudo ln -sf /etc/nginx/sites-available/privasimu /etc/nginx/sites-enabled/privasimu
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d app.privasimu.com   # otomatis pasang sertifikat + redirect 80→443
```
Certbot auto-renew sudah aktif (systemd timer). Set `APP_URL=https://app.privasimu.com` di `.env` → `docker compose up -d`.

### 4.3 Opsi 2 — ALB + ACM (kalau pakai Load Balancer)
- Minta sertifikat gratis di **ACM** (`app.privasimu.com`), validasi via DNS.
- Buat **Application Load Balancer** (listener 443 pakai sertifikat ACM, 80→443 redirect),
  target group → EC2 instance **port 80** (nginx container). Security group EC2: izinkan 80 hanya dari SG ALB.
- Route53 alias `app.privasimu.com` → ALB.

---

## 5. Operasional Pasca-Deploy

Semua proses latar **sudah otomatis** di dalam container backend (lihat `supervisord.conf`):
`php-fpm`, `nginx`, **2× `queue:work`**, dan **`schedule:work`** (scheduler). Plus 1 container
`queue-worker` terpisah → total 3 worker. Jadi **tidak perlu cron host** untuk scheduler.

Perintah artisan umum:
```bash
docker compose exec backend php artisan migrate --force        # migrasi manual (kalau perlu)
docker compose exec backend php artisan db:seed --class=MenuRegistrySeeder --force   # sinkron menu
docker compose exec backend php artisan optimize:clear         # bersihkan cache config/route
docker compose exec backend php artisan storage:link
docker compose exec backend php artisan queue:work --once      # tes worker manual
docker compose logs -f queue-worker                            # log worker
```

> **Setelah menambah menu/seed baru** (mis. split Cookie Management): jalankan
> `db:seed --class=MenuRegistrySeeder --force`, lalu user logout/login (cache menu sisi klien 30 menit).

---

## 6. Update / Redeploy

```bash
cd ~/privasimu
git pull
docker compose up -d --build          # rebuild image yang berubah (FE/BE) + restart
docker compose exec backend php artisan optimize:clear
```
- `migrate --force` otomatis jalan tiap backend start (aman, idempotent).
- Pastikan `RUN_SEEDERS=false` agar tidak re-seed data demo.
- **Frontend**: `NEXT_PUBLIC_API_URL` di-bake saat **build** (lihat `args` di compose), jadi
  perubahan API URL butuh `--build`.
- Zero-downtime sederhana: `docker compose up -d --build` me-recreate per service; untuk
  benar-benar zero-downtime gunakan Jalur B (ECS rolling).

---

## 7. Jalur B — Production-grade (Managed Services)

Pakai ini jika butuh HA, auto-scaling, dan pemisahan data dari compute. Gantikan
container `db`/`redis`/storage lokal dengan layanan terkelola.

### 7.1 Data layer
- **RDS MySQL 8.0** (Multi-AZ untuk HA). Catat endpoint, user, password, db.
  - SG RDS: izinkan 3306 **hanya** dari SG aplikasi (EC2/ECS).
- **ElastiCache for Redis 7**. Catat primary endpoint. SG: 6379 dari SG aplikasi.
- **S3 bucket** untuk file/bukti (evidence, export). Buat IAM role/policy `s3:*` terbatas ke bucket.

### 7.2 Arahkan aplikasi ke managed services (env backend)
```dotenv
DB_CONNECTION=mysql
DB_HOST=<nama>.xxxx.ap-southeast-3.rds.amazonaws.com
DB_PORT=3306
DB_DATABASE=privasimu
DB_USERNAME=<rds_user>
DB_PASSWORD=<rds_pass>

REDIS_HOST=<nama>.xxxx.cache.amazonaws.com
REDIS_PORT=6379
CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

# Simpan file ke S3 (bukan disk container) — wajib kalau >1 instance
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=<atau pakai IAM role instance/task>
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=ap-southeast-3
AWS_BUCKET=privasimu-prod-storage
AWS_URL=https://privasimu-prod-storage.s3.ap-southeast-3.amazonaws.com
```
> Multi-instance **wajib** S3 (storage lokal container tidak sinkron antar node).
> Hapus service `db` & `redis` dari compose, dan **jangan** mount `backend_storage`.

### 7.3 Image registry — ECR
```bash
aws ecr create-repository --repository-name privasimu-backend
aws ecr create-repository --repository-name privasimu-frontend
aws ecr get-login-password --region ap-southeast-3 | docker login --username AWS --password-stdin <ACCT>.dkr.ecr.ap-southeast-3.amazonaws.com

# Backend
docker build -t privasimu-backend ./backend
docker tag privasimu-backend <ACCT>.dkr.ecr.ap-southeast-3.amazonaws.com/privasimu-backend:latest
docker push <ACCT>.dkr.ecr.ap-southeast-3.amazonaws.com/privasimu-backend:latest

# Frontend (NEXT_PUBLIC_API_URL di-bake saat build!)
docker build --build-arg NEXT_PUBLIC_API_URL=/api -t privasimu-frontend ./frontend
docker tag privasimu-frontend <ACCT>.dkr.ecr.ap-southeast-3.amazonaws.com/privasimu-frontend:latest
docker push <ACCT>.dkr.ecr.ap-southeast-3.amazonaws.com/privasimu-frontend:latest
```

### 7.4 Compute — ECS Fargate (ringkas)
Buat **3 service** dalam 1 cluster (atau ECS-on-EC2):
1. **backend** — image ECR backend, port 8000, env dari §7.2, `desiredCount≥2`.
   - Override entrypoint default agar **tidak** double-run worker/scheduler kalau dipisah; atau biarkan (supervisord menjalankan semuanya per task — cukup untuk skala kecil).
2. **frontend** — image ECR frontend, port 3000, `desiredCount≥2`.
3. **queue-worker** — image backend, command `php artisan queue:work ...` (lihat compose), `desiredCount≥1`.
- **ALB** (lihat §4.3): rule `/api/*` → target group backend:8000, `/*` → frontend:3000.
- **Migrasi**: jalankan sebagai **one-off ECS task** (`php artisan migrate --force`) saat rilis,
  atau biarkan entrypoint backend menjalankannya (aman & idempotent).
- **Secrets**: simpan password/API key di **AWS Secrets Manager** / SSM Parameter Store,
  inject ke task definition (bukan plaintext).

### 7.5 Frontend alternatif — AWS Amplify / S3+CloudFront
Frontend Next.js memakai `output: standalone` (butuh Node runtime), jadi cocok dijalankan
sebagai **container** (cara di atas). Jika ingin PaaS, **AWS Amplify Hosting** mendukung Next.js
SSR — set env `NEXT_PUBLIC_API_URL=https://app.privasimu.com/api` dan arahkan ke backend ALB.
(S3+CloudFront murni hanya untuk static export — **tidak** dipakai karena app ini SSR/ISR.)

---

## 8. Referensi Environment Variables

| Variabel | Contoh | Keterangan |
|---|---|---|
| `APP_ENV` | `production` | mode Laravel |
| `APP_DEBUG` | `false` | **wajib false** di prod |
| `APP_KEY` | `base64:...` | generate sekali, simpan stabil |
| `APP_URL` | `https://app.privasimu.com` | URL publik |
| `DB_HOST/PORT/DATABASE/USERNAME/PASSWORD` | — | MySQL (compose: `db`; prod: endpoint RDS) |
| `DB_ROOT_PASSWORD` | — | hanya untuk container MySQL (Jalur A) |
| `REDIS_HOST/PORT` | `redis` / `6379` | cache+queue+session |
| `CACHE_DRIVER` `SESSION_DRIVER` `QUEUE_CONNECTION` | `redis` | — |
| `RUN_SEEDERS` | `true`→`false` | seed hanya saat first deploy |
| `FILESYSTEM_DISK` | `local` / `s3` | `s3` wajib bila multi-instance |
| `AWS_*` | — | kredensial/bucket S3 (Jalur B) |
| `NEXT_PUBLIC_API_URL` | `/api` | **build-time** untuk frontend |
| `OPENROUTER_API_KEY` / `OPENAI_API_KEY` / `ANTHROPIC_API_KEY` | — | fitur AI (opsional) |

> Frontend default `API_URL` jatuh ke `/api` (via nginx) — jadi browser memanggil domain yang sama,
> tidak ada CORS. Pastikan reverse proxy meneruskan `/api` ke backend (sudah diatur di `nginx/default.conf`).

---

## 9. Backup & Restore

**Jalur A (MySQL container):**
```bash
# Backup harian (taruh di cron host)
docker compose exec -T db mysqldump -u root -p"$DB_ROOT_PASSWORD" privasimu | gzip > ~/backup-$(date +%F).sql.gz
aws s3 cp ~/backup-$(date +%F).sql.gz s3://privasimu-backups/   # simpan off-box

# Restore
gunzip < backup-YYYY-MM-DD.sql.gz | docker compose exec -T db mysql -u root -p"$DB_ROOT_PASSWORD" privasimu
```
Backup juga volume `backend_storage` (file bukti): `docker run --rm -v privasimu_backend_storage:/d -v ~/:/b alpine tar czf /b/storage-$(date +%F).tgz -C /d .`

**Jalur B:** aktifkan **RDS automated backups** (retensi 7–35 hari) + snapshot manual sebelum rilis besar.
File di **S3** sudah durable; aktifkan **versioning** bucket.

---

## 10. Troubleshooting

| Gejala | Cek |
|---|---|
| 502 di `/` | `docker compose logs frontend` — pastikan `node server.js` up di :3000 |
| 502 di `/api` | `docker compose logs backend` — fpm/nginx up di :8000; cek koneksi DB |
| Login gagal / 500 | `APP_KEY` kosong/berubah → set stabil, `optimize:clear` |
| Migrasi tidak jalan | lihat `docker compose logs backend` (entrypoint run `migrate --force`); cek kredensial DB |
| Menu sidebar baru tak muncul | jalankan `db:seed --class=MenuRegistrySeeder --force` + logout/login (cache 30 mnt) |
| Upload gagal/terpotong | naikkan `client_max_body_size` di nginx host (§4.2) & `upload_max_filesize` (php.ini) |
| File hilang setelah redeploy | pakai **S3** (`FILESYSTEM_DISK=s3`) atau pastikan volume `backend_storage` tidak terhapus |
| Worker tidak proses job | `docker compose logs queue-worker`; cek `REDIS_HOST` & `QUEUE_CONNECTION=redis` |
| Out of memory saat build FE | EC2 ≥ 4 GB RAM, atau tambah swap 2 GB |
| HTTPS gagal renew | `sudo certbot renew --dry-run`; pastikan port 80 terbuka |

Tambah swap (kalau RAM pas-pasan saat build):
```bash
sudo fallocate -l 2G /swapfile && sudo chmod 600 /swapfile && sudo mkswap /swapfile && sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
```

---

## 11. Estimasi Biaya (region ap-southeast-3 / Jakarta, perkiraan)

| Komponen | Jalur A (hemat) | Jalur B (production) |
|---|---|---|
| Compute | 1× t3.medium ~ $30/bln | 2× t3.medium / Fargate ~ $60–120/bln |
| Database | (di EC2, gratis) | RDS db.t3.small Multi-AZ ~ $50–80/bln |
| Cache | (di EC2, gratis) | ElastiCache t3.micro ~ $15/bln |
| Storage | EBS 50GB ~ $5/bln | EBS + S3 (pay-per-use) |
| LB | (nginx host, gratis) | ALB ~ $18/bln + traffic |
| **Total kira-kira** | **~$35–40/bln** | **~$150–250/bln** |

> Mulai dari **Jalur A** (cukup untuk pilot/SME on-prem-style di cloud). Naik ke **Jalur B**
> saat butuh HA, banyak tenant, dan compliance pemisahan data.

---

### Ringkas — first deploy (Jalur A)
```bash
# di EC2 (Ubuntu, Docker terpasang)
git clone <repo> privasimu && cd privasimu
cp .env.docker.example .env && nano .env      # isi DB_*, APP_URL, RUN_SEEDERS=true
docker compose up -d --build                  # build + migrate + seed otomatis
docker compose exec backend php artisan storage:link
sed -i 's/^RUN_SEEDERS=true/RUN_SEEDERS=false/' .env && docker compose up -d
# arahkan DNS → EC2, lalu pasang HTTPS (certbot, §4.2)
```
Selesai. 🚀
