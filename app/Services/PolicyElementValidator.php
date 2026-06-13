<?php

namespace App\Services;

/**
 * Deterministic coverage check for the 15 mandatory UU PDP privacy-policy
 * elements. NON-AI: scans the generated sections JSON (heading/paragraph/list/
 * table text) for per-element keyword markers so the generator + UI can flag
 * which mandatory elements a draft does NOT yet cover and route them to manual
 * review — independent of (and cheaper than) the AI self-check.
 *
 * Element list & Pasal anchors per the Policy Generator design (UU PDP 27/2022
 * + Permenkominfo 20/2016 for child data). Markers are intentionally generous;
 * this is a "did the draft mention this at all" gate, not a quality score.
 */
class PolicyElementValidator
{
    /**
     * @var array<int,array{key:string,label:string,pasal:string,keywords:array<int,string>}>
     */
    public const ELEMENTS = [
        ['key' => 'identitas_pengendali', 'label' => 'Identitas Pengendali Data', 'pasal' => 'Pasal 31', 'keywords' => ['pengendali data', 'identitas pengendali', 'data controller', 'penanggung jawab data']],
        ['key' => 'kontak_dpo', 'label' => 'Kontak DPO', 'pasal' => 'Pasal 53', 'keywords' => ['dpo', 'pejabat pelindungan data', 'petugas pelindungan data', 'data protection officer', 'narahubung']],
        ['key' => 'kategori_data', 'label' => 'Kategori Data Dikumpulkan', 'pasal' => 'Pasal 16', 'keywords' => ['kategori data', 'jenis data', 'data yang dikumpulkan', 'data yang kami kumpulkan', 'categories of data', 'data we collect', 'personal data we collect']],
        ['key' => 'tujuan_pemrosesan', 'label' => 'Tujuan Pemrosesan', 'pasal' => 'Pasal 16', 'keywords' => ['tujuan pemrosesan', 'tujuan penggunaan', 'tujuan pengumpulan', 'purpose of processing', 'purposes of processing', 'why we process']],
        ['key' => 'dasar_hukum', 'label' => 'Dasar Hukum per Tujuan', 'pasal' => 'Pasal 20', 'keywords' => ['dasar hukum', 'dasar pemrosesan', 'basis hukum', 'dasar pengumpulan', 'legal basis', 'lawful basis']],
        ['key' => 'retensi', 'label' => 'Retensi Data', 'pasal' => 'Pasal 16', 'keywords' => ['retensi', 'masa simpan', 'masa penyimpanan', 'jangka waktu penyimpanan', 'retention', 'how long we keep']],
        ['key' => 'penerima_pihak_ketiga', 'label' => 'Penerima Data / Pihak Ketiga', 'pasal' => 'Pasal 31', 'keywords' => ['pihak ketiga', 'penerima data', 'berbagi data', 'pihak lain', 'pengungkapan data', 'third part', 'third-part', 'recipients', 'we share data', 'data sharing']],
        ['key' => 'hak_subjek', 'label' => 'Hak Subjek Data', 'pasal' => 'Pasal 5-10', 'keywords' => ['hak subjek', 'hak anda', 'hak pemilik data', 'hak akses', 'hak untuk', 'your rights', 'data subject rights', 'right to access']],
        ['key' => 'withdraw_consent', 'label' => 'Mekanisme Withdraw Consent', 'pasal' => 'Pasal 8', 'keywords' => ['penarikan persetujuan', 'menarik persetujuan', 'mencabut persetujuan', 'pencabutan persetujuan', 'withdraw consent', 'withdraw your consent', 'revoke consent']],
        ['key' => 'security', 'label' => 'Security Measures', 'pasal' => 'Pasal 35-39', 'keywords' => ['keamanan data', 'langkah keamanan', 'enkripsi', 'pengamanan data', 'kontrol akses', 'security measures', 'data security', 'safeguards']],
        ['key' => 'cookie', 'label' => 'Cookie Policy', 'pasal' => 'Pasal 16', 'keywords' => ['cookie', 'kuki', 'pelacak', 'tracker']],
        ['key' => 'data_anak', 'label' => 'Data Anak', 'pasal' => 'Pasal 26', 'keywords' => ['data anak', 'di bawah umur', 'anak-anak', 'usia minimum', 'permenkominfo', 'children', 'minors', 'under the age']],
        ['key' => 'cross_border', 'label' => 'Cross-Border Transfer', 'pasal' => 'Pasal 56', 'keywords' => ['lintas negara', 'transfer internasional', 'transfer ke luar negeri', 'cross-border', 'luar wilayah indonesia', 'international transfer', 'transfer abroad']],
        ['key' => 'breach_notification', 'label' => 'Breach Notification', 'pasal' => 'Pasal 46', 'keywords' => ['pelanggaran data', 'kebocoran data', 'insiden keamanan', 'pemberitahuan pelanggaran', 'notifikasi pelanggaran', 'data breach', 'breach notification', 'security incident']],
        ['key' => 'update_policy', 'label' => 'Mekanisme Update Policy', 'pasal' => 'Version control', 'keywords' => ['perubahan kebijakan', 'pembaruan kebijakan', 'pemutakhiran kebijakan', 'versi kebijakan', 'perubahan atas kebijakan', 'changes to this policy', 'updates to this policy', 'policy changes']],
    ];

    /**
     * Elements NOT applicable per audience (so they are never flagged "missing").
     * Customer/External-facing policies cover all 15. Internal HR-facing policies
     * (Employee, Job Applicant) do not concern web cookies or children's data.
     *
     * @var array<string,array<int,string>>
     */
    public const AUDIENCE_NOT_APPLICABLE = [
        'customer' => [],
        'external' => [],
        'employee' => ['cookie', 'data_anak'],
        'job_applicant' => ['cookie', 'data_anak'],
    ];

    /**
     * Validate which mandatory elements the sections cover, scoped to the
     * elements APPLICABLE for the given audience. Not-applicable elements are
     * never counted toward the total nor flagged missing.
     *
     * @param  array<int,mixed>  $sections  canonical sections JSON
     * @param  string  $audience  customer|employee|job_applicant|external
     * @return array{
     *   elements:array<int,array{key:string,label:string,pasal:string,covered:bool,applicable:bool}>,
     *   covered_count:int,
     *   total:int,
     *   missing:array<int,string>,
     *   not_applicable:array<int,string>,
     *   all_covered:bool
     * }
     */
    public static function validate(array $sections, string $audience = 'customer'): array
    {
        $haystack = self::flatten($sections);
        $naKeys = self::AUDIENCE_NOT_APPLICABLE[$audience] ?? [];

        $elements = [];
        $missing = [];
        $coveredCount = 0;
        $applicableTotal = 0;

        foreach (self::ELEMENTS as $el) {
            $applicable = ! in_array($el['key'], $naKeys, true);

            $covered = false;
            foreach ($el['keywords'] as $kw) {
                if ($haystack !== '' && str_contains($haystack, $kw)) {
                    $covered = true;
                    break;
                }
            }

            $elements[] = [
                'key' => $el['key'],
                'label' => $el['label'],
                'pasal' => $el['pasal'],
                'covered' => $covered,
                'applicable' => $applicable,
            ];

            if (! $applicable) {
                continue;
            }

            $applicableTotal++;
            if ($covered) {
                $coveredCount++;
            } else {
                $missing[] = $el['key'];
            }
        }

        return [
            'elements' => $elements,
            'covered_count' => $coveredCount,
            'total' => $applicableTotal,
            'missing' => $missing,
            'not_applicable' => array_values($naKeys),
            'all_covered' => $coveredCount === $applicableTotal,
        ];
    }

    /** Flatten the sections JSON to a single lowercased text blob for scanning. */
    private static function flatten(array $sections): string
    {
        $parts = [];
        foreach ($sections as $node) {
            if (! is_array($node)) {
                continue;
            }
            if (! empty($node['text']) && is_string($node['text'])) {
                $parts[] = $node['text'];
            }
            foreach ((array) ($node['items'] ?? []) as $item) {
                $parts[] = (string) $item;
            }
            foreach ((array) ($node['headers'] ?? []) as $h) {
                $parts[] = (string) $h;
            }
            foreach ((array) ($node['rows'] ?? []) as $row) {
                foreach ((array) $row as $cell) {
                    $parts[] = (string) $cell;
                }
            }
        }

        return mb_strtolower(implode("\n", $parts));
    }
}
