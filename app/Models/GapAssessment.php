<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class GapAssessment extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id', 'version', 'overall_score', 'compliance_level',
        'progress', 'answers', 'recommendations', 'created_by',
    ];

    protected $casts = [
        'answers' => 'array',
        'recommendations' => 'array',
        'overall_score' => 'decimal:2',
    ];

    /**
     * Hitung skor otomatis dari jawaban
     */
    public static function calculateScore(array $answers, string $code = 'uupdp'): array
    {
        $questions = self::getQuestionBank($code);
        $totalWeight = 0;
        $earnedWeight = 0;
        $recommendations = [];
        $categoryScores = [];

        foreach ($questions as $q) {
            $qId = $q['id'];
            $answer = $answers[$qId] ?? null;
            $weight = $q['weight'];
            $totalWeight += $weight;

            if (!isset($categoryScores[$q['category']])) {
                $categoryScores[$q['category']] = ['total' => 0, 'earned' => 0];
            }
            $categoryScores[$q['category']]['total'] += $weight;

            $score = match ($answer) {
                    'yes' => $weight,
                    'partial' => $weight * 0.5,
                    'no' => 0,
                    'na' => 0, // N/A doesn't count
                    default => 0,
                };

            if ($answer === 'na') {
                $totalWeight -= $weight; // Don't count N/A
                $categoryScores[$q['category']]['total'] -= $weight;
            }
            else {
                $earnedWeight += $score;
                $categoryScores[$q['category']]['earned'] += $score;
            }

            // Generate recommendation untuk jawaban No atau Partial
            if ($answer === 'no' || $answer === 'partial') {
                $recommendations[] = [
                    'question_id' => $qId,
                    'question' => $q['question'],
                    'article' => $q['article'],
                    'priority' => $weight >= 4 ? 'critical' : ($weight >= 3 ? 'high' : 'medium'),
                    'recommendation' => $q['recommendation'],
                    'current_answer' => $answer,
                ];
            }
        }

        $overallScore = $totalWeight > 0 ? round(($earnedWeight / $totalWeight) * 100, 2) : 0;
        $level = $overallScore >= 70 ? 'high' : ($overallScore >= 40 ? 'medium' : 'low');

        // Hitung per kategori
        $breakdown = [];
        foreach ($categoryScores as $cat => $scores) {
            $breakdown[$cat] = $scores['total'] > 0
                ? round(($scores['earned'] / $scores['total']) * 100, 2)
                : 0;
        }

        // Sort recommendations by priority
        usort($recommendations, function ($a, $b) {
            $order = ['critical' => 0, 'high' => 1, 'medium' => 2];
            return ($order[$a['priority']] ?? 3) - ($order[$b['priority']] ?? 3);
        });

        return [
            'overall_score' => $overallScore,
            'compliance_level' => $level,
            'category_breakdown' => $breakdown,
            'recommendations' => $recommendations,
            'total_questions' => count($questions),
            'answered' => count(array_filter($answers, fn($a) => $a !== null)),
        ];
    }

    /**
     * Bank soal GAP Assessment berdasarkan Regulasi terpilih
     */
    public static function getQuestionBank(string $code = 'uupdp'): array
    {
        $framework = \App\Models\RegulationFramework::where('code', $code)->first();
        if ($framework && $framework->articles) {
            $articles = is_string($framework->articles) ? json_decode($framework->articles, true) : $framework->articles;
            $mapped = [];
            foreach ($articles as $a) {
                if (isset($a['score_weight'])) {
                    $mapped[] = [
                        'id' => $a['id'],
                        'category' => $a['topic'] ?? 'General',
                        'subcategory' => 'Assessment',
                        'article' => $a['article'] ?? '-',
                        'weight' => $a['score_weight'] ?? 5,
                        'question' => $a['question'] ?? '',
                        'explanation' => '-',
                        'recommendation' => 'Review ' . ($a['topic'] ?? '') . ' compliance based on ' . ($a['article'] ?? ''),
                    ];
                } else {
                    $mapped[] = $a;
                }
            }
            if (count($mapped) > 0 && $code !== 'uupdp') {
                return $mapped;
            }
        }

        return [
            // ============================================
            // TATA KELOLA
            // ============================================

            // --- Kerangka/Framework PDP ---
            [
                'id' => 'TK-FR-01', 'category' => 'Tata Kelola', 'subcategory' => 'Kerangka/Framework PDP',
                'article' => 'Pasal 42-47', 'weight' => 5,
                'question' => 'Apakah Organisasi telah memiliki kerangka kerja (framework) pelindungan data pribadi yang terdokumentasi dan disetujui oleh manajemen puncak?',
                'explanation' => 'Framework PDP adalah kerangka kerja strategis yang menjadi acuan dalam implementasi pelindungan data pribadi secara menyeluruh. Mencakup kebijakan, prosedur, standar, dan pedoman teknis yang selaras dengan UU PDP.',
                'recommendation' => 'Buat framework PDP komprehensif yang mencakup: kebijakan utama, SOP operasional, standar teknis keamanan, pedoman pemrosesan data, dan mekanisme review berkala. Pastikan mendapat persetujuan tertulis dari Board/Direksi.',
            ],
            [
                'id' => 'TK-FR-02', 'category' => 'Tata Kelola', 'subcategory' => 'Kerangka/Framework PDP',
                'article' => 'Pasal 42', 'weight' => 5,
                'question' => 'Apakah Organisasi telah memiliki kebijakan pelindungan data pribadi (Privacy Policy) yang dipublikasikan dan dapat diakses oleh subjek data?',
                'explanation' => 'Privacy Policy adalah dokumen yang menginformasikan kepada subjek data tentang bagaimana data pribadinya dikumpulkan, diproses, disimpan, dan dilindungi oleh organisasi.',
                'recommendation' => 'Buat Privacy Policy yang mencakup: identitas pengendali data, tujuan pemrosesan, dasar hukum, jenis data, masa retensi, hak subjek data, dan kontak DPO. Publikasikan di website dan mudah diakses.',
            ],

            // --- Strategi Program PDP ---
            [
                'id' => 'TK-SP-01', 'category' => 'Tata Kelola', 'subcategory' => 'Strategi Program PDP',
                'article' => 'Pasal 47', 'weight' => 4,
                'question' => 'Apakah Organisasi telah menyusun strategi dan roadmap implementasi program PDP yang terukur?',
                'explanation' => 'Strategi program PDP adalah rencana jangka panjang yang menjelaskan tahapan, target, dan indikator keberhasilan implementasi pelindungan data pribadi dalam organisasi.',
                'recommendation' => 'Susun roadmap implementasi PDP dengan milestone per kuartal, KPI yang terukur, anggaran yang dialokasikan, dan penanggung jawab per area.',
            ],

            // --- Data Protection Officer ---
            [
                'id' => 'TK-PO-01', 'category' => 'Tata Kelola', 'subcategory' => 'Data Protection Officer',
                'article' => 'Pasal 53', 'weight' => 5,
                'question' => 'Apakah Organisasi telah menunjuk Pejabat/Petugas Pelindungan Data Pribadi (DPO) secara formal?',
                'explanation' => 'DPO adalah individu yang ditunjuk untuk memastikan kepatuhan organisasi terhadap UU PDP. DPO harus memiliki pengetahuan di bidang hukum, praktik pelindungan data, dan keamanan informasi.',
                'recommendation' => 'Tunjuk DPO secara formal melalui SK Direksi. DPO harus memiliki kualifikasi sesuai Pasal 53 ayat (3), melapor langsung ke pimpinan tertinggi, dan dilindungi dari pemecatan karena menjalankan tugasnya.',
            ],
            [
                'id' => 'TK-PO-02', 'category' => 'Tata Kelola', 'subcategory' => 'Data Protection Officer',
                'article' => 'Pasal 53-54', 'weight' => 4,
                'question' => 'Apakah DPO memiliki akses, sumber daya, dan kewenangan yang memadai untuk menjalankan tugasnya?',
                'explanation' => 'DPO harus memiliki akses ke semua data dan proses pemrosesan, sumber daya (staf, anggaran, tools), dan kewenangan untuk memberikan rekomendasi yang mengikat.',
                'recommendation' => 'Pastikan DPO memiliki: akses ke seluruh proses pemrosesan data, anggaran operasional, tim pendukung, dan otoritas untuk menghentikan pemrosesan yang tidak comply.',
            ],

            // --- Organisasi PDP ---
            [
                'id' => 'TK-OR-03', 'category' => 'Tata Kelola', 'subcategory' => 'Organisasi PDP',
                'article' => 'Pasal 47', 'weight' => 4,
                'question' => 'Apakah Organisasi telah membentuk struktur organisasi PDP dengan peran dan tanggung jawab yang jelas?',
                'explanation' => 'Struktur organisasi PDP yang jelas memastikan akuntabilitas dan tanggung jawab setiap pihak dalam pelindungan data pribadi.',
                'recommendation' => 'Buat struktur organisasi PDP yang jelas: DPO, Privacy Champions per departemen, IT Security, Legal, dan Compliance. Dokumentasikan RACI matrix.',
            ],

            // --- Manajemen Risiko PDP ---
            [
                'id' => 'TK-MR-01', 'category' => 'Tata Kelola', 'subcategory' => 'Manajemen Risiko PDP',
                'article' => 'Pasal 34', 'weight' => 5,
                'question' => 'Apakah Organisasi telah melakukan penilaian risiko (risk assessment) terhadap pemrosesan data pribadi secara berkala?',
                'explanation' => 'Risk assessment PDP adalah proses mengidentifikasi, menganalisis, dan mengevaluasi risiko terhadap data pribadi dari setiap aktivitas pemrosesan.',
                'recommendation' => 'Lakukan risk assessment minimal setahun sekali atau setiap ada perubahan signifikan dalam pemrosesan data. Gunakan metodologi 5x5 risk matrix (likelihood × impact).',
            ],

            // --- Manajemen Dasar Pemrosesan ---
            [
                'id' => 'TK-MDR-01', 'category' => 'Tata Kelola', 'subcategory' => 'Manajemen Dasar Pemrosesan',
                'article' => 'Pasal 20-21', 'weight' => 5,
                'question' => 'Apakah Organisasi telah mendokumentasikan dasar hukum untuk setiap aktivitas pemrosesan data pribadi?',
                'explanation' => 'Setiap aktivitas pemrosesan data pribadi harus memiliki salah satu dari dasar hukum yang sah menurut UU PDP: persetujuan, kontrak, kewajiban hukum, kepentingan vital, tugas publik, atau kepentingan sah.',
                'recommendation' => 'Buat register dasar pemrosesan yang mencakup setiap aktivitas pemrosesan beserta dasar hukumnya. Review secara berkala.',
            ],

            // --- Penyandang Disabilitas ---
            [
                'id' => 'TK-PD-01', 'category' => 'Tata Kelola', 'subcategory' => 'Penyandang Disabilitas',
                'article' => 'Pasal 25', 'weight' => 3,
                'question' => 'Apakah Organisasi memberikan pelindungan khusus terhadap data pribadi penyandang disabilitas?',
                'explanation' => 'UU PDP memberikan perlindungan khusus terhadap data pribadi penyandang disabilitas, termasuk aksesibilitas informasi dan mekanisme consent yang sesuai.',
                'recommendation' => 'Buat kebijakan khusus untuk pemrosesan data penyandang disabilitas, termasuk mekanisme consent yang aksesibel dan perlindungan tambahan.',
            ],

            // --- Anak ---
            [
                'id' => 'TK-PA-01', 'category' => 'Tata Kelola', 'subcategory' => 'Anak',
                'article' => 'Pasal 25', 'weight' => 4,
                'question' => 'Apakah Organisasi telah menerapkan mekanisme khusus untuk pemrosesan data pribadi anak?',
                'explanation' => 'Pemrosesan data anak memerlukan persetujuan dari orang tua/wali. UU PDP memberikan perlindungan khusus untuk data pribadi anak-anak.',
                'recommendation' => 'Implementasikan mekanisme verifikasi usia, parental consent, dan perlindungan tambahan untuk data anak. Batasi pengumpulan data anak seminimal mungkin.',
            ],

            // --- Tata Kelola dan Manajemen Data ---
            [
                'id' => 'TK-MD-01', 'category' => 'Tata Kelola', 'subcategory' => 'Tata Kelola dan Manajemen Data',
                'article' => 'Pasal 27-28', 'weight' => 4,
                'question' => 'Apakah Organisasi telah menerapkan prinsip Data Governance yang mencakup kualitas, integritas, dan ketersediaan data pribadi?',
                'explanation' => 'Data Governance memastikan data pribadi yang diproses akurat, lengkap, tidak menyesatkan, mutakhir, dan dapat dipertanggungjawabkan.',
                'recommendation' => 'Implementasikan framework Data Governance: data quality rules, data stewardship, master data management, dan data lineage tracking.',
            ],

            // ============================================
            // SIKLUS PROSES PDP - ASSESS
            // ============================================

            // --- Data Mapping/Pemetaan Data ---
            [
                'id' => 'SP-AS-DP-01', 'category' => 'Siklus Proses PDP', 'subcategory' => 'Assess',
                'article' => 'Pasal 4', 'weight' => 5,
                'question' => 'Apakah Organisasi telah melakukan identifikasi dan klasifikasi Data Pribadi dalam siklus pemrosesan Data Pribadi dengan melakukan pemetaan Data Pribadi terhadap sistem yang memproses Data Pribadi?',
                'explanation' => 'Pemetaan Data Pribadi adalah proses klasifikasi Data Pribadi berdasarkan sifat dan risiko. Data pribadi diklasifikasikan menjadi 2: Data Pribadi bersifat spesifik (kesehatan, biometrik, genetika, catatan kejahatan, data anak, data keuangan) dan Data Pribadi bersifat umum (nama, jenis kelamin, kewarganegaraan, agama, status perkawinan, dll).',
                'recommendation' => 'Lakukan data mapping menyeluruh: identifikasi semua data pribadi, klasifikasikan (umum vs spesifik), petakan ke sistem yang memprosesnya, dan dokumentasikan data flow.',
            ],
            [
                'id' => 'SP-AS-DP-02', 'category' => 'Siklus Proses PDP', 'subcategory' => 'Assess',
                'article' => 'Pasal 4', 'weight' => 4,
                'question' => 'Apakah Organisasi telah memiliki inventaris aset data (Data Asset Inventory) yang mencakup seluruh data pribadi yang diproses?',
                'explanation' => 'Data Asset Inventory adalah daftar lengkap semua aset data pribadi yang dimiliki organisasi, termasuk lokasi penyimpanan, format, volume, dan penanggung jawab.',
                'recommendation' => 'Buat dan kelola Data Asset Inventory yang mencakup: jenis data, sumber data, lokasi penyimpanan, format, volume, data owner, dan masa retensi.',
            ],

            // --- ROPA ---
            [
                'id' => 'SP-AS-RO-01', 'category' => 'Siklus Proses PDP', 'subcategory' => 'Assess',
                'article' => 'Pasal 31', 'weight' => 5,
                'question' => 'Apakah Organisasi telah memiliki Catatan Aktivitas Pemrosesan (Records of Processing Activities/ROPA)?',
                'explanation' => 'ROPA adalah catatan sistematis dari seluruh aktivitas pemrosesan data pribadi yang dilakukan oleh organisasi. Wajib dimiliki sesuai Pasal 31 UU PDP.',
                'recommendation' => 'Buat ROPA yang mencakup: tujuan pemrosesan, kategori subjek data, kategori data, penerima data, transfer ke luar negeri, masa retensi, dan deskripsi langkah keamanan.',
            ],

            // --- Flow of Information ---
            [
                'id' => 'SP-AS-FI-01', 'category' => 'Siklus Proses PDP', 'subcategory' => 'Assess',
                'article' => 'Pasal 27-28', 'weight' => 4,
                'question' => 'Apakah Organisasi telah mendokumentasikan alur informasi (Flow of Information) data pribadi mulai dari pengumpulan hingga pemusnahan?',
                'explanation' => 'Flow of Information menggambarkan siklus hidup lengkap data pribadi: dari titik pengumpulan, pemrosesan, penyimpanan, penggunaan, transfer, hingga pemusnahan.',
                'recommendation' => 'Buat data flow diagram untuk setiap proses bisnis yang melibatkan data pribadi. Dokumentasikan touchpoints, transfer, dan pihak yang mengakses.',
            ],

            // ============================================
            // SIKLUS PROSES PDP - PROTECT
            // ============================================

            // --- DPIA/Impact Assessment ---
            [
                'id' => 'SP-AS-IA-01', 'category' => 'Siklus Proses PDP', 'subcategory' => 'Protect',
                'article' => 'Pasal 34', 'weight' => 5,
                'question' => 'Apakah Organisasi telah melakukan Data Protection Impact Assessment (DPIA) untuk pemrosesan data berisiko tinggi?',
                'explanation' => 'DPIA wajib dilakukan untuk pemrosesan yang berisiko tinggi terhadap hak subjek data: pemrosesan otomatis/profiling, pemrosesan skala besar data spesifik, atau monitoring sistematis.',
                'recommendation' => 'Lakukan DPIA untuk setiap pemrosesan berisiko tinggi. Dokumentasikan: deskripsi pemrosesan, penilaian kebutuhan, risiko terhadap hak subjek data, dan langkah mitigasi.',
            ],

            // --- Third Party/Vendor Management ---
            [
                'id' => 'SP-AS-TP-01', 'category' => 'Siklus Proses PDP', 'subcategory' => 'Protect',
                'article' => 'Pasal 35', 'weight' => 5,
                'question' => 'Apakah Organisasi memiliki perjanjian pemrosesan data (Data Processing Agreement) dengan semua pihak ketiga yang memproses data pribadi?',
                'explanation' => 'DPA mengatur hubungan antara pengendali data dan pemroses data, termasuk kewajiban keamanan, batasan pemrosesan, dan tanggung jawab saat terjadi breach.',
                'recommendation' => 'Buat DPA dengan semua vendor/pemroses yang mencakup: ruang lingkup pemrosesan, kewajiban keamanan, notifikasi breach, sub-processor approval, dan right to audit.',
            ],

            // --- Marketing & Advertising ---
            [
                'id' => 'SP-AS-MA-01', 'category' => 'Siklus Proses PDP', 'subcategory' => 'Protect',
                'article' => 'Pasal 20-21', 'weight' => 4,
                'question' => 'Apakah Organisasi memperoleh consent yang spesifik untuk penggunaan data pribadi dalam aktivitas marketing dan advertising?',
                'explanation' => 'Penggunaan data pribadi untuk marketing memerlukan consent terpisah dan spesifik. Subjek data harus dapat opt-out dengan mudah.',
                'recommendation' => 'Implementasikan consent terpisah untuk marketing, sediakan mekanisme unsubscribe/opt-out di setiap komunikasi marketing, dan catat preferensi komunikasi.',
            ],

            // --- Data Transfer ---
            [
                'id' => 'SP-AS-TP-02', 'category' => 'Siklus Proses PDP', 'subcategory' => 'Protect',
                'article' => 'Pasal 56', 'weight' => 5,
                'question' => 'Apakah transfer data pribadi ke luar wilayah Indonesia telah memenuhi persyaratan UU PDP?',
                'explanation' => 'Transfer data lintas negara hanya diizinkan jika negara tujuan memiliki pelindungan data yang setara atau terdapat safeguard yang memadai (kontrak, BCR, atau SCCs).',
                'recommendation' => 'Lakukan Transfer Impact Assessment, pastikan negara tujuan memiliki regulasi PDP setara, dan gunakan Standard Contractual Clauses jika diperlukan.',
            ],

            // --- Privacy by Design ---
            [
                'id' => 'SP-PR-PD-01', 'category' => 'Siklus Proses PDP', 'subcategory' => 'Protect',
                'article' => 'Pasal 30', 'weight' => 4,
                'question' => 'Apakah Organisasi menerapkan prinsip Privacy by Design dan Privacy by Default dalam pengembangan sistem?',
                'explanation' => 'Privacy by Design memastikan pelindungan data pribadi dipertimbangkan sejak tahap perancangan sistem. Privacy by Default memastikan pengaturan privasi tertinggi menjadi default.',
                'recommendation' => 'Integrasikan PIA dalam SDLC, terapkan data minimization secara default, dan lakukan privacy review di setiap fase pengembangan.',
            ],

            // --- Privacy Notice ---
            [
                'id' => 'SP-PR-PN-01', 'category' => 'Siklus Proses PDP', 'subcategory' => 'Protect',
                'article' => 'Pasal 24', 'weight' => 5,
                'question' => 'Apakah Organisasi menyampaikan Privacy Notice yang jelas dan lengkap kepada subjek data sebelum pemrosesan?',
                'explanation' => 'Privacy Notice harus mencantumkan: identitas pengendali data, tujuan pemrosesan, jenis data, dasar hukum, hak subjek data, masa retensi, dan kontak DPO.',
                'recommendation' => 'Buat Privacy Notice yang jelas, mudah dipahami, dan dapat diakses. Sampaikan sebelum atau pada saat pengumpulan data.',
            ],

            // --- Information Security ---
            [
                'id' => 'SP-PR-IS-05', 'category' => 'Siklus Proses PDP', 'subcategory' => 'Protect',
                'article' => 'Pasal 35', 'weight' => 5,
                'question' => 'Apakah Organisasi menerapkan kontrol akses berbasis peran (RBAC) untuk data pribadi?',
                'explanation' => 'Access control memastikan hanya personil yang berwenang yang dapat mengakses data pribadi sesuai kebutuhan tugas (need-to-know basis).',
                'recommendation' => 'Implementasikan RBAC, least privilege principle, MFA untuk akses data sensitif, dan review akses secara berkala.',
            ],
            [
                'id' => 'SP-PR-IS-01', 'category' => 'Siklus Proses PDP', 'subcategory' => 'Protect',
                'article' => 'Pasal 35', 'weight' => 5,
                'question' => 'Apakah Organisasi memiliki kebijakan keamanan informasi (Information Security Policy) yang mencakup pelindungan data pribadi?',
                'explanation' => 'Kebijakan keamanan informasi harus mencakup: enkripsi, access control, logging, monitoring, backup, dan incident response khusus untuk data pribadi.',
                'recommendation' => 'Buat kebijakan keamanan informasi komprehensif yang mencakup: enkripsi (at-rest dan in-transit), access control, logging, monitoring, patch management, dan vulnerability assessment.',
            ],

            // --- Retention & Disposal ---
            [
                'id' => 'SP-PR-RD-01', 'category' => 'Siklus Proses PDP', 'subcategory' => 'Protect',
                'article' => 'Pasal 43-44', 'weight' => 4,
                'question' => 'Apakah Organisasi memiliki kebijakan retensi dan pemusnahan data pribadi yang terdokumentasi?',
                'explanation' => 'Data pribadi hanya boleh disimpan selama diperlukan untuk tujuan pemrosesan. Setelah masa retensi berakhir, data harus dihapus/dimusnahkan secara aman.',
                'recommendation' => 'Tetapkan masa retensi per kategori data, implementasikan mekanisme auto-deletion, dan gunakan metode pemusnahan yang aman (secure wipe).',
            ],

            // --- Mobile & Portability ---
            [
                'id' => 'SP-PR-MP-01', 'category' => 'Siklus Proses PDP', 'subcategory' => 'Protect',
                'article' => 'Pasal 11', 'weight' => 4,
                'question' => 'Apakah Organisasi mendukung hak portabilitas data (data portability) subjek data?',
                'explanation' => 'Subjek data berhak mendapatkan salinan data pribadinya dalam format yang terstruktur, umum digunakan, dan dapat dibaca mesin untuk dipindahkan ke pengendali lain.',
                'recommendation' => 'Implementasikan fitur export data dalam format standar (JSON/CSV). Sediakan mekanisme transfer langsung ke pengendali data lain jika diminta.',
            ],

            // --- Direct Marketing & DSR ---
            [
                'id' => 'SP-PR-DT-01', 'category' => 'Siklus Proses PDP', 'subcategory' => 'Protect',
                'article' => 'Pasal 5-13', 'weight' => 5,
                'question' => 'Apakah Organisasi memiliki mekanisme untuk menerima dan menangani permintaan hak subjek data (Data Subject Request)?',
                'explanation' => 'Organisasi harus menyediakan mekanisme untuk subjek data melaksanakan hak-haknya: akses, koreksi, penghapusan, pembatasan, portabilitas, dan keberatan pemrosesan.',
                'recommendation' => 'Buat portal/form DSR online, SOP penanganan DSR dengan SLA 3x24 jam, dan tracking system untuk setiap request.',
            ],

            // --- Profiling & Automated Decision Making ---
            [
                'id' => 'SP-PR-PB-01', 'category' => 'Siklus Proses PDP', 'subcategory' => 'Protect',
                'article' => 'Pasal 29', 'weight' => 4,
                'question' => 'Apakah Organisasi memberikan informasi dan pilihan kepada subjek data jika melakukan profiling atau automated decision making?',
                'explanation' => 'Jika organisasi melakukan profiling atau keputusan otomatis yang berdampak signifikan, subjek data berhak atas penjelasan, intervensi manusia, dan menyatakan keberatan.',
                'recommendation' => 'Informasikan kepada subjek data tentang adanya profiling, berikan hak untuk menolak, dan sediakan alternatif non-otomatis.',
            ],

            // --- Complaints Handling ---
            [
                'id' => 'SP-PR-CP-01', 'category' => 'Siklus Proses PDP', 'subcategory' => 'Protect',
                'article' => 'Pasal 47', 'weight' => 4,
                'question' => 'Apakah Organisasi memiliki mekanisme penanganan pengaduan (complaints handling) terkait pelindungan data pribadi?',
                'explanation' => 'Organisasi harus menyediakan saluran yang jelas untuk subjek data menyampaikan pengaduan terkait pemrosesan data pribadinya.',
                'recommendation' => 'Sediakan multi-channel complaints: email DPO, form online, hotline. Buat SOP penanganan dengan SLA respons dan eskalasi yang jelas.',
            ],

            // --- KPI Compliance ---
            [
                'id' => 'SP-SU-KP-01', 'category' => 'Siklus Proses PDP', 'subcategory' => 'Protect',
                'article' => 'Pasal 47', 'weight' => 3,
                'question' => 'Apakah Organisasi memiliki Key Performance Indicators (KPI) untuk mengukur efektivitas program pelindungan data pribadi?',
                'explanation' => 'KPI PDP membantu mengukur efektivitas implementasi program pelindungan data, termasuk: tingkat kepatuhan, jumlah insiden, waktu respons DSR, dan hasil audit.',
                'recommendation' => 'Tetapkan KPI: compliance score, DSR response time, breach count & response time, training completion rate, audit findings resolution rate.',
            ],

            // --- Privacy Policy (Implementation) ---
            [
                'id' => 'SP-PR-PD-02', 'category' => 'Siklus Proses PDP', 'subcategory' => 'Protect',
                'article' => 'Pasal 42', 'weight' => 4,
                'question' => 'Apakah Privacy Policy organisasi di-review dan diperbarui secara berkala?',
                'explanation' => 'Privacy Policy harus diperbarui setiap ada perubahan signifikan dalam pemrosesan data, regulasi, atau praktik bisnis. Dokumen versi lama harus diarsipkan.',
                'recommendation' => 'Review Privacy Policy minimal setiap 12 bulan atau saat ada perubahan signifikan. Simpan version history dan notifikasi subjek data tentang perubahan material.',
            ],

            // ============================================
            // SIKLUS PROSES PDP - RESPOND
            // ============================================

            // --- Incident Management ---
            [
                'id' => 'SP-RD-IM-02', 'category' => 'Siklus Proses PDP', 'subcategory' => 'Respond',
                'article' => 'Pasal 46', 'weight' => 5,
                'question' => 'Apakah Organisasi memiliki prosedur penanganan insiden (Incident Response Plan) yang mencakup kewajiban notifikasi dalam 3x24 jam?',
                'explanation' => 'UU PDP mewajibkan organisasi memberitahukan kegagalan pelindungan data pribadi kepada subjek data dan lembaga pengawas (KOMDIGI) dalam waktu 3x24 jam.',
                'recommendation' => 'Buat Incident Response Plan lengkap: deteksi, containment, assessment, notifikasi KOMDIGI + subjek data, remediasi, dan lessons learned. Lakukan drill berkala.',
            ],

            // --- Data Subject Rights ---
            [
                'id' => 'SP-RD-DS-01', 'category' => 'Siklus Proses PDP', 'subcategory' => 'Respond',
                'article' => 'Pasal 5-13', 'weight' => 5,
                'question' => 'Apakah Organisasi dapat memenuhi hak subjek data (akses, koreksi, penghapusan, pembatasan, portabilitas, keberatan) dalam batas waktu yang ditentukan?',
                'explanation' => 'Organisasi wajib merespons permintaan hak subjek data dalam batas waktu 3x24 jam. Hak-hak meliputi: informasi, akses, koreksi, hapus, batasi, portabilitas, dan keberatan.',
                'recommendation' => 'Bangun DSR workflow otomatis dengan SLA tracking, verifikasi identitas pemohon, dan template respons. Pastikan respons dalam 3x24 jam.',
            ],

            // ============================================
            // SIKLUS PROSES PDP - SUSTAIN
            // ============================================

            // --- Compliance Program ---
            [
                'id' => 'SP-SU-CP-01', 'category' => 'Siklus Proses PDP', 'subcategory' => 'Sustain',
                'article' => 'Pasal 47', 'weight' => 4,
                'question' => 'Apakah Organisasi memiliki program pelatihan dan awareness PDP yang berkelanjutan untuk seluruh karyawan?',
                'explanation' => 'Program pelatihan dan awareness memastikan seluruh karyawan memahami kewajiban PDP dan mampu menerapkan praktik pelindungan data dalam aktivitas sehari-hari.',
                'recommendation' => 'Adakan pelatihan PDP minimal 1x/tahun untuk seluruh karyawan. Buat program awareness: newsletter, phishing simulation, quiz, dan privacy champions.',
            ],

            // --- Privacy Audit ---
            [
                'id' => 'SP-SU-PA-01', 'category' => 'Siklus Proses PDP', 'subcategory' => 'Sustain',
                'article' => 'Pasal 55', 'weight' => 4,
                'question' => 'Apakah Organisasi melakukan audit pelindungan data pribadi secara berkala dengan independen?',
                'explanation' => 'Audit PDP yang independen memastikan efektivitas implementasi dan mengidentifikasi area yang perlu perbaikan. Bisa dilakukan internal audit atau pihak ketiga.',
                'recommendation' => 'Lakukan privacy audit minimal 1x/tahun. Libatkan auditor independen untuk audit eksternal. Dokumentasikan findings dan tindak lanjut.',
            ],
        ];
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class , 'org_id');
    }
}
