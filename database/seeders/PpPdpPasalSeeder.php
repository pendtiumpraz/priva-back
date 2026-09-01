<?php

namespace Database\Seeders;

use App\Models\KnowledgeBaseSection;
use Illuminate\Database\Seeder;

/**
 * PP 33/2026 — Full Pasal Seeder (data-driven)
 * -------------------------------------------------------------------
 * Menanam SELURUH pasal Peraturan Pemerintah No. 33 Tahun 2026 tentang
 * Peraturan Pelaksanaan UU No. 27 Tahun 2022 tentang Pelindungan Data
 * Pribadi sebagai entry Knowledge Base (shared rule, org_id = null),
 * sebagai PELENGKAP {@see UuPdpPasalSeeder}. PP 33 adalah aturan
 * pelaksana UU PDP — begitu di-embed (KbEmbeddingObserver → pgvector),
 * AI chat & fitur RAG merujuk kedua dokumen sekaligus lewat
 * search_knowledge_base.
 *
 * Sumber data: database/seeders/data/pp_33_2026_pasal.json
 *   - Diekstrak dari PDF salinan resmi (Setneg) via pdftotext, header/footer
 *     halaman + catatan tanda tangan elektronik BSrE dibersihkan, paragraf
 *     yang terpecah OCR disatukan kembali tanpa parafrase substansi.
 *   - Tiap pasal punya `content` (bunyi pasal) dan opsional `penjelasan`
 *     (dari bagian PENJELASAN "Pasal Demi Pasal"; entry "Cukup jelas"
 *     tidak dilampirkan).
 *
 * Idempotent — updateOrCreate by module_key (pp33_pasal_{N}).
 *
 * Run:
 *   php artisan db:seed --class=PpPdpPasalSeeder
 */
class PpPdpPasalSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/pp_33_2026_pasal.json');
        if (! is_file($path)) {
            $this->command->error("File data tidak ditemukan: {$path}");

            return;
        }

        $data = json_decode(file_get_contents($path), true);
        if (! is_array($data) || empty($data['bab'])) {
            $this->command->error('Format JSON PP 33/2026 tidak valid.');

            return;
        }

        $meta = $data['_meta'] ?? [];
        $regulation = $meta['regulation'] ?? 'Peraturan Pemerintah No. 33 Tahun 2026 tentang Peraturan Pelaksanaan UU No. 27 Tahun 2022 tentang Pelindungan Data Pribadi';
        $short = $meta['short'] ?? 'PP 33/2026';
        $officialUrl = $meta['official_text_url'] ?? null;
        $totalBab = $meta['total_bab'] ?? count($data['bab']);
        $totalPasal = $meta['total_pasal'] ?? 0;

        $created = 0;
        $withPenjelasan = 0;
        $sort = 400; // setelah UU PDP (200+) supaya UU tampil lebih dulu

        // 0. Entry overview kategori — peta Bab/Pasal lengkap.
        $overview = "# {$regulation}\n\n";
        $overview .= "**{$short}** adalah aturan pelaksana (peraturan pemerintah) dari "
            .($meta['implements'] ?? 'UU No. 27 Tahun 2022 tentang Pelindungan Data Pribadi').'. ';
        $overview .= "Struktur lengkap: **{$totalBab} Bab, {$totalPasal} Pasal**. ";
        $overview .= "Setiap pasal punya entry KB sendiri (`pp33_pasal_N`). Cari \"PP 33 Pasal N\" untuk detail.\n\n";
        if (! empty($meta['ditetapkan'])) {
            $overview .= "- Ditetapkan/diundangkan: **{$meta['ditetapkan']}**\n";
        }
        if (! empty($meta['lembaran_negara'])) {
            $overview .= "- {$meta['lembaran_negara']}\n";
        }
        if (! empty($meta['mulai_berlaku'])) {
            $overview .= "- Mulai berlaku: **{$meta['mulai_berlaku']}**\n";
        }
        if ($officialUrl) {
            $overview .= "- Naskah resmi: {$officialUrl}\n";
        }
        $overview .= "\n## Daftar Bab\n\n";
        foreach ($data['bab'] as $bab) {
            $pasalNos = array_map(fn ($p) => $p['no'], $bab['pasal']);
            if (empty($pasalNos)) {
                continue;
            }
            $range = count($pasalNos) > 1
                ? 'Pasal '.min($pasalNos).'–'.max($pasalNos)
                : 'Pasal '.($pasalNos[0] ?? '-');
            $overview .= "- **BAB {$bab['nomor']} — {$bab['judul']}** ({$range})\n";
        }

        KnowledgeBaseSection::updateOrCreate(
            ['module_key' => 'pp33_struktur_lengkap'],
            [
                'org_id' => null,
                'title' => "PP 33/2026 — Struktur Lengkap ({$totalBab} Bab, {$totalPasal} Pasal)",
                'category' => 'regulation',
                'feature_tags' => 'chat,policy_review,remediation',
                'keywords' => 'pp 33 2026,pp pdp,pp 33,peraturan pemerintah 33 2026,pelaksanaan uu pdp,peraturan pelaksanaan,struktur,bab,pasal,daftar,index,225 pasal',
                'summary' => "Indeks lengkap {$short} — {$totalBab} Bab dan {$totalPasal} Pasal, aturan pelaksana UU PDP 27/2022. Tiap pasal tersedia sebagai entry KB terpisah (pp33_pasal_N).",
                'content' => $overview,
                'sort_order' => $sort++,
                'is_active' => true,
            ]
        );
        $created++;

        // 1. Satu entry per Pasal.
        foreach ($data['bab'] as $bab) {
            foreach ($bab['pasal'] as $pasal) {
                $no = $pasal['no'];
                $topik = trim($pasal['topik'] ?? '');
                $bagian = trim($pasal['bagian'] ?? '');
                $body = trim($pasal['content'] ?? '');
                $penjelasan = trim($pasal['penjelasan'] ?? '');

                $title = "PP 33/2026 Pasal {$no} — ".($topik !== '' ? $this->shorten($topik) : 'BAB '.$bab['nomor']);

                $content = "# PP 33/2026 Pasal {$no}\n\n";
                $content .= "_BAB {$bab['nomor']} — {$bab['judul']}_";
                if ($bagian !== '') {
                    $content .= " · _{$bagian}_";
                }
                $content .= "\n\n";
                $content .= ($body !== '' ? $body : '_[Teks pasal tidak terekstrak — verifikasi naskah resmi.]_')."\n";
                if ($penjelasan !== '') {
                    $content .= "\n## Penjelasan Pasal {$no}\n\n".$penjelasan."\n";
                    $withPenjelasan++;
                }

                $summary = "PP 33/2026 Pasal {$no} (BAB {$bab['nomor']} — {$bab['judul']}"
                    .($bagian !== '' ? ", {$bagian}" : '').'): '.($topik !== '' ? $topik : $this->shorten($body));

                KnowledgeBaseSection::updateOrCreate(
                    ['module_key' => "pp33_pasal_{$no}"],
                    [
                        'org_id' => null,
                        'title' => $title,
                        'category' => 'regulation',
                        'feature_tags' => 'chat,policy_review,remediation,ropa_autofill,dpia_autofill',
                        'keywords' => $this->keywords($no, $bab, $topik, $bagian),
                        'summary' => mb_strlen($summary) > 480 ? mb_substr($summary, 0, 477).'…' : $summary,
                        'content' => $content,
                        'sort_order' => $sort++,
                        'is_active' => true,
                    ]
                );
                $created++;
            }
        }

        $this->command->info("✅ PP 33/2026 KB: {$created} entry ditanam (1 indeks + ".($created - 1).' pasal).');
        $this->command->info("   • {$withPenjelasan} pasal disertai Penjelasan resmi (Pasal Demi Pasal).");
    }

    private function shorten(string $text): string
    {
        $first = preg_split('/[\(\—\-,;:]/u', $text)[0] ?? $text;
        $first = trim($first);

        return mb_strlen($first) > 80 ? mb_substr($first, 0, 77).'…' : $first;
    }

    private function keywords(int $no, array $bab, string $topik, string $bagian): string
    {
        $base = "pp 33 2026,pp pdp,pp 33,peraturan pemerintah,pelaksanaan uu pdp,pasal {$no},pasal{$no},bab {$bab['nomor']},{$bab['judul']}";
        if ($bagian !== '') {
            $base .= ','.mb_strtolower($bagian);
        }
        $words = preg_split('/[^\p{L}0-9]+/u', mb_strtolower($topik), -1, PREG_SPLIT_NO_EMPTY);
        $stop = ['dan', 'atau', 'yang', 'data', 'pribadi', 'dalam', 'untuk', 'dari', 'pada', 'tidak', 'dengan', 'oleh', 'ke', 'di', 'hal', 'terhadap', 'sebagaimana', 'dimaksud'];
        $keep = array_values(array_unique(array_filter($words, fn ($w) => mb_strlen($w) > 3 && ! in_array($w, $stop, true))));
        $keep = array_slice($keep, 0, 12);

        return $keep ? $base.','.implode(',', $keep) : $base;
    }
}
