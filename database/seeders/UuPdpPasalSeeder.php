<?php

namespace Database\Seeders;

use App\Models\KnowledgeBaseSection;
use Illuminate\Database\Seeder;

/**
 * UU PDP — Full Pasal Seeder (data-driven)
 * -------------------------------------------------------------------
 * Menanam SELURUH pasal UU No. 27 Tahun 2022 tentang Pelindungan Data
 * Pribadi sebagai entry Knowledge Base (shared rule, org_id = null), agar
 * AI chat & user bisa merujuk detail per-pasal.
 *
 * Sumber data: database/seeders/data/uu_pdp_pasal.json
 *   - Struktur Bab + nomor & topik Pasal = informasi publik faktual.
 *   - Field "content" tiap pasal = teks VERBATIM resmi. SENGAJA kosong di
 *     repo untuk pasal yang teks resminya belum di-supply, supaya tidak ada
 *     teks pasal yang dikarang. Isi field "content" di JSON dengan salinan
 *     resmi UU 27/2022 (Lembaran Negara RI 2022 No. 196), lalu jalankan ulang
 *     seeder ini.
 *
 * Perilaku:
 *   - content terisi  → ditanam apa adanya (markdown).
 *   - content kosong  → ditanam SKELETON: judul Bab/Pasal + topik + label
 *                       eksplisit "[Teks verbatim belum tersedia]". TIDAK
 *                       mengarang bunyi pasal.
 *
 * Idempotent — updateOrCreate by module_key (uupdp_pasal_{N}).
 *
 * Run:
 *   php artisan db:seed --class=UuPdpPasalSeeder
 */
class UuPdpPasalSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/uu_pdp_pasal.json');
        if (! is_file($path)) {
            $this->command->error("File data tidak ditemukan: {$path}");
            return;
        }

        $data = json_decode(file_get_contents($path), true);
        if (! is_array($data) || empty($data['bab'])) {
            $this->command->error('Format JSON UU PDP tidak valid.');
            return;
        }

        $meta = $data['_meta'] ?? [];
        $regulation = $meta['regulation'] ?? 'UU No. 27 Tahun 2022 tentang Pelindungan Data Pribadi';
        $officialUrl = $meta['official_text_url'] ?? null;

        $created = 0;
        $withVerbatim = 0;
        $skeletonOnly = 0;
        $sort = 200; // setelah entry KB lain (comprehensive seeder pakai 100+)

        // 0. Entry overview kategori — peta Bab/Pasal lengkap.
        $overview = "# {$regulation}\n\n";
        $overview .= "Struktur lengkap UU PDP: **{$data['_meta']['total_bab']} Bab, {$data['_meta']['total_pasal']} Pasal**. ";
        $overview .= "Setiap pasal punya entry KB sendiri (`uupdp_pasal_N`). Cari \"Pasal N\" untuk detail.\n\n";
        if ($officialUrl) {
            $overview .= "Naskah resmi: {$officialUrl}\n\n";
        }
        $overview .= "## Daftar Bab\n\n";
        foreach ($data['bab'] as $bab) {
            $pasalNos = array_map(fn ($p) => $p['no'], $bab['pasal']);
            $range = count($pasalNos) > 1
                ? 'Pasal '.min($pasalNos).'–'.max($pasalNos)
                : 'Pasal '.($pasalNos[0] ?? '-');
            $overview .= "- **BAB {$bab['nomor']} — {$bab['judul']}** ({$range})\n";
        }

        KnowledgeBaseSection::updateOrCreate(
            ['module_key' => 'uupdp_struktur_lengkap'],
            [
                'org_id' => null,
                'title' => 'UU PDP No. 27/2022 — Struktur Lengkap (16 Bab, 76 Pasal)',
                'category' => 'regulation',
                'feature_tags' => 'chat,policy_review,remediation',
                'keywords' => 'uu pdp,uu 27 2022,pelindungan data pribadi,struktur,bab,pasal,daftar,index,seluruh pasal,76 pasal',
                'summary' => 'Indeks lengkap UU PDP No. 27 Tahun 2022 — 16 Bab dan 76 Pasal. Tiap pasal tersedia sebagai entry KB terpisah (uupdp_pasal_N).',
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
                $verbatim = trim($pasal['content'] ?? '');

                $title = "UU PDP Pasal {$no} — ".($topik !== '' ? $this->shorten($topik) : 'BAB '.$bab['nomor']);

                if ($verbatim !== '') {
                    $content = "# UU PDP Pasal {$no}\n\n";
                    $content .= "_BAB {$bab['nomor']} — {$bab['judul']}_\n\n";
                    $content .= $verbatim."\n";
                    $withVerbatim++;
                } else {
                    $content = "# UU PDP Pasal {$no}\n\n";
                    $content .= "_BAB {$bab['nomor']} — {$bab['judul']}_\n\n";
                    $content .= "**Topik:** {$topik}\n\n";
                    $content .= "> **[Teks verbatim belum tersedia di repo]** — Bunyi resmi Pasal {$no} ".
                                "belum disalin ke knowledge base. Jangan mengarang teks pasal. ".
                                "Salin teks resmi dari naskah UU 27/2022";
                    if ($officialUrl) {
                        $content .= " ({$officialUrl})";
                    }
                    $content .= " ke field `content` Pasal {$no} di ".
                                "`database/seeders/data/uu_pdp_pasal.json`, lalu jalankan ulang seeder ini.\n";
                    $skeletonOnly++;
                }

                KnowledgeBaseSection::updateOrCreate(
                    ['module_key' => "uupdp_pasal_{$no}"],
                    [
                        'org_id' => null,
                        'title' => $title,
                        'category' => 'regulation',
                        'feature_tags' => 'chat,policy_review,remediation,ropa_autofill,dpia_autofill',
                        'keywords' => $this->keywords($no, $bab, $topik),
                        'summary' => "UU PDP Pasal {$no} (BAB {$bab['nomor']} — {$bab['judul']}): {$topik}",
                        'content' => $content,
                        'sort_order' => $sort++,
                        'is_active' => true,
                    ]
                );
                $created++;
            }
        }

        $this->command->info("✅ UU PDP KB: {$created} entry ditanam (1 indeks + ".($created - 1)." pasal).");
        $this->command->info("   • {$withVerbatim} pasal dengan teks verbatim, {$skeletonOnly} pasal skeleton (teks verbatim belum di-supply).");
        if ($skeletonOnly > 0) {
            $this->command->warn("   ⚠ {$skeletonOnly} pasal masih SKELETON. Isi field \"content\" di database/seeders/data/uu_pdp_pasal.json dengan teks resmi UU 27/2022, lalu seed ulang.");
        }
    }

    private function shorten(string $topik): string
    {
        // Ambil frasa pertama yang ringkas untuk judul.
        $first = preg_split('/[\(\—\-,;]/u', $topik)[0] ?? $topik;
        $first = trim($first);
        return mb_strlen($first) > 80 ? mb_substr($first, 0, 77).'…' : $first;
    }

    private function keywords(int $no, array $bab, string $topik): string
    {
        $base = "uu pdp,pdp,uu 27 2022,pelindungan data pribadi,pasal {$no},pasal{$no},bab {$bab['nomor']},{$bab['judul']}";
        // Tambah kata kunci topik (huruf kecil) yang signifikan.
        $words = preg_split('/[^\p{L}0-9]+/u', mb_strtolower($topik), -1, PREG_SPLIT_NO_EMPTY);
        $stop = ['dan','atau','yang','data','pribadi','dalam','untuk','dari','pada','tidak','dengan','oleh','ke','di','hal','terhadap'];
        $keep = array_values(array_unique(array_filter($words, fn ($w) => mb_strlen($w) > 3 && ! in_array($w, $stop, true))));
        $keep = array_slice($keep, 0, 12);
        return $keep ? $base.','.implode(',', $keep) : $base;
    }
}
