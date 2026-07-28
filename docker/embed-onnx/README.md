# Sidecar Embedding ONNX

Melayani model embedding kecil ber-format ONNX terkuantisasi (int8) di CPU,
dengan kontrak HTTP yang sama seperti TEI sehingga bisa saling menggantikan.

Dipakai oleh mode **local** dan **blob** pada panel root
*Platform Config → Model Embedding*. Mode **api** tidak memakai sidecar ini
sama sekali.

## Kenapa tinggal di repo backend

Mode `blob` adalah **default**, dan mode itu tetap menghitung vektor di
sidecar ini — blob hanya menentukan dari mana berkas modelnya diambil. Artinya
hampir setiap deployment yang menyalakan `AI_EMBEDDING_ENABLED` membutuhkan
sidecar ini, bukan hanya deployment on-prem berGPU.

Karena itu ia dikirim bersama backend yang memanggilnya, bukan bersama stack
`ai-onprem` yang hanya dipasang klien paket AI on-prem.

## Kenapa terpisah dari TEI (`ai-onprem`)

Stack `ai-onprem` punya service `embeddings` berbasis TEI. Keduanya melengkapi,
bukan menggantikan:

| | TEI (`ai-onprem`) | Sidecar ini (`embed-onnx`) |
|---|---|---|
| Model | bge-m3, 1024 dim | MiniLM / BGE-small / E5-small, 384 dim |
| Presisi | fp16 | int8 terkuantisasi |
| Ukuran | ~2 GB | 23 – 118 MB |
| Perangkat | GPU (compose minta nvidia) | CPU |
| Cocok untuk | throughput tinggi, on-prem berGPU | VPS kecil, deployment hemat |

Pilih TEI lewat mode `api` (`AI_EMBEDDING_PROVIDER=tei`); pilih sidecar ini
lewat mode `local` atau `blob`.

## Kontrak HTTP

```
GET  /health     -> {"status":"ok","default_model":"minilm-l6-v2"}
GET  /models     -> daftar model + status termuat / ada di disk
POST /embed      -> {"inputs":["teks"],"model":"minilm-l6-v2","kind":"passage"}
                    balasan [[0.01, -0.03, ...]]  (searah urutan input)
```

`kind` bernilai `query` atau `passage` (default `passage`). Keluarga E5 dan
BGE memakai imbuhan berbeda di kedua sisi; melewatkannya membuat skor
kemiripan melenceng tanpa memunculkan error apa pun.

## Variabel lingkungan

| Variabel | Default | Keterangan |
|---|---|---|
| `PORT` | `8091` | Port dengar |
| `MODELS_DIR` | `/models` | Direktori artefak hasil unduhan mode *local* |
| `CACHE_DIR` | `/tmp/embed-cache` | Tempat menyimpan unduhan mode *blob* |
| `BLOB_BASE_URL` | — | Awalan URL publik Vercel Blob, mis. `https://<store>.public.blob.vercel-storage.com/embedding-models`. Wajib untuk mode *blob*. |
| `EMBED_MODEL` | `minilm-l6-v2` | Model default bila request tidak menyebut `model` |
| `MAX_BATCH` | `64` | Batas jumlah teks per request |

Sidecar sengaja **tidak** boleh menarik model langsung dari HuggingFace
(`allowRemoteModels = false`). Artefak harus disediakan lewat panel root agar
yang berjalan di produksi selalu berkas yang sudah ditinjau.

## Menjalankan

### Lewat compose (dianjurkan)

Sudah terdaftar di `docker/docker-compose.onprem.yml` di balik profil
`cpu-embed`, supaya tidak ikut naik pada deployment yang memakai mode `api`:

```bash
docker compose -f docker/docker-compose.onprem.yml --profile cpu-embed up -d embed-onnx
```

### Berdiri sendiri

```bash
# Mode local — artefak sudah diunduh lewat panel root
docker build -t privasimu-embed-onnx docker/embed-onnx
docker run --rm -p 8091:8091 \
  -v /srv/privasimu/models:/models:ro \
  privasimu-embed-onnx

# Mode blob
docker run --rm -p 8091:8091 \
  -e BLOB_BASE_URL=https://<store>.public.blob.vercel-storage.com/embedding-models \
  privasimu-embed-onnx
```

Arahkan backend ke sidecar lewat `.env`:

```
AI_EMBEDDING_ENABLED=true
AI_EMBEDDING_LOCAL_URL=http://embed-onnx:8091   # atau http://127.0.0.1:8091 bila di luar compose
AI_EMBEDDING_MODELS_DIR=/srv/privasimu/models
```

Nilai `AI_EMBEDDING_MODELS_DIR` harus menunjuk direktori yang sama dengan yang
di-mount ke `/models` — panel root menulis ke sana, sidecar membacanya. Kalau
keduanya berbeda, unduhan mode `local` akan tampak berhasil tetapi sidecar
tidak menemukan berkasnya.

## Setelah mengganti model

Seluruh model di katalog menghasilkan 384 dimensi, jadi skema kolom vektor
tidak berubah. Tetapi **ruang vektornya berbeda**: vektor lama dan baru tidak
sebanding. Jalankan embed ulang setelah berganti model, jika tidak hasil
pencarian akan tampak acak tanpa error apa pun:

```bash
php artisan embeddings:backfill
```
