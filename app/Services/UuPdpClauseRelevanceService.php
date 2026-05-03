<?php

namespace App\Services;

/**
 * Single source of truth untuk per-document-type UU PDP clause requirements.
 *
 * Dipakai oleh:
 *   - DocumentMakerService    (generator → inject clause checklist ke prompt)
 *   - AiFeatureController     (contractReview → relevance gating)
 *
 * Kanonik 8 klausul UU PDP (UU 27/2022):
 *   - klausul_tujuan_pemrosesan      (Pasal 16)
 *   - hak_subjek_data                (Pasal 5–13)
 *   - kewajiban_pengendali           (Pasal 20–35)
 *   - transfer_lintas_negara         (Pasal 56)
 *   - masa_retensi                   (Pasal 27)
 *   - mekanisme_pemusnahan           (Pasal 27, 43)
 *   - klausul_kerahasiaan            (Pasal 36)
 *   - klausul_pelanggaran_data       (Pasal 46)
 *
 * Tiap clause punya 3 kemungkinan status per document-type:
 *   - core            → WAJIB ada
 *   - conditional_pii → WAJIB hanya kalau dokumen cover Data Pribadi
 *   - not_applicable  → tidak relevan, jangan di-flag missing
 */
class UuPdpClauseRelevanceService
{
    public const CLAUSE_KEYS = [
        'klausul_tujuan_pemrosesan',
        'hak_subjek_data',
        'kewajiban_pengendali',
        'transfer_lintas_negara',
        'masa_retensi',
        'mekanisme_pemusnahan',
        'klausul_kerahasiaan',
        'klausul_pelanggaran_data',
    ];

    /**
     * Map dari DocumentMaker `document_type` → Contract Review `contract_type`.
     * DocumentMaker punya granular type (msa, vendor_agreement); reviewer pakai
     * canonical bucket. Function ini bridge antar dua sisi.
     */
    public static function mapDocumentTypeToContractType(string $documentType): string
    {
        $t = strtolower(trim($documentType));

        return match ($t) {
            'nda' => 'nda',
            'dpa' => 'dpa',
            'msa', 'vendor_agreement', 'vendor', 'service_agreement', 'partnership' => 'vendor',
            'employment', 'employment_contract', 'pkwt', 'pkwtt' => 'employment',
            'customer', 'customer_agreement', 'b2c', 'eula', 'tos' => 'customer',
            default => 'other',
        };
    }

    /**
     * Per-contract-type relevance map.
     *
     * @return array{core:array<int,string>, conditional_pii:array<int,string>, not_applicable:array<int,string>}
     */
    public static function getRelevance(string $contractType): array
    {
        $type = strtolower(trim($contractType));

        $map = [
            'nda' => [
                'core' => ['klausul_kerahasiaan'],
                'conditional_pii' => ['masa_retensi', 'mekanisme_pemusnahan', 'klausul_pelanggaran_data'],
                'not_applicable' => ['hak_subjek_data', 'kewajiban_pengendali', 'transfer_lintas_negara', 'klausul_tujuan_pemrosesan'],
            ],
            'dpa' => [
                'core' => [
                    'klausul_tujuan_pemrosesan', 'hak_subjek_data', 'kewajiban_pengendali',
                    'klausul_pelanggaran_data', 'masa_retensi', 'mekanisme_pemusnahan', 'klausul_kerahasiaan',
                ],
                'conditional_pii' => ['transfer_lintas_negara'],
                'not_applicable' => [],
            ],
            'vendor' => [
                'core' => ['klausul_kerahasiaan', 'klausul_pelanggaran_data'],
                'conditional_pii' => [
                    'hak_subjek_data', 'kewajiban_pengendali', 'masa_retensi',
                    'mekanisme_pemusnahan', 'klausul_tujuan_pemrosesan', 'transfer_lintas_negara',
                ],
                'not_applicable' => [],
            ],
            'employment' => [
                'core' => ['klausul_kerahasiaan', 'masa_retensi'],
                'conditional_pii' => ['hak_subjek_data', 'mekanisme_pemusnahan'],
                'not_applicable' => ['transfer_lintas_negara', 'kewajiban_pengendali', 'klausul_tujuan_pemrosesan'],
            ],
            'customer' => [
                'core' => ['klausul_kerahasiaan', 'hak_subjek_data', 'klausul_tujuan_pemrosesan'],
                'conditional_pii' => ['masa_retensi', 'mekanisme_pemusnahan', 'klausul_pelanggaran_data', 'transfer_lintas_negara'],
                'not_applicable' => ['kewajiban_pengendali'],
            ],
        ];

        return $map[$type] ?? [
            'core' => [],
            'conditional_pii' => ['klausul_kerahasiaan', 'klausul_pelanggaran_data', 'masa_retensi', 'mekanisme_pemusnahan'],
            'not_applicable' => [],
        ];
    }

    /**
     * Convenience: relevance dari DocumentMaker `document_type` langsung,
     * via canonical bridge.
     *
     * @return array{core:array<int,string>, conditional_pii:array<int,string>, not_applicable:array<int,string>}
     */
    public static function getRelevanceForDocumentType(string $documentType): array
    {
        return self::getRelevance(self::mapDocumentTypeToContractType($documentType));
    }

    /**
     * Human label per clause (Indonesian, untuk UI + prompt).
     *
     * @return array<string,string>
     */
    public static function getClauseLabels(): array
    {
        return [
            'klausul_tujuan_pemrosesan' => 'Tujuan Pemrosesan Data',
            'hak_subjek_data' => 'Hak Subjek Data',
            'kewajiban_pengendali' => 'Kewajiban Pengendali Data',
            'transfer_lintas_negara' => 'Transfer Data Lintas Negara',
            'masa_retensi' => 'Masa Retensi Data',
            'mekanisme_pemusnahan' => 'Mekanisme Pemusnahan Data',
            'klausul_kerahasiaan' => 'Klausul Kerahasiaan',
            'klausul_pelanggaran_data' => 'Klausul Pelanggaran Data',
        ];
    }

    /**
     * Drafting guidance per clause untuk Document Maker generator. Tiap entry
     * menyebutkan: pasal UU PDP, isi minimum yg harus ada, dan contoh konkret.
     *
     * @return array<string,array{pasal:string, must_include:string}>
     */
    public static function getDraftingGuidance(): array
    {
        return [
            'klausul_tujuan_pemrosesan' => [
                'pasal' => 'Pasal 16 UU PDP',
                'must_include' => 'Tujuan pemrosesan data spesifik & terbatas, dasar hukum (consent/kontrak/kewajiban hukum/kepentingan vital/kepentingan sah), kategori data yang diproses.',
            ],
            'hak_subjek_data' => [
                'pasal' => 'Pasal 5–13 UU PDP',
                'must_include' => 'Hak akses, koreksi, penghapusan, portabilitas, pembatasan pemrosesan, penolakan profiling otomatis. Mekanisme & timeline (mis. 72 jam) untuk respon permintaan.',
            ],
            'kewajiban_pengendali' => [
                'pasal' => 'Pasal 20–35 UU PDP',
                'must_include' => 'Kewajiban pengendali memastikan akurasi data, keamanan teknis-organisasional (enkripsi, access control, audit log), penunjukan DPO bila wajib, dokumentasi pemrosesan.',
            ],
            'transfer_lintas_negara' => [
                'pasal' => 'Pasal 56 UU PDP',
                'must_include' => 'Larangan transfer keluar Indonesia tanpa safeguard. Bila ada transfer: sebut negara tujuan + safeguard (SCC, BCR, adequacy decision, atau persetujuan eksplisit subjek data).',
            ],
            'masa_retensi' => [
                'pasal' => 'Pasal 27 UU PDP',
                'must_include' => 'Durasi retensi spesifik per kategori data, dikaitkan dengan tujuan pemrosesan. Hindari "sesuai kebutuhan" tanpa angka konkret.',
            ],
            'mekanisme_pemusnahan' => [
                'pasal' => 'Pasal 27, 43 UU PDP',
                'must_include' => 'Prosedur pemusnahan setelah masa retensi habis / pengakhiran perjanjian. Sebut metode (overwrite, shredding, certified destruction) + timeline (mis. 30 hari) + kewajiban pemberian sertifikat pemusnahan.',
            ],
            'klausul_kerahasiaan' => [
                'pasal' => 'Pasal 36 UU PDP',
                'must_include' => 'Kewajiban menjaga kerahasiaan data, batasan disclosure ke pihak ketiga, durasi pasca-pengakhiran (umumnya 3-5 tahun), pengembalian/pemusnahan informasi rahasia.',
            ],
            'klausul_pelanggaran_data' => [
                'pasal' => 'Pasal 46 UU PDP',
                'must_include' => 'Notifikasi pelanggaran ke pengendali ≤ 24-72 jam, ke subjek data ≤ 72 jam (kecuali high-risk lebih cepat), prosedur containment, root cause analysis, post-incident report. Sanksi/denda spesifik untuk pelanggaran.',
            ],
        ];
    }

    /**
     * Build a markdown-ish checklist text for an AI prompt — listing the
     * clauses applicable to this document type with drafting guidance.
     *
     * `assumePiiCoverage` = true → conditional clauses ditreat sebagai core
     * (untuk Document Maker yang tau dari wizard input apakah dokumen touch PII).
     */
    public static function buildPromptChecklist(string $contractType, bool $assumePiiCoverage = false): string
    {
        $relevance = self::getRelevance($contractType);
        $labels = self::getClauseLabels();
        $guidance = self::getDraftingGuidance();

        $coreList = $assumePiiCoverage
            ? array_unique(array_merge($relevance['core'], $relevance['conditional_pii']))
            : $relevance['core'];

        $lines = ['=== KLAUSUL UU PDP YANG WAJIB ADA ==='];
        if (empty($coreList)) {
            $lines[] = '(Tidak ada klausul UU PDP yang strictly required untuk tipe ini.)';
        }
        foreach ($coreList as $key) {
            $g = $guidance[$key] ?? null;
            $label = $labels[$key] ?? $key;
            if ($g) {
                $lines[] = "- {$label} ({$g['pasal']}): {$g['must_include']}";
            } else {
                $lines[] = "- {$label}";
            }
        }

        if (! $assumePiiCoverage && ! empty($relevance['conditional_pii'])) {
            $lines[] = '';
            $lines[] = '=== CONDITIONAL — sertakan HANYA jika dokumen menyentuh Data Pribadi (PII) ===';
            foreach ($relevance['conditional_pii'] as $key) {
                $g = $guidance[$key] ?? null;
                $label = $labels[$key] ?? $key;
                if ($g) {
                    $lines[] = "- {$label} ({$g['pasal']}): {$g['must_include']}";
                } else {
                    $lines[] = "- {$label}";
                }
            }
        }

        if (! empty($relevance['not_applicable'])) {
            $naLabels = array_map(fn ($k) => $labels[$k] ?? $k, $relevance['not_applicable']);
            $lines[] = '';
            $lines[] = '=== TIDAK PERLU disertakan (tidak relevan untuk tipe ini) ===';
            $lines[] = '- '.implode(', ', $naLabels);
        }

        return implode("\n", $lines);
    }

    // =========================================================================
    // POLICY / SOP — scope-based dimension gating
    // =========================================================================
    //
    // Untuk policy/SOP, UU PDP gating bukan per-klausul (kayak kontrak), tapi
    // per-dimensi: tipe dokumen tertentu fokus pada dimensi tertentu. Misalnya
    // SOP Breach Response gak butuh dimensi "tujuan pemrosesan" (itu domain
    // Privacy Policy). Map di bawah jadi single source of truth untuk:
    //   - DocumentMakerService::buildPolicyScopeHint (generator prompt)
    //   - AiFeatureController::policyReview (analyzer prompt)
    //   - DocumentMakerService::selfCheckPolicy (post-generate review)

    /**
     * Map dari DocumentMaker frontend `document_type` → canonical policy
     * review type. Dipakai supaya generator + reviewer sinkron meskipun
     * frontend & analyzer pakai key set yg historically beda.
     */
    public static function mapPolicyDocumentTypeToReviewType(string $documentType): string
    {
        $t = strtolower(trim($documentType));

        return match ($t) {
            'privacy_policy', 'kebijakan_privasi' => 'kebijakan_privasi',
            'internal_sop', 'sop_data_handling' => 'sop_data_handling',
            'code_of_conduct', 'peraturan_perusahaan' => 'peraturan_perusahaan',
            'data_retention_policy', 'sop_retensi' => 'sop_retensi',
            'breach_response_policy', 'sop_breach_response' => 'sop_breach_response',
            'dsr_policy', 'sop_dsr' => 'sop_dsr',
            default => 'other',
        };
    }

    /**
     * Human-readable label per canonical policy review type. Dipakai prompt.
     *
     * @return array<string,string>
     */
    public static function getPolicyTypeLabels(): array
    {
        return [
            'kebijakan_privasi' => 'Kebijakan Privasi (Privacy Policy)',
            'sop_data_handling' => 'SOP Penanganan Data Pribadi',
            'sop_breach_response' => 'SOP Respon Pelanggaran Data',
            'peraturan_perusahaan' => 'Peraturan Perusahaan / Kode Etik',
            'sop_dsr' => 'SOP Pemenuhan Hak Subjek Data',
            'sop_retensi' => 'SOP Retensi & Pemusnahan Data',
            'other' => 'Dokumen Lainnya',
        ];
    }

    /**
     * Per-canonical-policy-type scope hint untuk AI prompt.
     * Format: focus dimensi UU PDP yang relevan + pasal refs.
     *
     * @return array<string,string>
     */
    public static function getPolicyScopeHints(): array
    {
        return [
            'kebijakan_privasi' => 'fokus dimensi: tujuan pemrosesan (Pasal 16), dasar hukum (Pasal 20), hak subjek data (Pasal 5–13), masa retensi (Pasal 27), transfer lintas negara (Pasal 56), kontak DPO (Pasal 53), persetujuan (Pasal 22), kategori data dikumpulkan, mekanisme keamanan (Pasal 35).',
            'sop_data_handling' => 'fokus dimensi: prosedur pemrosesan, klasifikasi data, akses kontrol & RBAC, enkripsi at-rest/in-transit (Pasal 35), audit log, pelatihan personel, prinsip data minimization (Pasal 16).',
            'sop_breach_response' => 'fokus dimensi: deteksi & klasifikasi insiden, eskalasi, notifikasi pelanggaran ≤ 3×24 jam (Pasal 46), notifikasi subjek data, containment, root cause analysis, post-mortem & dokumentasi.',
            'peraturan_perusahaan' => 'fokus dimensi: kewajiban karyawan, kerahasiaan data (Pasal 36), sanksi internal, pelatihan PDP wajib, larangan disclosure — TIDAK perlu hak subjek data eksternal atau dasar hukum konsumen.',
            'sop_dsr' => 'fokus dimensi: penerimaan permintaan, verifikasi identitas, deadline 72 jam (Pasal 5–13), dokumentasi keputusan, eskalasi penolakan, jejak audit.',
            'sop_retensi' => 'fokus dimensi: jadwal retensi per kategori data, prosedur pemusnahan (Pasal 27, 43), metode (overwrite, shredding, certified destruction), sertifikat pemusnahan, review berkala.',
            'other' => 'fokus dimensi UU PDP yang RELEVAN dengan konten dokumen — JANGAN paksa dimensi yang tidak applicable.',
        ];
    }

    /**
     * Convenience: scope hint string untuk prompt, untuk satu policy type.
     */
    public static function getPolicyScopeHint(string $documentType): string
    {
        $reviewType = self::mapPolicyDocumentTypeToReviewType($documentType);
        $hints = self::getPolicyScopeHints();

        return $hints[$reviewType] ?? $hints['other'];
    }

    /**
     * Build full scope-hint block for a policy generator prompt.
     */
    public static function buildPolicyPromptScope(string $documentType): string
    {
        $reviewType = self::mapPolicyDocumentTypeToReviewType($documentType);
        $labels = self::getPolicyTypeLabels();
        $label = $labels[$reviewType] ?? $documentType;
        $hint = self::getPolicyScopeHint($documentType);

        return "=== SCOPE UU PDP UNTUK TIPE DOKUMEN ({$label}) ===\n"
            ."{$hint}\n"
            .'JANGAN paksa dimensi UU PDP yang tidak relevan ke dokumen ini — fokus pada scope di atas.';
    }
}
