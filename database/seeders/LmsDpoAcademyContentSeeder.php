<?php

namespace Database\Seeders;

use App\Lms\Models\Course;
use App\Lms\Models\Module;
use App\Lms\Models\Lesson;
use App\Lms\Models\Quiz;
use App\Lms\Models\QuizQuestion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LmsDpoAcademyContentSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedCourseUpdpFundamentals();
        $this->seedCourseManajemenRisikoDataPribadi();
        $this->seedCourseAuditKepatuhanPdp();
        $this->seedCourseTataKelolaDataPribadi();
    }

    private function seedCourseUpdpFundamentals(): void
    {
        $course = Course::updateOrCreate(
            ['slug' => 'kepatuhan-uu-pdp-fundamentals'],
            [
                'org_id'           => null,
                'title'            => 'Kepatuhan UU PDP Fundamentals',
                'description'      => 'Pahami dasar-dasar UU No. 27 Tahun 2022 tentang Pelindungan Data Pribadi, prinsip-prinsip utama, dan kewajiban pengendali data. Kursus ini wajib bagi setiap DPO dan tim kepatuhan privasi.',
                'level'            => 'beginner',
                'duration_minutes' => 360,
                'regulation_code'  => 'UU_PDP',
                'thumbnail_url'    => '/images/courses/updp-fundamentals.png',
                'published'        => true,
                'order'            => 1,
                'created_by'       => null,
            ]
        );

        $this->seedModules($course, $this->updpFundamentalsModuleSpec());
        $this->seedFinalExam($course);
    }

    private function seedCourseManajemenRisikoDataPribadi(): void
    {
        $course = Course::updateOrCreate(
            ['slug' => 'manajemen-risiko-data-pribadi'],
            [
                'org_id'           => null,
                'title'            => 'Manajemen Risiko Data Pribadi',
                'description'      => 'Pelajari cara mengidentifikasi, menilai, dan memitigasi risiko privasi data — mulai dari risk assessment hingga implementasi kontrol teknis dan organisasional. Untuk DPO dan tim risiko.',
                'level'            => 'intermediate',
                'duration_minutes' => 480,
                'regulation_code'  => 'UU_PDP',
                'thumbnail_url'    => '/images/courses/manajemen-risiko-data-pribadi.png',
                'published'        => true,
                'order'            => 2,
                'created_by'       => null,
            ]
        );

        $this->seedModules($course, $this->manajemenRisikoModuleSpec());
    }

    private function seedCourseAuditKepatuhanPdp(): void
    {
        $course = Course::updateOrCreate(
            ['slug' => 'audit-kepatuhan-pdp'],
            [
                'org_id'           => null,
                'title'            => 'Audit Kepatuhan PDP',
                'description'      => 'Kuasai metodologi audit kepatuhan UU PDP — perencanaan, eksekusi, dokumentasi temuan, dan tindak lanjut. Untuk auditor internal, DPO, dan tim compliance.',
                'level'            => 'advanced',
                'duration_minutes' => 600,
                'regulation_code'  => 'UU_PDP',
                'thumbnail_url'    => '/images/courses/audit-kepatuhan-pdp.png',
                'published'        => true,
                'order'            => 3,
                'created_by'       => null,
            ]
        );

        $this->seedModules($course, $this->auditKepatuhanModuleSpec());
    }

    private function seedCourseTataKelolaDataPribadi(): void
    {
        $course = Course::updateOrCreate(
            ['slug' => 'tata-kelola-data-pribadi'],
            [
                'org_id'           => null,
                'title'            => 'Tata Kelola Data Pribadi',
                'description'      => 'Bangun kerangka governance privasi yang scalable — kebijakan, prosedur, struktur organisasi, dan akuntabilitas. Untuk DPO senior dan stakeholders manajemen.',
                'level'            => 'advanced',
                'duration_minutes' => 540,
                'regulation_code'  => 'UU_PDP',
                'thumbnail_url'    => '/images/courses/tata-kelola-data-pribadi.png',
                'published'        => true,
                'order'            => 4,
                'created_by'       => null,
            ]
        );

        $this->seedModules($course, $this->tataKelolaModuleSpec());
    }

    private function seedModules(Course $course, array $moduleSpec): void
    {
        $previousModuleId = null;
        foreach ($moduleSpec as $i => $modSpec) {
            $module = Module::updateOrCreate(
                ['course_id' => $course->id, 'slug' => $modSpec['slug']],
                [
                    'title'                   => $modSpec['title'],
                    'description'             => $modSpec['description'] ?? '',
                    'order'                   => $i + 1,
                    'unlock_after_module_id'  => $previousModuleId,
                ]
            );

            foreach ($modSpec['lessons'] as $j => $lessonSpec) {
                Lesson::updateOrCreate(
                    ['module_id' => $module->id, 'slug' => $lessonSpec['slug']],
                    [
                        'title'            => $lessonSpec['title'],
                        'body'             => $lessonSpec['body'],
                        'order'            => $j + 1,
                        'duration_seconds' => $lessonSpec['duration_seconds'],
                        'video_id'         => null,
                        'steps'            => $lessonSpec['steps'] ?? null,
                        'tips'             => $lessonSpec['tips'] ?? null,
                        'tags'             => $lessonSpec['tags'] ?? null,
                    ]
                );
            }

            $this->seedModuleQuiz($module, $modSpec);
            $previousModuleId = $module->id;
        }
    }

    private function seedModuleQuiz(Module $module, array $modSpec): void
    {
        if (empty($modSpec['quiz']['questions'])) {
            return;
        }

        $quiz = Quiz::updateOrCreate(
            ['owner_type' => 'module', 'owner_key' => (string) $module->id],
            [
                'passing_score'      => $modSpec['quiz']['passing_score'] ?? 70,
                'time_limit_seconds' => null,
                'max_attempts'       => null,
            ]
        );
        $expectedCount = count($modSpec['quiz']['questions']);
        if ($quiz->questions()->count() === $expectedCount) {
            return; // already seeded with the same set; skip
        }
        $isSqlite = DB::getDriverName() === 'sqlite';
        if ($isSqlite) {
            DB::statement('PRAGMA foreign_keys = OFF');
        }
        DB::table('lms_quiz_questions')->where('quiz_id', $quiz->id)->delete();
        if ($isSqlite) {
            DB::statement('PRAGMA foreign_keys = ON');
        }
        foreach ($modSpec['quiz']['questions'] as $k => $q) {
            QuizQuestion::create([
                'quiz_id'        => $quiz->id,
                'type'           => $q['type'],
                'prompt'         => $q['prompt'],
                'options'        => $q['options'] ?? null,
                'correct_answer' => $q['correct_answer'],
                'points'         => $q['points'] ?? 1,
                'order'          => $k + 1,
            ]);
        }
    }

    private function seedFinalExam(Course $course): void
    {
        $quiz = Quiz::updateOrCreate(
            ['owner_type' => 'course', 'owner_key' => (string) $course->id],
            [
                'passing_score'      => 80,
                'time_limit_seconds' => 3600,
                'max_attempts'       => 3,
            ]
        );
        $expectedCount = count($this->examQuestions());
        if ($quiz->questions()->count() === $expectedCount) {
            return; // already seeded with the same set; skip
        }
        $isSqlite = DB::getDriverName() === 'sqlite';
        if ($isSqlite) {
            DB::statement('PRAGMA foreign_keys = OFF');
        }
        DB::table('lms_quiz_questions')->where('quiz_id', $quiz->id)->delete();
        if ($isSqlite) {
            DB::statement('PRAGMA foreign_keys = ON');
        }
        foreach ($this->examQuestions() as $k => $q) {
            QuizQuestion::create([
                'quiz_id'        => $quiz->id,
                'type'           => $q['type'],
                'prompt'         => $q['prompt'],
                'options'        => $q['options'] ?? null,
                'correct_answer' => $q['correct_answer'],
                'points'         => $q['points'] ?? 1,
                'order'          => $k + 1,
            ]);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function htmlToMarkdown(string $html): string
    {
        $stripped = preg_replace('/\s*<p>\s*/', '', $html);
        $stripped = preg_replace('/\s*<\/p>\s*/', "\n\n", $stripped);
        return trim($stripped);
    }

    private function kebab(string $s): string
    {
        $clean = preg_replace('/[^A-Za-z0-9 ]+/', '', strtolower(\Illuminate\Support\Str::ascii($s)));
        return preg_replace('/\s+/', '-', trim($clean));
    }

    private function durationToSeconds(?string $s): ?int
    {
        if (!$s) {
            return null;
        }
        if (preg_match('/(\d+)\s*menit/iu', $s, $m)) {
            return ((int) $m[1]) * 60;
        }
        if (preg_match('/(\d+)\s*jam/iu', $s, $m)) {
            return ((int) $m[1]) * 3600;
        }
        return null;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Module specs — Course 1: UU PDP Fundamentals
    // ──────────────────────────────────────────────────────────────────────────

    private function updpFundamentalsModuleSpec(): array
    {
        return [

            // ── Module 1: Pengantar UU No. 27 Tahun 2022 ──────────────────────
            [
                'slug'        => $this->kebab('Pengantar UU No. 27 Tahun 2022'),
                'title'       => 'Pengantar UU No. 27 Tahun 2022',
                'description' => 'Memahami latar belakang, ruang lingkup, dan struktur UU Pelindungan Data Pribadi Indonesia.',
                'lessons'     => [
                    [
                        'slug'             => $this->kebab('Latar Belakang dan Sejarah UU PDP'),
                        'title'            => 'Latar Belakang dan Sejarah UU PDP',
                        'body'             => $this->htmlToMarkdown('
                              <p>UU No. 27 Tahun 2022 tentang Pelindungan Data Pribadi (UU PDP) merupakan tonggak penting dalam sejarah regulasi privasi di Indonesia. Sebelum UU ini, pelindungan data pribadi di Indonesia tersebar di berbagai regulasi sektoral seperti UU ITE, PP 71/2019, dan berbagai peraturan menteri.</p>
                              <p>UU PDP disahkan pada 17 Oktober 2022 dan memberikan masa transisi 2 tahun hingga Oktober 2024 bagi seluruh pengendali dan prosesor data untuk menyesuaikan praktik mereka. UU ini banyak mengadopsi prinsip-prinsip dari GDPR Eropa namun disesuaikan dengan konteks hukum dan sosial Indonesia.</p>
                              <p>Dengan UU PDP, Indonesia kini memiliki kerangka hukum komprehensif yang mengatur pengumpulan, pemrosesan, penyimpanan, dan penghapusan data pribadi. UU ini juga menetapkan hak-hak subjek data, kewajiban pengendali dan prosesor data, serta sanksi yang tegas bagi pelanggaran.</p>
                            '),
                        'duration_seconds' => $this->durationToSeconds('30 menit'),
                        'steps'            => [
                            ['order' => 1, 'title' => 'Pelajari Kronologi Regulasi', 'description' => 'Pahami evolusi regulasi privasi di Indonesia, dari UU ITE hingga UU PDP.'],
                            ['order' => 2, 'title' => 'Pahami Ruang Lingkup',         'description' => 'Identifikasi siapa saja yang tunduk pada UU PDP dan data apa yang dilindungi.'],
                            ['order' => 3, 'title' => 'Bandingkan dengan GDPR',       'description' => 'Kenali persamaan dan perbedaan utama antara UU PDP dan GDPR.'],
                        ],
                        'tips'             => [
                            'Buat ringkasan perbandingan UU PDP vs GDPR sebagai referensi cepat.',
                            'Catat tanggal-tanggal penting terkait masa transisi dan pemberlakuan penuh.',
                        ],
                        'tags'             => ['UU PDP', 'sejarah', 'regulasi', 'GDPR', 'Indonesia'],
                    ],
                    [
                        'slug'             => $this->kebab('Struktur dan Ruang Lingkup UU PDP'),
                        'title'            => 'Struktur dan Ruang Lingkup UU PDP',
                        'body'             => $this->htmlToMarkdown('
                              <p>UU PDP terdiri dari 76 pasal yang dikelompokkan dalam 16 bab. Bab-bab utama mencakup: Ketentuan Umum, Jenis Data Pribadi, Hak Subjek Data, Pemrosesan Data Pribadi, Kewajiban Pengendali dan Prosesor, Transfer Data Lintas Batas, dan Ketentuan Pidana.</p>
                              <p>UU PDP membedakan dua jenis data pribadi: data pribadi bersifat umum (nama, jenis kelamin, kewarganegaraan, agama, dll.) dan data pribadi bersifat spesifik (data kesehatan, biometrik, genetika, catatan kejahatan, data anak, data keuangan, dll.). Data spesifik mendapat perlindungan yang lebih ketat.</p>
                              <p>Ruang lingkup keberlakuan UU PDP bersifat ekstrateritorial — berlaku untuk setiap orang atau badan hukum yang memproses data pribadi warga negara Indonesia, baik yang berlokasi di dalam maupun di luar wilayah Indonesia.</p>
                            '),
                        'duration_seconds' => $this->durationToSeconds('30 menit'),
                        'steps'            => [
                            ['order' => 1, 'title' => 'Pelajari Definisi Kunci',    'description' => 'Pahami definisi data pribadi, pengendali data, prosesor data, dan subjek data.'],
                            ['order' => 2, 'title' => 'Identifikasi Jenis Data',    'description' => 'Klasifikasikan data yang diproses organisasi Anda ke dalam kategori umum dan spesifik.'],
                            ['order' => 3, 'title' => 'Evaluasi Keberlakuan',       'description' => 'Tentukan apakah organisasi Anda tunduk pada UU PDP dan dalam kapasitas apa.'],
                        ],
                        'tips'             => [
                            'Buat mapping jenis data yang diproses organisasi terhadap kategori UU PDP.',
                            'Perhatikan aspek ekstrateritorial jika organisasi beroperasi lintas negara.',
                            'Pahami perbedaan peran pengendali dan prosesor data karena kewajibannya berbeda.',
                        ],
                        'tags'             => ['UU PDP', 'struktur', 'ruang lingkup', 'definisi', 'data pribadi'],
                    ],
                    [
                        'slug'             => $this->kebab('Sanksi dan Penegakan UU PDP'),
                        'title'            => 'Sanksi dan Penegakan UU PDP',
                        'body'             => $this->htmlToMarkdown('
                              <p>UU PDP menetapkan sanksi yang cukup berat bagi pelanggaran, mencakup sanksi administratif dan sanksi pidana. Sanksi administratif meliputi peringatan tertulis, penghentian sementara pemrosesan data, penghapusan data, dan denda administratif hingga 2% dari pendapatan tahunan.</p>
                              <p>Sanksi pidana berlaku untuk pelanggaran serius seperti pengumpulan data secara melawan hukum (pidana penjara hingga 5 tahun dan/atau denda hingga Rp5 miliar), pengungkapan data pribadi secara melawan hukum (pidana penjara hingga 4 tahun dan/atau denda hingga Rp4 miliar), dan pemalsuan data pribadi (pidana penjara hingga 6 tahun dan/atau denda hingga Rp6 miliar).</p>
                              <p>Penegakan UU PDP akan dilakukan oleh lembaga pelindungan data pribadi yang dibentuk berdasarkan UU ini. Lembaga ini memiliki kewenangan untuk melakukan penyelidikan, audit, dan penjatuhan sanksi administratif.</p>
                            '),
                        'duration_seconds' => $this->durationToSeconds('25 menit'),
                        'steps'            => [
                            ['order' => 1, 'title' => 'Pelajari Jenis Sanksi',          'description' => 'Pahami perbedaan antara sanksi administratif dan sanksi pidana.'],
                            ['order' => 2, 'title' => 'Identifikasi Risiko Organisasi', 'description' => 'Evaluasi area mana dalam organisasi yang berisiko terkena sanksi.'],
                            ['order' => 3, 'title' => 'Siapkan Strategi Mitigasi',      'description' => 'Susun langkah-langkah untuk meminimalkan risiko pelanggaran.'],
                        ],
                        'tips'             => [
                            'Komunikasikan risiko sanksi kepada manajemen untuk mendapatkan dukungan program kepatuhan.',
                            'Dokumentasikan semua upaya kepatuhan sebagai bukti itikad baik (good faith).',
                        ],
                        'tags'             => ['UU PDP', 'sanksi', 'pidana', 'administratif', 'penegakan'],
                    ],
                ],
                // quiz-updp-fundamentals → module_id: 'academy-updp-mod-1'
                'quiz'        => [
                    'passing_score' => 70,
                    'questions'     => [
                        [
                            'type'           => 'mcq',
                            'prompt'         => 'UU No. 27 Tahun 2022 tentang Pelindungan Data Pribadi disahkan pada tanggal berapa?',
                            'options'        => [
                                ['key' => 'a', 'label' => '17 Agustus 2022'],
                                ['key' => 'b', 'label' => '17 Oktober 2022'],
                                ['key' => 'c', 'label' => '1 Januari 2023'],
                                ['key' => 'd', 'label' => '27 November 2022'],
                            ],
                            'correct_answer' => ['b'],
                            'points'         => 1,
                        ],
                        [
                            'type'           => 'mcq',
                            'prompt'         => 'Manakah yang termasuk data pribadi bersifat spesifik menurut UU PDP?',
                            'options'        => [
                                ['key' => 'a', 'label' => 'Data kesehatan'],
                                ['key' => 'b', 'label' => 'Nama lengkap'],
                                ['key' => 'c', 'label' => 'Data biometrik'],
                                ['key' => 'd', 'label' => 'Data keuangan pribadi'],
                            ],
                            'correct_answer' => ['a', 'c', 'd'],
                            'points'         => 1,
                        ],
                        [
                            'type'           => 'mcq',
                            'prompt'         => 'Berapa denda administratif maksimal yang dapat dikenakan berdasarkan UU PDP?',
                            'options'        => [
                                ['key' => 'a', 'label' => '1% dari pendapatan tahunan'],
                                ['key' => 'b', 'label' => '2% dari pendapatan tahunan'],
                                ['key' => 'c', 'label' => '4% dari pendapatan tahunan'],
                                ['key' => 'd', 'label' => 'Rp10 miliar'],
                            ],
                            'correct_answer' => ['b'],
                            'points'         => 1,
                        ],
                        [
                            'type'           => 'mcq',
                            'prompt'         => 'Apa yang dimaksud dengan prinsip "purpose limitation" dalam UU PDP?',
                            'options'        => [
                                ['key' => 'a', 'label' => 'Membatasi jumlah data yang dikumpulkan'],
                                ['key' => 'b', 'label' => 'Data hanya boleh diproses sesuai tujuan yang telah ditentukan saat pengumpulan'],
                                ['key' => 'c', 'label' => 'Membatasi akses data hanya untuk DPO'],
                                ['key' => 'd', 'label' => 'Menghapus data setelah tujuan tercapai'],
                            ],
                            'correct_answer' => ['b'],
                            'points'         => 1,
                        ],
                        [
                            'type'           => 'mcq',
                            'prompt'         => 'Berapa lama waktu yang diberikan UU PDP kepada pengendali data untuk merespons Data Subject Request (DSR)?',
                            'options'        => [
                                ['key' => 'a', 'label' => '24 jam'],
                                ['key' => 'b', 'label' => '3 x 24 jam untuk konfirmasi, 14 hari kerja untuk penyelesaian'],
                                ['key' => 'c', 'label' => '30 hari kalender'],
                                ['key' => 'd', 'label' => '90 hari kalender'],
                            ],
                            'correct_answer' => ['b'],
                            'points'         => 1,
                        ],
                    ],
                ],
            ],

            // ── Module 2: Prinsip Dasar Pelindungan Data ──────────────────────
            [
                'slug'        => $this->kebab('Prinsip Dasar Pelindungan Data'),
                'title'       => 'Prinsip Dasar Pelindungan Data',
                'description' => 'Memahami prinsip-prinsip fundamental yang menjadi landasan pemrosesan data pribadi yang sah.',
                'lessons'     => [
                    [
                        'slug'             => $this->kebab('Prinsip Pemrosesan Data Pribadi'),
                        'title'            => 'Prinsip Pemrosesan Data Pribadi',
                        'body'             => $this->htmlToMarkdown('
                              <p>UU PDP menetapkan sejumlah prinsip yang harus dipatuhi dalam pemrosesan data pribadi. Prinsip-prinsip ini meliputi: pengumpulan secara terbatas dan spesifik (purpose limitation), pemrosesan sesuai tujuan (lawfulness), akurasi dan kelengkapan data, pemberitahuan yang memadai, dan jangka waktu penyimpanan yang terbatas.</p>
                              <p>Prinsip purpose limitation mengharuskan data pribadi hanya dikumpulkan untuk tujuan yang spesifik, eksplisit, dan sah. Data tidak boleh diproses lebih lanjut dengan cara yang tidak sesuai dengan tujuan pengumpulan awal tanpa persetujuan baru dari subjek data.</p>
                              <p>Prinsip data minimization mewajibkan organisasi hanya mengumpulkan data yang benar-benar diperlukan untuk tujuan pemrosesan. Ini berarti organisasi perlu mengevaluasi setiap field data yang dikumpulkan dan memastikan ada justifikasi yang jelas.</p>
                            '),
                        'duration_seconds' => $this->durationToSeconds('30 menit'),
                        'steps'            => [
                            ['order' => 1, 'title' => 'Inventarisasi Prinsip',      'description' => 'Buat daftar semua prinsip pemrosesan data yang ditetapkan UU PDP.'],
                            ['order' => 2, 'title' => 'Evaluasi Praktik Saat Ini', 'description' => 'Bandingkan praktik pemrosesan data organisasi dengan setiap prinsip.'],
                            ['order' => 3, 'title' => 'Identifikasi Kesenjangan',  'description' => 'Catat area di mana praktik belum sesuai dengan prinsip yang ditetapkan.'],
                        ],
                        'tips'             => [
                            'Terapkan prinsip "privacy by design" sejak awal perancangan sistem atau proses baru.',
                            'Buat privacy notice yang jelas untuk setiap aktivitas pengumpulan data.',
                            'Review kebijakan retensi data secara berkala untuk memastikan kesesuaian.',
                        ],
                        'tags'             => ['prinsip', 'pemrosesan data', 'purpose limitation', 'data minimization'],
                    ],
                    [
                        'slug'             => $this->kebab('Dasar Hukum Pemrosesan Data'),
                        'title'            => 'Dasar Hukum Pemrosesan Data',
                        'body'             => $this->htmlToMarkdown('
                              <p>UU PDP menetapkan beberapa dasar hukum yang sah untuk pemrosesan data pribadi. Yang paling umum adalah persetujuan (consent) subjek data, pelaksanaan perjanjian, kewajiban hukum, kepentingan vital, pelaksanaan tugas dalam kepentingan umum, dan kepentingan sah (legitimate interest) pengendali data.</p>
                              <p>Setiap dasar hukum memiliki syarat dan batasan yang berbeda. Misalnya, consent harus diberikan secara bebas, spesifik, berdasarkan informasi yang memadai, dan merupakan pernyataan tegas. Sementara legitimate interest memerlukan balancing test antara kepentingan pengendali dan hak subjek data.</p>
                              <p>Pemilihan dasar hukum yang tepat sangat penting karena menentukan hak-hak subjek data yang berlaku dan kewajiban tambahan yang harus dipenuhi pengendali data. Dokumentasi dasar hukum untuk setiap aktivitas pemrosesan juga menjadi bagian wajib dari ROPA.</p>
                            '),
                        'duration_seconds' => $this->durationToSeconds('30 menit'),
                        'steps'            => [
                            ['order' => 1, 'title' => 'Identifikasi Dasar Hukum',   'description' => 'Untuk setiap aktivitas pemrosesan, tentukan dasar hukum yang paling tepat.'],
                            ['order' => 2, 'title' => 'Dokumentasikan Justifikasi', 'description' => 'Catat alasan pemilihan dasar hukum tertentu untuk setiap pemrosesan.'],
                            ['order' => 3, 'title' => 'Review Secara Berkala',      'description' => 'Evaluasi apakah dasar hukum yang digunakan masih valid seiring perubahan konteks.'],
                        ],
                        'tips'             => [
                            'Jangan hanya mengandalkan consent sebagai dasar hukum — pertimbangkan alternatif lain yang mungkin lebih tepat.',
                            'Lakukan Legitimate Interest Assessment (LIA) setiap kali menggunakan kepentingan sah sebagai dasar hukum.',
                        ],
                        'tags'             => ['dasar hukum', 'consent', 'legitimate interest', 'pemrosesan'],
                    ],
                ],
                // Authored quiz for academy-updp-mod-2 (Prinsip Dasar Pelindungan Data).
                'quiz'        => [
                    'passing_score' => 70,
                    'questions'     => [
                        [
                            'type'           => 'mcq',
                            'prompt'         => 'Manakah berikut ini yang BUKAN merupakan dasar hukum pemrosesan data pribadi yang sah menurut UU PDP?',
                            'options'        => [
                                ['key' => 'a', 'label' => 'Persetujuan yang sah dari subjek data'],
                                ['key' => 'b', 'label' => 'Pemenuhan kewajiban perjanjian dengan subjek data'],
                                ['key' => 'c', 'label' => 'Kepentingan sah (legitimate interest) pengendali data'],
                                ['key' => 'd', 'label' => 'Keinginan pengendali memperbanyak data tanpa tujuan tertentu'],
                            ],
                            'correct_answer' => ['d'],
                            'points'         => 1,
                        ],
                        [
                            'type'           => 'mcq',
                            'prompt'         => 'Sebelum menggunakan kepentingan sah (legitimate interest) sebagai dasar hukum, organisasi sebaiknya melakukan...',
                            'options'        => [
                                ['key' => 'a', 'label' => 'Legitimate Interest Assessment (LIA) / balancing test'],
                                ['key' => 'b', 'label' => 'Transfer data ke luar negeri'],
                                ['key' => 'c', 'label' => 'Penghapusan seluruh data lama'],
                                ['key' => 'd', 'label' => 'Pengumpulan data sebanyak mungkin'],
                            ],
                            'correct_answer' => ['a'],
                            'points'         => 1,
                        ],
                        [
                            'type'           => 'mcq',
                            'prompt'         => 'Manakah yang termasuk prinsip pemrosesan data pribadi menurut UU PDP? (pilih semua yang benar)',
                            'options'        => [
                                ['key' => 'a', 'label' => 'Pembatasan tujuan (purpose limitation)'],
                                ['key' => 'b', 'label' => 'Minimalisasi data (data minimization)'],
                                ['key' => 'c', 'label' => 'Akurasi data'],
                                ['key' => 'd', 'label' => 'Mengumpulkan data sebanyak-banyaknya untuk berjaga-jaga'],
                            ],
                            'correct_answer' => ['a', 'b', 'c'],
                            'points'         => 1,
                        ],
                    ],
                ],
            ],

            // ── Module 3: Kewajiban Pengendali Data ───────────────────────────
            [
                'slug'        => $this->kebab('Kewajiban Pengendali Data'),
                'title'       => 'Kewajiban Pengendali Data',
                'description' => 'Memahami seluruh kewajiban yang harus dipenuhi oleh pengendali data berdasarkan UU PDP.',
                'lessons'     => [
                    [
                        'slug'             => $this->kebab('Kewajiban Utama Pengendali Data'),
                        'title'            => 'Kewajiban Utama Pengendali Data',
                        'body'             => $this->htmlToMarkdown('
                              <p>Pengendali data memiliki sejumlah kewajiban utama berdasarkan UU PDP. Ini meliputi: memastikan keakuratan data, melindungi data dari akses tidak sah, menyediakan mekanisme penghapusan data, menunjuk DPO (Data Protection Officer) untuk organisasi tertentu, dan melakukan DPIA untuk pemrosesan berisiko tinggi.</p>
                              <p>Pengendali data juga wajib menyediakan privacy notice yang jelas dan mudah dipahami sebelum mengumpulkan data pribadi. Privacy notice harus mencakup identitas pengendali, tujuan pemrosesan, jenis data yang dikumpulkan, hak-hak subjek data, dan informasi kontak DPO.</p>
                              <p>Kewajiban penting lainnya adalah menjaga keamanan data pribadi melalui penerapan langkah-langkah teknis dan organisatoris yang memadai. Standar keamanan harus disesuaikan dengan sensitivitas data yang diproses dan risiko yang mungkin timbul.</p>
                            '),
                        'duration_seconds' => $this->durationToSeconds('30 menit'),
                        'steps'            => [
                            ['order' => 1, 'title' => 'Inventarisasi Kewajiban', 'description' => 'Buat checklist seluruh kewajiban pengendali data berdasarkan UU PDP.'],
                            ['order' => 2, 'title' => 'Evaluasi Kepatuhan',      'description' => 'Nilai tingkat kepatuhan organisasi terhadap setiap kewajiban.'],
                            ['order' => 3, 'title' => 'Susun Program Kepatuhan', 'description' => 'Rancang program kerja untuk memenuhi seluruh kewajiban yang belum terpenuhi.'],
                        ],
                        'tips'             => [
                            'Pastikan DPO memiliki akses langsung ke manajemen puncak.',
                            'Dokumentasikan semua kebijakan dan prosedur terkait pelindungan data.',
                            'Alokasikan anggaran yang memadai untuk program kepatuhan.',
                        ],
                        'tags'             => ['pengendali data', 'kewajiban', 'DPO', 'privacy notice', 'keamanan'],
                    ],
                    [
                        'slug'             => $this->kebab('Hak Subjek Data dan Cara Pemenuhannya'),
                        'title'            => 'Hak Subjek Data dan Cara Pemenuhannya',
                        'body'             => $this->htmlToMarkdown('
                              <p>UU PDP memberikan sejumlah hak kepada subjek data, antara lain: hak untuk mendapatkan informasi, hak untuk mengakses data, hak untuk memperbaiki kesalahan data, hak untuk menghapus data, hak untuk membatasi pemrosesan, hak atas portabilitas data, dan hak untuk mengajukan keberatan.</p>
                              <p>Organisasi wajib menyediakan mekanisme yang mudah diakses bagi subjek data untuk menggunakan hak-hak mereka. Ini termasuk menyediakan kanal penerimaan permintaan (DSR portal, email, atau formulir), prosedur verifikasi identitas, dan proses penanganan yang terstruktur.</p>
                              <p>Setiap permintaan hak subjek data harus ditanggapi dalam jangka waktu yang ditetapkan UU PDP. Jika permintaan ditolak, organisasi wajib memberikan alasan penolakan yang jelas beserta informasi tentang hak subjek data untuk mengajukan keberatan kepada lembaga pelindungan data pribadi.</p>
                            '),
                        'duration_seconds' => $this->durationToSeconds('30 menit'),
                        'steps'            => [
                            ['order' => 1, 'title' => 'Pelajari Setiap Hak',          'description' => 'Pahami secara mendalam setiap hak subjek data dan implikasinya.'],
                            ['order' => 2, 'title' => 'Siapkan Mekanisme Pemenuhan', 'description' => 'Bangun prosedur dan sistem untuk menangani setiap jenis permintaan hak.'],
                            ['order' => 3, 'title' => 'Latih Tim Terkait',            'description' => 'Pastikan tim yang menangani DSR memahami prosedur dan tenggat waktu.'],
                        ],
                        'tips'             => [
                            'Buat SOP terperinci untuk penanganan setiap jenis hak subjek data.',
                            'Siapkan template respons untuk mempercepat proses penanganan DSR.',
                            'Monitor SLA penanganan DSR dan laporkan secara berkala kepada manajemen.',
                        ],
                        'tags'             => ['hak subjek data', 'DSR', 'akses', 'penghapusan', 'portabilitas'],
                    ],
                    [
                        'slug'             => $this->kebab('Transfer Data Lintas Batas'),
                        'title'            => 'Transfer Data Lintas Batas',
                        'body'             => $this->htmlToMarkdown('
                              <p>UU PDP mengatur secara ketat transfer data pribadi ke luar wilayah Republik Indonesia. Transfer hanya diperbolehkan jika negara tujuan memiliki tingkat pelindungan data yang setara atau lebih tinggi dari Indonesia, atau jika terdapat perjanjian internasional yang mengatur hal tersebut.</p>
                              <p>Jika transfer ke negara yang tidak memiliki tingkat pelindungan setara, pengendali data harus memastikan adanya safeguards yang memadai, seperti Standard Contractual Clauses (SCC), Binding Corporate Rules (BCR), atau persetujuan eksplisit dari subjek data.</p>
                              <p>Pengendali data bertanggung jawab untuk melakukan due diligence terhadap negara tujuan dan penerima data, serta mendokumentasikan penilaian kecukupan pelindungan. Dokumentasi ini harus tersedia untuk diperiksa oleh regulator sewaktu-waktu.</p>
                            '),
                        'duration_seconds' => $this->durationToSeconds('25 menit'),
                        'steps'            => [
                            ['order' => 1, 'title' => 'Identifikasi Transfer Data', 'description' => 'Mapping seluruh aliran data yang melintasi batas negara.'],
                            ['order' => 2, 'title' => 'Evaluasi Negara Tujuan',     'description' => 'Nilai tingkat pelindungan data di setiap negara tujuan transfer.'],
                            ['order' => 3, 'title' => 'Siapkan Safeguards',         'description' => 'Implementasikan mekanisme perlindungan yang tepat untuk setiap transfer.'],
                        ],
                        'tips'             => [
                            'Perhatikan penggunaan layanan cloud — server di luar negeri termasuk transfer data lintas batas.',
                            'Siapkan data transfer map yang menunjukkan aliran data ke dan dari luar negeri.',
                        ],
                        'tags'             => ['transfer data', 'lintas batas', 'cross-border', 'SCC', 'safeguards'],
                    ],
                ],
                // Authored quiz for academy-updp-mod-3 (Kewajiban Pengendali Data).
                'quiz'        => [
                    'passing_score' => 70,
                    'questions'     => [
                        [
                            'type'           => 'mcq',
                            'prompt'         => 'Manakah yang merupakan kewajiban pengendali data berdasarkan UU PDP? (pilih semua yang benar)',
                            'options'        => [
                                ['key' => 'a', 'label' => 'Menyediakan privacy notice yang jelas sebelum mengumpulkan data'],
                                ['key' => 'b', 'label' => 'Menunjuk DPO untuk kategori organisasi tertentu'],
                                ['key' => 'c', 'label' => 'Melakukan DPIA untuk pemrosesan berisiko tinggi'],
                                ['key' => 'd', 'label' => 'Menjual data pribadi subjek tanpa pemberitahuan'],
                            ],
                            'correct_answer' => ['a', 'b', 'c'],
                            'points'         => 1,
                        ],
                        [
                            'type'           => 'mcq',
                            'prompt'         => 'Hak subjek data untuk memperoleh dan memindahkan data pribadinya dalam format yang dapat dibaca mesin disebut hak...',
                            'options'        => [
                                ['key' => 'a', 'label' => 'Hak akses'],
                                ['key' => 'b', 'label' => 'Hak penghapusan'],
                                ['key' => 'c', 'label' => 'Hak atas portabilitas data'],
                                ['key' => 'd', 'label' => 'Hak untuk mengajukan keberatan'],
                            ],
                            'correct_answer' => ['c'],
                            'points'         => 1,
                        ],
                        [
                            'type'           => 'mcq',
                            'prompt'         => 'Transfer data pribadi ke luar wilayah Indonesia menurut UU PDP diperbolehkan apabila...',
                            'options'        => [
                                ['key' => 'a', 'label' => 'Negara tujuan memiliki tingkat pelindungan setara atau lebih tinggi'],
                                ['key' => 'b', 'label' => 'Terdapat safeguards memadai seperti SCC atau BCR'],
                                ['key' => 'c', 'label' => 'Terdapat persetujuan eksplisit dari subjek data'],
                                ['key' => 'd', 'label' => 'Semua jawaban di atas benar'],
                            ],
                            'correct_answer' => ['d'],
                            'points'         => 1,
                        ],
                    ],
                ],
            ],
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Final exam questions (hand-written, 5 questions)
    // ──────────────────────────────────────────────────────────────────────────

    private function examQuestions(): array
    {
        return [
            [
                'type'           => 'mcq',
                'prompt'         => 'UU No. 27 Tahun 2022 disahkan pada tanggal berapa?',
                'options'        => [
                    ['key' => 'a', 'label' => '17 Oktober 2022'],
                    ['key' => 'b', 'label' => '17 Agustus 2022'],
                    ['key' => 'c', 'label' => '1 Januari 2023'],
                    ['key' => 'd', 'label' => '17 Oktober 2024'],
                ],
                'correct_answer' => ['a'],
                'points'         => 2,
            ],
            [
                'type'           => 'mcq',
                'prompt'         => 'Berapa lama masa transisi yang diberikan UU PDP setelah pengesahan?',
                'options'        => [
                    ['key' => 'a', 'label' => '1 tahun'],
                    ['key' => 'b', 'label' => '2 tahun'],
                    ['key' => 'c', 'label' => '3 tahun'],
                    ['key' => 'd', 'label' => '5 tahun'],
                ],
                'correct_answer' => ['b'],
                'points'         => 2,
            ],
            [
                'type'           => 'true_false',
                'prompt'         => 'UU PDP berlaku ekstrateritorial — yaitu juga untuk badan hukum di luar Indonesia yang memproses data WNI.',
                'options'        => null,
                'correct_answer' => [true],
                'points'         => 2,
            ],
            [
                'type'           => 'mcq',
                'prompt'         => 'Mana yang termasuk data pribadi bersifat spesifik menurut UU PDP?',
                'options'        => [
                    ['key' => 'a', 'label' => 'Nama lengkap'],
                    ['key' => 'b', 'label' => 'Data biometrik'],
                    ['key' => 'c', 'label' => 'Alamat email'],
                    ['key' => 'd', 'label' => 'Nomor telepon'],
                ],
                'correct_answer' => ['b'],
                'points'         => 2,
            ],
            [
                'type'           => 'true_false',
                'prompt'         => 'Pengendali data wajib mendokumentasikan seluruh aktivitas pemrosesan data pribadi (ROPA).',
                'options'        => null,
                'correct_answer' => [true],
                'points'         => 2,
            ],
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Module specs — Course 2: Manajemen Risiko Data Pribadi
    // ──────────────────────────────────────────────────────────────────────────

    private function manajemenRisikoModuleSpec(): array
    {
        return [
            // ── Module 1: Kerangka Manajemen Risiko Privasi ────────────────────
            [
                'slug'        => $this->kebab('Kerangka Manajemen Risiko Privasi'),
                'title'       => 'Kerangka Manajemen Risiko Privasi',
                'description' => 'Memahami pendekatan risk-based dalam pelindungan data pribadi serta kerangka kerja internasional yang menjadi rujukan.',
                'lessons'     => [
                    [
                        'slug'             => $this->kebab('Prinsip Risk-Based Approach'),
                        'title'            => 'Prinsip Risk-Based Approach',
                        'body'             => $this->htmlToMarkdown('
                              <p>Pendekatan risk-based merupakan paradigma fundamental dalam manajemen privasi modern. UU PDP, GDPR, dan kerangka internasional lainnya secara konsisten mengharuskan pengendali data untuk mengalokasikan sumber daya pelindungan secara proporsional terhadap tingkat risiko yang dihadapi subjek data.</p>
                              <p>Dengan pendekatan ini, kontrol yang ketat diterapkan pada aktivitas pemrosesan berisiko tinggi (misalnya profiling otomatis, pemrosesan data anak, atau data kesehatan dalam skala besar), sementara aktivitas berisiko rendah dapat dikelola dengan kontrol yang lebih ringan. Hal ini memastikan efisiensi tanpa mengorbankan kepatuhan.</p>
                              <p>Praktik di lapangan: setiap aktivitas pemrosesan dievaluasi menggunakan kombinasi likelihood (kemungkinan terjadinya insiden) dan impact (dampak terhadap subjek data). Hasilnya adalah risk register yang menjadi dasar prioritas kontrol — bukan sekadar checklist generik.</p>
                            '),
                        'duration_seconds' => $this->durationToSeconds('20 menit'),
                        'steps'            => [
                            ['order' => 1, 'title' => 'Pahami Konsep Risiko Privasi', 'description' => 'Definisikan likelihood dan impact dalam konteks pelindungan data pribadi.'],
                            ['order' => 2, 'title' => 'Susun Risk Register',           'description' => 'Buat daftar aktivitas pemrosesan beserta skor risikonya.'],
                            ['order' => 3, 'title' => 'Prioritaskan Kontrol',          'description' => 'Alokasikan sumber daya berdasarkan ranking risiko, bukan urutan abjad.'],
                        ],
                        'tips'             => [
                            'Gunakan skala 1-5 untuk likelihood dan impact agar mudah dipahami stakeholders non-teknis.',
                            'Review risk register minimal setiap kuartal atau saat ada perubahan signifikan.',
                        ],
                        'tags'             => ['risk-based', 'risk-register', 'prioritization'],
                    ],
                    [
                        'slug'             => $this->kebab('ISO IEC 27701 Overview'),
                        'title'            => 'ISO/IEC 27701 Overview',
                        'body'             => $this->htmlToMarkdown('
                              <p>ISO/IEC 27701 adalah ekstensi dari ISO/IEC 27001 yang secara spesifik mengatur Privacy Information Management System (PIMS). Standar ini memberikan kerangka kerja terstruktur untuk membangun, menerapkan, memelihara, dan meningkatkan sistem manajemen informasi privasi.</p>
                              <p>Inti dari ISO 27701 adalah pemetaan kontrol terhadap peran pengendali data (PII Controller) dan prosesor data (PII Processor). Setiap kontrol dirancang untuk memenuhi kewajiban yang berbeda — misalnya pengendali wajib menentukan tujuan dan dasar hukum, sedangkan prosesor wajib memberikan jaminan keamanan dan tidak menggunakan data di luar instruksi pengendali.</p>
                              <p>Bagi organisasi Indonesia, sertifikasi ISO 27701 dapat menjadi bukti due diligence yang kuat di hadapan regulator UU PDP. Standar ini juga memudahkan transfer data lintas batas karena diakui secara internasional.</p>
                            '),
                        'duration_seconds' => $this->durationToSeconds('25 menit'),
                        'steps'            => [
                            ['order' => 1, 'title' => 'Pelajari Struktur Standar',    'description' => 'Kenali Annex A (kontrol pengendali) dan Annex B (kontrol prosesor).'],
                            ['order' => 2, 'title' => 'Lakukan Gap Assessment',       'description' => 'Bandingkan kontrol organisasi dengan persyaratan ISO 27701.'],
                            ['order' => 3, 'title' => 'Susun Roadmap Sertifikasi',    'description' => 'Tentukan timeline dan budget untuk mencapai sertifikasi.'],
                        ],
                        'tips'             => [
                            'Jika organisasi sudah ISO 27001-certified, ekstensi 27701 jauh lebih murah dan cepat.',
                            'Libatkan auditor eksternal lebih awal untuk pre-assessment.',
                        ],
                        'tags'             => ['iso-27701', 'pims', 'sertifikasi'],
                    ],
                    [
                        'slug'             => $this->kebab('NIST Privacy Framework'),
                        'title'            => 'NIST Privacy Framework',
                        'body'             => $this->htmlToMarkdown('
                              <p>NIST Privacy Framework adalah kerangka kerja sukarela yang dikembangkan oleh National Institute of Standards and Technology Amerika Serikat. Berbeda dengan ISO 27701 yang preskriptif, NIST PF lebih fleksibel dan berorientasi pada outcome — cocok untuk organisasi yang membutuhkan adaptasi tinggi.</p>
                              <p>Kerangka ini terdiri dari tiga komponen utama: Core (lima fungsi: Identify-P, Govern-P, Control-P, Communicate-P, Protect-P), Profiles (kondisi saat ini vs target), dan Implementation Tiers (level kematangan dari Partial hingga Adaptive).</p>
                              <p>Banyak organisasi multinasional menggunakan NIST PF sebagai bahasa bersama lintas yurisdiksi karena pemetaannya mudah dihubungkan dengan GDPR, UU PDP, CCPA, dan regulasi lain. NIST PF juga menyediakan crosswalk resmi ke beberapa regulasi utama.</p>
                            '),
                        'duration_seconds' => $this->durationToSeconds('20 menit'),
                        'steps'            => [
                            ['order' => 1, 'title' => 'Kuasai Lima Fungsi Core',     'description' => 'Pahami tujuan Identify-P, Govern-P, Control-P, Communicate-P, Protect-P.'],
                            ['order' => 2, 'title' => 'Buat Current Profile',         'description' => 'Petakan kondisi pelindungan privasi organisasi saat ini.'],
                            ['order' => 3, 'title' => 'Definisikan Target Profile',  'description' => 'Tentukan tingkat kematangan yang ingin dicapai.'],
                        ],
                        'tips'             => [
                            'Gunakan NIST PF crosswalk untuk memetakan kontrol ke UU PDP secara efisien.',
                            'Implementation Tier bukan rating — pilih sesuai kapasitas dan risiko organisasi.',
                        ],
                        'tags'             => ['nist', 'privacy-framework', 'maturity'],
                    ],
                ],
                'quiz'        => [
                    'passing_score' => 70,
                    'questions'     => [
                        [
                            'type'           => 'mcq',
                            'prompt'         => 'Apa inti dari pendekatan risk-based dalam pelindungan data?',
                            'options'        => [
                                ['key' => 'a', 'label' => 'Mengaplikasikan kontrol yang sama untuk semua aktivitas pemrosesan'],
                                ['key' => 'b', 'label' => 'Mengalokasikan kontrol secara proporsional terhadap tingkat risiko'],
                                ['key' => 'c', 'label' => 'Menghapus seluruh data berisiko tinggi'],
                                ['key' => 'd', 'label' => 'Memindahkan data ke negara dengan regulasi lebih longgar'],
                            ],
                            'correct_answer' => ['b'],
                            'points'         => 2,
                        ],
                        [
                            'type'           => 'true_false',
                            'prompt'         => 'ISO/IEC 27701 adalah ekstensi dari ISO/IEC 27001 yang khusus mengatur Privacy Information Management System.',
                            'options'        => null,
                            'correct_answer' => [true],
                            'points'         => 2,
                        ],
                        [
                            'type'           => 'mcq',
                            'prompt'         => 'Lima fungsi Core NIST Privacy Framework adalah:',
                            'options'        => [
                                ['key' => 'a', 'label' => 'Plan-Do-Check-Act-Review'],
                                ['key' => 'b', 'label' => 'Identify-P, Govern-P, Control-P, Communicate-P, Protect-P'],
                                ['key' => 'c', 'label' => 'Collect, Store, Process, Share, Delete'],
                                ['key' => 'd', 'label' => 'Confidentiality, Integrity, Availability, Authenticity, Non-repudiation'],
                            ],
                            'correct_answer' => ['b'],
                            'points'         => 2,
                        ],
                    ],
                ],
            ],

            // ── Module 2: Risk Assessment & DPIA ───────────────────────────────
            [
                'slug'        => $this->kebab('Risk Assessment dan DPIA'),
                'title'       => 'Risk Assessment & DPIA',
                'description' => 'Menguasai metodologi Data Protection Impact Assessment dan kapan wajib dilakukan.',
                'lessons'     => [
                    [
                        'slug'             => $this->kebab('Kapan DPIA Wajib Dilakukan'),
                        'title'            => 'Kapan DPIA Wajib Dilakukan',
                        'body'             => $this->htmlToMarkdown('
                              <p>UU PDP Pasal 33 mewajibkan pengendali data melakukan Data Protection Impact Assessment (DPIA) sebelum melakukan pemrosesan yang berpotensi menimbulkan risiko tinggi terhadap subjek data. DPIA bukan formalitas — ini adalah analisis proaktif untuk mengidentifikasi dan memitigasi risiko sebelum sistem berjalan.</p>
                              <p>Trigger DPIA umumnya meliputi: pemrosesan data dalam skala besar, profiling otomatis dengan dampak hukum, pemrosesan data spesifik (kesehatan, biometrik, anak), monitoring sistematis area publik, kombinasi atau pencocokan dataset, dan penggunaan teknologi baru seperti AI atau IoT.</p>
                              <p>Banyak organisasi gagal melakukan DPIA karena tidak memiliki proses screening awal. Sebaiknya setiap proyek baru yang melibatkan data pribadi melalui pre-DPIA assessment singkat (10-15 pertanyaan) untuk menentukan apakah DPIA penuh diperlukan.</p>
                            '),
                        'duration_seconds' => $this->durationToSeconds('20 menit'),
                        'steps'            => [
                            ['order' => 1, 'title' => 'Buat DPIA Trigger List',       'description' => 'Definisikan kriteria yang otomatis memicu DPIA.'],
                            ['order' => 2, 'title' => 'Implementasikan Pre-DPIA',     'description' => 'Tambahkan checklist screening di proses project intake.'],
                            ['order' => 3, 'title' => 'Tetapkan Owner DPIA',          'description' => 'DPO bertanggung jawab atas review, tapi project owner yang menulisnya.'],
                        ],
                        'tips'             => [
                            'Integrasikan trigger DPIA ke project management tool (Jira/ClickUp) sebagai checklist wajib.',
                            'Simpan log keputusan "tidak perlu DPIA" — itu bukti due diligence.',
                        ],
                        'tags'             => ['dpia', 'trigger', 'risk-assessment'],
                    ],
                    [
                        'slug'             => $this->kebab('Metodologi DPIA'),
                        'title'            => 'Metodologi DPIA',
                        'body'             => $this->htmlToMarkdown('
                              <p>DPIA yang baik mengikuti lima tahap: (1) deskripsi sistematis aktivitas pemrosesan, (2) penilaian necessity dan proportionality, (3) identifikasi dan analisis risiko terhadap hak subjek data, (4) penentuan tindakan mitigasi, dan (5) konsultasi dengan stakeholders termasuk DPO dan subjek data jika perlu.</p>
                              <p>Pada tahap 3, gunakan matriks risiko 2 dimensi (likelihood × severity). Severity mempertimbangkan dampak terhadap subjek data — bukan dampak terhadap organisasi. Pelanggaran data kesehatan untuk 10 orang lebih severe daripada pelanggaran data nama untuk 10.000 orang.</p>
                              <p>Hasil DPIA harus didokumentasikan secara formal dan disetujui oleh DPO sebelum sistem beroperasi. Jika residual risk masih tinggi setelah mitigasi, kasus harus dieskalasi ke lembaga pelindungan data sebelum pemrosesan dimulai (prior consultation).</p>
                            '),
                        'duration_seconds' => $this->durationToSeconds('25 menit'),
                        'steps'            => [
                            ['order' => 1, 'title' => 'Deskripsikan Pemrosesan',      'description' => 'Buat data flow diagram lengkap dengan jenis data, aktor, dan sistem.'],
                            ['order' => 2, 'title' => 'Nilai Necessity dan Proportionality', 'description' => 'Tanyakan: apakah pemrosesan ini benar-benar perlu? Apakah ada cara yang less-invasive?'],
                            ['order' => 3, 'title' => 'Skor Risiko',                  'description' => 'Gunakan matriks likelihood × severity berbasis dampak ke subjek data.'],
                            ['order' => 4, 'title' => 'Definisikan Mitigasi',         'description' => 'Tentukan kontrol untuk setiap risiko yang teridentifikasi.'],
                            ['order' => 5, 'title' => 'Approval dan Dokumentasi',     'description' => 'DPO sign-off; arsipkan dokumen DPIA selama minimal 5 tahun.'],
                        ],
                        'tips'             => [
                            'Gunakan template DPIA standar (misalnya dari CNIL atau ICO) sebagai starting point.',
                            'Libatkan stakeholders teknis sejak awal — engineer paham risiko teknis lebih dalam.',
                        ],
                        'tags'             => ['dpia', 'metodologi', 'mitigation'],
                    ],
                    [
                        'slug'             => $this->kebab('Case Study DPIA'),
                        'title'            => 'Case Study DPIA',
                        'body'             => $this->htmlToMarkdown('
                              <p>Studi kasus: sebuah e-commerce ingin meluncurkan fitur "personalisasi harga" berbasis ML, di mana harga produk akan berbeda untuk setiap user berdasarkan perilaku browsing, lokasi, perangkat, dan riwayat transaksi. Apakah ini memicu DPIA? Ya — ini termasuk profiling otomatis dengan dampak ekonomi.</p>
                              <p>Dalam DPIA, tim mengidentifikasi risiko: (1) diskriminasi harga tersembunyi (severity: tinggi, likelihood: tinggi), (2) reverse engineering algoritma oleh kompetitor (severity: rendah ke organisasi, irrelevant ke subjek data), (3) ketidakmampuan user untuk meminta penjelasan harga (severity: sedang, likelihood: tinggi).</p>
                              <p>Mitigasi yang diadopsi: transparansi via privacy notice yang menjelaskan bahwa harga dapat berbeda, opt-out mechanism, audit fairness model setiap kuartal, dan dashboard internal yang memonitor disparitas harga across demographic groups. Residual risk dinilai sedang dan diterima oleh manajemen dengan persetujuan DPO.</p>
                            '),
                        'duration_seconds' => $this->durationToSeconds('20 menit'),
                        'steps'            => [
                            ['order' => 1, 'title' => 'Pelajari Skenario',            'description' => 'Pahami konteks bisnis dan teknis dari case study.'],
                            ['order' => 2, 'title' => 'Identifikasi Risiko',          'description' => 'Latihan: daftar minimal 5 risiko terhadap subjek data.'],
                            ['order' => 3, 'title' => 'Rancang Mitigasi',             'description' => 'Untuk setiap risiko, tentukan kontrol yang feasible.'],
                        ],
                        'tips'             => [
                            'DPIA bukan dokumen sekali jadi — update setiap kali ada perubahan signifikan pada sistem.',
                            'Diskusi dengan tim engineering sering mengungkap risiko teknis yang tidak terlihat dari sisi bisnis.',
                        ],
                        'tags'             => ['dpia', 'case-study', 'profiling'],
                    ],
                ],
                'quiz'        => [
                    'passing_score' => 70,
                    'questions'     => [
                        [
                            'type'           => 'mcq',
                            'prompt'         => 'Pasal berapa di UU PDP yang mewajibkan DPIA untuk pemrosesan berisiko tinggi?',
                            'options'        => [
                                ['key' => 'a', 'label' => 'Pasal 27'],
                                ['key' => 'b', 'label' => 'Pasal 33'],
                                ['key' => 'c', 'label' => 'Pasal 45'],
                                ['key' => 'd', 'label' => 'Pasal 51'],
                            ],
                            'correct_answer' => ['b'],
                            'points'         => 2,
                        ],
                        [
                            'type'           => 'true_false',
                            'prompt'         => 'Severity dalam DPIA dinilai berdasarkan dampak terhadap subjek data, bukan terhadap organisasi.',
                            'options'        => null,
                            'correct_answer' => [true],
                            'points'         => 2,
                        ],
                        [
                            'type'           => 'mcq',
                            'prompt'         => 'Manakah yang TIDAK termasuk trigger umum DPIA?',
                            'options'        => [
                                ['key' => 'a', 'label' => 'Pemrosesan data biometrik'],
                                ['key' => 'b', 'label' => 'Profiling otomatis dengan dampak hukum'],
                                ['key' => 'c', 'label' => 'Pengumpulan nama untuk daftar tamu rapat internal'],
                                ['key' => 'd', 'label' => 'Monitoring sistematis area publik'],
                            ],
                            'correct_answer' => ['c'],
                            'points'         => 2,
                        ],
                    ],
                ],
            ],

            // ── Module 3: Mitigasi & Kontrol ───────────────────────────────────
            [
                'slug'        => $this->kebab('Mitigasi dan Kontrol'),
                'title'       => 'Mitigasi & Kontrol',
                'description' => 'Implementasi kontrol teknis dan organisasional untuk memitigasi risiko privasi yang teridentifikasi.',
                'lessons'     => [
                    [
                        'slug'             => $this->kebab('Kontrol Teknis Enkripsi dan Pseudonymisasi'),
                        'title'            => 'Kontrol Teknis: Enkripsi & Pseudonymisasi',
                        'body'             => $this->htmlToMarkdown('
                              <p>Enkripsi adalah kontrol teknis paling fundamental dalam pelindungan data pribadi. UU PDP secara eksplisit menyebutkan enkripsi sebagai salah satu langkah keamanan yang harus dipertimbangkan. Praktik baik: enkripsi at-rest (AES-256) untuk database dan backup, enkripsi in-transit (TLS 1.3) untuk komunikasi, dan key management yang terisolasi dari data.</p>
                              <p>Pseudonymisasi adalah teknik mengganti identifier langsung (nama, NIK) dengan token atau hash, sehingga data tidak dapat dikaitkan ke individu tanpa informasi tambahan yang disimpan terpisah. Berbeda dengan anonymisasi (irreversible), pseudonymisasi tetap memungkinkan re-identifikasi untuk keperluan sah.</p>
                              <p>Kombinasi enkripsi + pseudonymisasi sangat powerful untuk pemrosesan analytics. Tim analitik dapat bekerja dengan dataset pseudonymous tanpa risiko mengakses identitas asli, sementara tim operasional yang membutuhkan re-identifikasi memiliki access control terpisah ke mapping table.</p>
                            '),
                        'duration_seconds' => $this->durationToSeconds('20 menit'),
                        'steps'            => [
                            ['order' => 1, 'title' => 'Inventarisasi Data Sensitif',  'description' => 'Petakan di mana data spesifik disimpan dan diproses.'],
                            ['order' => 2, 'title' => 'Terapkan Enkripsi at-Rest',    'description' => 'Aktifkan TDE di database; enkripsi backup; enkripsi file system untuk file sensitif.'],
                            ['order' => 3, 'title' => 'Pseudonymisasi Dataset Analitik', 'description' => 'Ganti PII langsung dengan token sebelum data masuk ke data warehouse.'],
                        ],
                        'tips'             => [
                            'Key management adalah weakest link — gunakan HSM atau cloud KMS, jangan hardcode key.',
                            'Pseudonymisasi tetap masuk ruang lingkup UU PDP — bukan exemption.',
                        ],
                        'tags'             => ['enkripsi', 'pseudonymisasi', 'kontrol-teknis'],
                    ],
                    [
                        'slug'             => $this->kebab('Kontrol Organisasional'),
                        'title'            => 'Kontrol Organisasional',
                        'body'             => $this->htmlToMarkdown('
                              <p>Kontrol organisasional sering diabaikan padahal sama pentingnya dengan kontrol teknis. Ini meliputi kebijakan tertulis, prosedur operasional, role-based access control (RBAC) berbasis prinsip least privilege, pemisahan tugas (segregation of duties), dan program awareness berkelanjutan.</p>
                              <p>Praktik baik RBAC: tidak ada user yang mendapat akses default ke data pribadi — akses harus di-request dan di-approve berdasarkan job role. Review akses dilakukan setiap kuartal; akses dicabut otomatis saat user pindah role atau resign. Privileged access (admin database, sysadmin) wajib menggunakan MFA dan dicatat ke audit log.</p>
                              <p>Program awareness bukan sekedar slide deck setahun sekali. Praktik terbaik: onboarding privacy training (mandatory, with quiz) untuk semua karyawan baru, refresher tahunan untuk seluruh staf, role-specific training untuk DPO, IT, dan customer service, plus phishing simulation kuartalan.</p>
                            '),
                        'duration_seconds' => $this->durationToSeconds('20 menit'),
                        'steps'            => [
                            ['order' => 1, 'title' => 'Audit Access Matrix',          'description' => 'Identifikasi siapa saat ini memiliki akses ke data pribadi apa.'],
                            ['order' => 2, 'title' => 'Terapkan Least Privilege',     'description' => 'Cabut akses yang tidak dibutuhkan untuk job role saat ini.'],
                            ['order' => 3, 'title' => 'Rancang Program Awareness',    'description' => 'Susun calendar training tahunan dengan tracking completion.'],
                        ],
                        'tips'             => [
                            'Joiner-Mover-Leaver process (JML) harus terintegrasi dengan HRIS untuk otomatisasi.',
                            'Privacy champion di setiap departemen lebih efektif daripada DPO sentralistik.',
                        ],
                        'tags'             => ['rbac', 'awareness', 'kontrol-organisasional'],
                    ],
                    [
                        'slug'             => $this->kebab('Monitoring dan Residual Risk'),
                        'title'            => 'Monitoring & Residual Risk',
                        'body'             => $this->htmlToMarkdown('
                              <p>Mitigasi tidak menghilangkan risiko sepenuhnya — yang tersisa setelah kontrol diterapkan disebut residual risk. Manajemen wajib memformalkan tingkat residual risk yang dapat diterima organisasi (risk appetite) dan memastikan setiap aktivitas pemrosesan berada di bawah threshold tersebut.</p>
                              <p>Monitoring berkelanjutan diperlukan karena risiko bersifat dinamis: regulasi baru, teknologi baru, supplier baru, perubahan organisasi, semua dapat mengubah profil risiko. Praktik baik: KRI (Key Risk Indicators) untuk privasi seperti jumlah DSR yang melebihi SLA, jumlah insiden minor, persentase staf yang completed training, jumlah temuan audit.</p>
                              <p>Reporting privasi ke manajemen puncak harus berkala (minimal kuartalan) dengan dashboard yang menampilkan: risk register dengan trend, KRI dengan threshold, status mitigasi terbuka, insiden bulan ini, dan upcoming regulatory changes. Tanpa visibility ini, manajemen tidak dapat membuat keputusan investasi yang informed.</p>
                            '),
                        'duration_seconds' => $this->durationToSeconds('15 menit'),
                        'steps'            => [
                            ['order' => 1, 'title' => 'Definisikan Risk Appetite',    'description' => 'Manajemen menetapkan batas residual risk yang dapat diterima.'],
                            ['order' => 2, 'title' => 'Pilih KRI yang Relevan',       'description' => 'Maksimal 8-10 KRI; lebih dari itu menjadi noise.'],
                            ['order' => 3, 'title' => 'Siapkan Dashboard',            'description' => 'Bangun dashboard privasi yang ter-update real-time atau minimal bulanan.'],
                        ],
                        'tips'             => [
                            'Risk appetite harus tertulis dan disetujui board — bukan asumsi.',
                            'KRI yang tidak pernah merah selama setahun mungkin perlu di-tighten thresholdnya.',
                        ],
                        'tags'             => ['monitoring', 'residual-risk', 'kri'],
                    ],
                ],
                'quiz'        => [
                    'passing_score' => 70,
                    'questions'     => [
                        [
                            'type'           => 'mcq',
                            'prompt'         => 'Apa perbedaan utama antara pseudonymisasi dan anonymisasi?',
                            'options'        => [
                                ['key' => 'a', 'label' => 'Tidak ada perbedaan, keduanya istilah yang sama'],
                                ['key' => 'b', 'label' => 'Pseudonymisasi reversible dengan informasi tambahan; anonymisasi irreversible'],
                                ['key' => 'c', 'label' => 'Pseudonymisasi hanya untuk data biometrik'],
                                ['key' => 'd', 'label' => 'Anonymisasi tetap masuk ruang lingkup UU PDP'],
                            ],
                            'correct_answer' => ['b'],
                            'points'         => 2,
                        ],
                        [
                            'type'           => 'true_false',
                            'prompt'         => 'Data yang sudah di-pseudonymisasi tidak lagi tunduk pada UU PDP.',
                            'options'        => null,
                            'correct_answer' => [false],
                            'points'         => 2,
                        ],
                        [
                            'type'           => 'mcq',
                            'prompt'         => 'Manakah contoh terbaik dari Key Risk Indicator (KRI) untuk pelindungan data?',
                            'options'        => [
                                ['key' => 'a', 'label' => 'Jumlah karyawan total'],
                                ['key' => 'b', 'label' => 'Persentase DSR yang ditangani melebihi SLA'],
                                ['key' => 'c', 'label' => 'Pendapatan kuartalan'],
                                ['key' => 'd', 'label' => 'Jumlah kantor cabang'],
                            ],
                            'correct_answer' => ['b'],
                            'points'         => 2,
                        ],
                    ],
                ],
            ],
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Module specs — Course 3: Audit Kepatuhan PDP
    // ──────────────────────────────────────────────────────────────────────────

    private function auditKepatuhanModuleSpec(): array
    {
        return [
            // ── Module 1: Perencanaan Audit Kepatuhan ──────────────────────────
            [
                'slug'        => $this->kebab('Perencanaan Audit Kepatuhan'),
                'title'       => 'Perencanaan Audit Kepatuhan',
                'description' => 'Membangun fondasi audit kepatuhan PDP yang efektif: charter, scope, dan kompetensi tim.',
                'lessons'     => [
                    [
                        'slug'             => $this->kebab('Audit Charter'),
                        'title'            => 'Audit Charter',
                        'body'             => $this->htmlToMarkdown('
                              <p>Audit charter adalah dokumen formal yang menetapkan tujuan, kewenangan, dan tanggung jawab fungsi audit. Untuk audit kepatuhan PDP, charter ini harus secara eksplisit menyatakan bahwa auditor memiliki akses tanpa batas ke seluruh data, sistem, dan personel yang relevan dengan pelindungan data pribadi.</p>
                              <p>Charter juga harus mengatur independensi auditor — secara organisasional, audit harus melapor ke board atau audit committee, bukan ke unit yang diauditnya. Auditor PDP tidak boleh merangkap sebagai DPO atau bagian dari tim implementasi, untuk menghindari self-review threat.</p>
                              <p>Charter ditandatangani oleh board dan ditinjau minimal tahunan. Praktik baik: review charter setelah setiap perubahan regulasi besar atau restrukturisasi organisasi yang signifikan.</p>
                            '),
                        'duration_seconds' => $this->durationToSeconds('15 menit'),
                        'steps'            => [
                            ['order' => 1, 'title' => 'Susun Draft Charter',          'description' => 'Sertakan tujuan, scope, kewenangan akses, dan reporting line.'],
                            ['order' => 2, 'title' => 'Review oleh Legal',            'description' => 'Pastikan charter konsisten dengan UU PDP dan kebijakan internal.'],
                            ['order' => 3, 'title' => 'Approve oleh Board',           'description' => 'Charter wajib disetujui board atau audit committee.'],
                        ],
                        'tips'             => [
                            'Gunakan template IIA (Institute of Internal Auditors) sebagai starting point.',
                            'Charter yang baik melindungi auditor dari tekanan operasional saat menemukan temuan sensitif.',
                        ],
                        'tags'             => ['audit', 'charter', 'governance'],
                    ],
                    [
                        'slug'             => $this->kebab('Scope dan Risk-Based Audit Plan'),
                        'title'            => 'Scope & Risk-Based Audit Plan',
                        'body'             => $this->htmlToMarkdown('
                              <p>Audit plan tahunan harus berbasis risiko — bukan rotasi mekanis "setiap unit diaudit setiap tahun". Risiko tinggi (misalnya unit yang baru implementasi sistem CRM, atau yang sebelumnya pernah ada insiden) diaudit lebih sering dan lebih dalam.</p>
                              <p>Scope audit kepatuhan PDP umumnya mencakup: ROPA (Record of Processing Activities), implementasi hak subjek data, manajemen konsen, kontrol akses, manajemen vendor (DPA dengan prosesor), incident response, transfer data lintas batas, dan training awareness. Untuk audit pertama kali, scope sering kali "wall-to-wall" — semua area dasar.</p>
                              <p>Audit plan harus menyertakan estimasi waktu, anggaran, dan resource. Diskusikan dengan management agar tidak terjadi konflik dengan agenda operasional besar (misalnya audit di tengah peak season e-commerce). Plan ini di-approve audit committee sebelum eksekusi dimulai.</p>
                            '),
                        'duration_seconds' => $this->durationToSeconds('20 menit'),
                        'steps'            => [
                            ['order' => 1, 'title' => 'Lakukan Risk Assessment',      'description' => 'Identifikasi unit/proses dengan risiko PDP tertinggi.'],
                            ['order' => 2, 'title' => 'Definisikan Scope per Audit',  'description' => 'Tentukan area, periode, dan kedalaman testing.'],
                            ['order' => 3, 'title' => 'Susun Annual Plan',            'description' => 'Petakan timeline 12 bulan dengan resource allocation.'],
                        ],
                        'tips'             => [
                            'Plan boleh disesuaikan di tengah tahun jika ada perubahan risiko material — fleksibilitas adalah kekuatan.',
                            'Sisakan 15-20% kapasitas untuk ad-hoc audit (insiden, regulator request).',
                        ],
                        'tags'             => ['scope', 'audit-plan', 'risk-based'],
                    ],
                    [
                        'slug'             => $this->kebab('Audit Team dan Competencies'),
                        'title'            => 'Audit Team & Competencies',
                        'body'             => $this->htmlToMarkdown('
                              <p>Audit kepatuhan PDP memerlukan tim multidisiplin: pemahaman regulasi (UU PDP, regulasi sektoral), keahlian audit (sampling, evidence collection, reporting), serta pengetahuan teknis (sistem, database, keamanan informasi). Jarang satu orang menguasai semua — komposisi tim yang baik mencampurkan profil ini.</p>
                              <p>Sertifikasi yang relevan untuk auditor PDP meliputi CIPP/E atau CIPP/A (privacy), CISA (audit IT), CIA (audit umum), dan sertifikasi DPO lokal. Untuk audit yang menyentuh teknologi spesifik (cloud, AI), pertimbangkan certifications tambahan seperti CCSP atau AI ethics certificates.</p>
                              <p>Continuing professional education (CPE) wajib dilakukan minimal 40 jam per tahun untuk menjaga kompetensi. Update regulasi sangat cepat — auditor yang tidak rajin training akan ketinggalan dalam 12-18 bulan.</p>
                            '),
                        'duration_seconds' => $this->durationToSeconds('15 menit'),
                        'steps'            => [
                            ['order' => 1, 'title' => 'Petakan Skill Tim Saat Ini',   'description' => 'Identifikasi gap kompetensi terhadap kebutuhan audit.'],
                            ['order' => 2, 'title' => 'Rancang Training Plan',        'description' => 'Tentukan sertifikasi dan training untuk masing-masing anggota tim.'],
                            ['order' => 3, 'title' => 'Pertimbangkan Co-sourcing',    'description' => 'Untuk skill yang langka, kombinasikan internal team + external consultant.'],
                        ],
                        'tips'             => [
                            'Rotasi auditor antar area mencegah complacency dan memperluas pengetahuan tim.',
                            'Investasi sertifikasi: ROI muncul dalam kualitas audit dan kredibilitas di mata regulator.',
                        ],
                        'tags'             => ['team', 'competencies', 'sertifikasi'],
                    ],
                ],
                'quiz'        => [
                    'passing_score' => 70,
                    'questions'     => [
                        [
                            'type'           => 'mcq',
                            'prompt'         => 'Mengapa auditor PDP tidak boleh merangkap sebagai DPO?',
                            'options'        => [
                                ['key' => 'a', 'label' => 'Karena UU PDP melarangnya secara eksplisit'],
                                ['key' => 'b', 'label' => 'Untuk menghindari self-review threat yang mengompromikan independensi'],
                                ['key' => 'c', 'label' => 'Karena DPO tidak memiliki keahlian audit'],
                                ['key' => 'd', 'label' => 'Tidak ada alasan khusus, bisa saja'],
                            ],
                            'correct_answer' => ['b'],
                            'points'         => 2,
                        ],
                        [
                            'type'           => 'true_false',
                            'prompt'         => 'Audit plan tahunan idealnya berbasis risiko, bukan rotasi mekanis setiap unit.',
                            'options'        => null,
                            'correct_answer' => [true],
                            'points'         => 2,
                        ],
                        [
                            'type'           => 'mcq',
                            'prompt'         => 'Manakah sertifikasi yang paling relevan khusus untuk privacy auditor?',
                            'options'        => [
                                ['key' => 'a', 'label' => 'PMP'],
                                ['key' => 'b', 'label' => 'CISSP'],
                                ['key' => 'c', 'label' => 'CIPP/A atau CIPP/E'],
                                ['key' => 'd', 'label' => 'CFA'],
                            ],
                            'correct_answer' => ['c'],
                            'points'         => 2,
                        ],
                    ],
                ],
            ],

            // ── Module 2: Eksekusi Audit ───────────────────────────────────────
            [
                'slug'        => $this->kebab('Eksekusi Audit'),
                'title'       => 'Eksekusi Audit',
                'description' => 'Teknik praktis untuk mengumpulkan evidence, melakukan wawancara, dan menguji kontrol.',
                'lessons'     => [
                    [
                        'slug'             => $this->kebab('Evidence Collection Techniques'),
                        'title'            => 'Evidence Collection Techniques',
                        'body'             => $this->htmlToMarkdown('
                              <p>Kualitas audit ditentukan oleh kualitas evidence. Empat jenis evidence utama: (1) physical evidence (screenshot sistem, log file), (2) documentary evidence (kebijakan, prosedur, kontrak), (3) testimonial evidence (hasil wawancara, written confirmation), dan (4) analytical evidence (hasil analisis data, rekonsiliasi).</p>
                              <p>Hierarki kekuatan evidence: evidence dari sumber eksternal independen lebih kuat daripada internal; evidence written lebih kuat daripada oral; evidence dari sumber yang kontrolnya baik lebih kuat daripada sumber yang kontrolnya lemah; dan evidence yang dikumpulkan auditor sendiri lebih kuat daripada yang disediakan auditee.</p>
                              <p>Setiap evidence harus didokumentasikan dengan metadata: tanggal pengumpulan, sumber, metode pengumpulan, dan tanda tangan auditor yang mengumpulkan. Working paper menjadi formal record yang bisa di-review oleh pihak ketiga (peer review, external audit, atau regulator) bertahun-tahun setelah audit.</p>
                            '),
                        'duration_seconds' => $this->durationToSeconds('20 menit'),
                        'steps'            => [
                            ['order' => 1, 'title' => 'Tentukan Jenis Evidence',      'description' => 'Untuk setiap audit objective, identifikasi evidence yang paling tepat.'],
                            ['order' => 2, 'title' => 'Susun Evidence Request List',  'description' => 'Daftar dokumen dan data yang dibutuhkan dari auditee.'],
                            ['order' => 3, 'title' => 'Dokumentasikan ke Working Paper', 'description' => 'Simpan dengan metadata lengkap untuk traceability.'],
                        ],
                        'tips'             => [
                            'Jangan terima evidence verbal saja — minta written confirmation untuk hal-hal kritis.',
                            'Working paper yang rapi menghemat waktu saat regulator melakukan inspeksi.',
                        ],
                        'tags'             => ['evidence', 'working-paper', 'audit-trail'],
                    ],
                    [
                        'slug'             => $this->kebab('Interview dan Walkthrough'),
                        'title'            => 'Interview & Walkthrough',
                        'body'             => $this->htmlToMarkdown('
                              <p>Wawancara adalah teknik audit yang powerful tapi sering disalahgunakan. Wawancara yang baik: terstruktur dengan pertanyaan terbuka, fokus pada fakta bukan opini, didokumentasikan dalam interview notes yang dikonfirmasi oleh narasumber. Hindari pertanyaan leading ("Apakah Anda selalu mengikuti SOP?").</p>
                              <p>Walkthrough adalah teknik di mana auditor "berjalan" mengikuti satu transaksi atau proses end-to-end. Misalnya: ambil satu DSR yang baru masuk, lalu ikuti perjalanannya dari penerimaan, verifikasi identitas, eksekusi, hingga komunikasi balik ke subjek data. Walkthrough mengungkap gap antara design control dan operating control.</p>
                              <p>Best practice: kombinasikan wawancara dengan walkthrough. Wawancara mengungkap intent dan understanding; walkthrough mengungkap reality. Inconsistensi antara keduanya adalah red flag yang perlu dieksplorasi lebih dalam.</p>
                            '),
                        'duration_seconds' => $this->durationToSeconds('20 menit'),
                        'steps'            => [
                            ['order' => 1, 'title' => 'Siapkan Interview Guide',      'description' => 'Tulis pertanyaan terbuka, hindari yes/no.'],
                            ['order' => 2, 'title' => 'Lakukan Wawancara',            'description' => 'Catat dengan teliti; konfirmasi pemahaman di akhir sesi.'],
                            ['order' => 3, 'title' => 'Jalankan Walkthrough',         'description' => 'Pilih sample transaksi dan ikuti end-to-end.'],
                        ],
                        'tips'             => [
                            'Wawancara front-line staff sering lebih insightful daripada wawancara management.',
                            'Rekam wawancara (dengan izin) jika memungkinkan — memudahkan review nanti.',
                        ],
                        'tags'             => ['interview', 'walkthrough', 'audit-technique'],
                    ],
                    [
                        'slug'             => $this->kebab('Sampling dan Testing Controls'),
                        'title'            => 'Sampling & Testing Controls',
                        'body'             => $this->htmlToMarkdown('
                              <p>Auditor tidak menguji 100% populasi — sampling adalah teknik untuk menarik kesimpulan tentang seluruh populasi dari sebagian. Untuk audit PDP, sampling umum digunakan untuk: testing DSR handling (apakah SLA terpenuhi), testing access review (apakah review dilakukan tepat waktu), dan testing consent records (apakah dokumentasi lengkap).</p>
                              <p>Dua jenis sampling: statistical (sample size dihitung secara matematis dari populasi dan tingkat confidence yang diinginkan) dan judgmental (auditor memilih sample berdasarkan profesionalisme). Statistical lebih defensible secara hukum; judgmental lebih efisien untuk eksplorasi awal.</p>
                              <p>Aturan praktis: untuk kontrol berfrekuensi tinggi (daily), sample 25-40. Untuk kontrol mingguan, sample 5-10. Untuk kontrol bulanan, sample 2-5. Jika ditemukan exception dalam sample, evaluasi: apakah ini one-off error atau systemic issue? Eskalasi sample size jika dicurigai systemic.</p>
                            '),
                        'duration_seconds' => $this->durationToSeconds('20 menit'),
                        'steps'            => [
                            ['order' => 1, 'title' => 'Tentukan Populasi',            'description' => 'Identifikasi total transaksi yang relevan dalam periode audit.'],
                            ['order' => 2, 'title' => 'Pilih Metode Sampling',        'description' => 'Statistical untuk audit formal; judgmental untuk eksplorasi.'],
                            ['order' => 3, 'title' => 'Test Sample',                  'description' => 'Periksa setiap sample terhadap kriteria kontrol.'],
                            ['order' => 4, 'title' => 'Evaluasi Hasil',               'description' => 'Bedakan one-off error vs systemic issue.'],
                        ],
                        'tips'             => [
                            'Random sampling lebih kuat secara statistik daripada sequential sampling.',
                            'Dokumentasikan rasional pemilihan sample size — auditor lain harus bisa replicate.',
                        ],
                        'tags'             => ['sampling', 'testing', 'controls'],
                    ],
                ],
                'quiz'        => [
                    'passing_score' => 70,
                    'questions'     => [
                        [
                            'type'           => 'mcq',
                            'prompt'         => 'Manakah jenis evidence yang paling kuat dalam hierarki audit?',
                            'options'        => [
                                ['key' => 'a', 'label' => 'Testimonial verbal dari auditee'],
                                ['key' => 'b', 'label' => 'Evidence dari sumber eksternal independen'],
                                ['key' => 'c', 'label' => 'Evidence yang disediakan auditee tanpa verifikasi'],
                                ['key' => 'd', 'label' => 'Opini management'],
                            ],
                            'correct_answer' => ['b'],
                            'points'         => 2,
                        ],
                        [
                            'type'           => 'true_false',
                            'prompt'         => 'Walkthrough adalah teknik mengikuti satu transaksi end-to-end untuk mengungkap gap antara design control dan operating control.',
                            'options'        => null,
                            'correct_answer' => [true],
                            'points'         => 2,
                        ],
                        [
                            'type'           => 'mcq',
                            'prompt'         => 'Apa yang harus dilakukan auditor jika menemukan exception dalam sample?',
                            'options'        => [
                                ['key' => 'a', 'label' => 'Mengabaikan karena hanya satu kasus'],
                                ['key' => 'b', 'label' => 'Mengevaluasi apakah ini one-off error atau systemic issue, dan mempertimbangkan eskalasi sample size'],
                                ['key' => 'c', 'label' => 'Langsung melaporkan ke regulator'],
                                ['key' => 'd', 'label' => 'Mengganti sample dengan yang tidak ada exception'],
                            ],
                            'correct_answer' => ['b'],
                            'points'         => 2,
                        ],
                    ],
                ],
            ],

            // ── Module 3: Pelaporan & Tindak Lanjut ────────────────────────────
            [
                'slug'        => $this->kebab('Pelaporan dan Tindak Lanjut'),
                'title'       => 'Pelaporan & Tindak Lanjut',
                'description' => 'Menulis laporan audit yang berdampak dan memastikan tindak lanjut yang efektif.',
                'lessons'     => [
                    [
                        'slug'             => $this->kebab('Struktur Laporan Audit'),
                        'title'            => 'Struktur Laporan Audit',
                        'body'             => $this->htmlToMarkdown('
                              <p>Laporan audit yang baik mengikuti struktur standar: (1) Executive Summary (1-2 halaman, untuk board), (2) Background & Objectives, (3) Scope & Methodology, (4) Detailed Findings (per finding: condition, criteria, cause, effect, recommendation), (5) Management Response, dan (6) Appendices.</p>
                              <p>Executive Summary adalah bagian paling sering dibaca — bahkan satu-satunya yang dibaca beberapa stakeholders. Mulai dengan overall opinion (Satisfactory / Needs Improvement / Unsatisfactory), highlight 3-5 finding terpenting, lalu rangkuman aksi yang diharapkan.</p>
                              <p>Setiap finding ditulis menggunakan "5C framework": Condition (apa yang ditemukan), Criteria (standar yang dilanggar — UU PDP pasal X, atau SOP internal), Cause (mengapa terjadi), Consequence (dampak), Corrective action (rekomendasi). Format ini memastikan finding lengkap dan actionable.</p>
                            '),
                        'duration_seconds' => $this->durationToSeconds('20 menit'),
                        'steps'            => [
                            ['order' => 1, 'title' => 'Tulis Executive Summary Last', 'description' => 'Tulis setelah seluruh body laporan selesai agar konsisten.'],
                            ['order' => 2, 'title' => 'Gunakan 5C Framework',         'description' => 'Setiap finding: Condition, Criteria, Cause, Consequence, Corrective action.'],
                            ['order' => 3, 'title' => 'Sertakan Management Response', 'description' => 'Auditee mendapat hak respond dan disclose target date.'],
                        ],
                        'tips'             => [
                            'Bahasa harus objektif — hindari kata-kata emosional atau menuduh.',
                            'Sertakan trend analysis: temuan baru vs temuan repeat dari audit sebelumnya.',
                        ],
                        'tags'             => ['reporting', 'laporan-audit', 'executive-summary'],
                    ],
                    [
                        'slug'             => $this->kebab('Finding Classification'),
                        'title'            => 'Finding Classification (High/Med/Low)',
                        'body'             => $this->htmlToMarkdown('
                              <p>Klasifikasi finding memungkinkan management memprioritaskan respons. Skema umum: High (risiko material — kemungkinan sanksi regulator, kebocoran data, atau pelanggaran hukum), Medium (kelemahan kontrol yang dapat dieskalasi menjadi material), Low (improvement opportunity tanpa risiko signifikan).</p>
                              <p>Faktor yang mempengaruhi klasifikasi: (1) Tingkat pelanggaran terhadap UU PDP — pelanggaran pasal tentang data spesifik lebih tinggi daripada pasal administratif, (2) Skala — jumlah subjek data yang terdampak, (3) Pengulangan — repeat finding otomatis naik tingkat, (4) Sensitivitas — data anak/kesehatan/finansial lebih tinggi.</p>
                              <p>Pastikan klasifikasi konsisten lintas tim auditor. Maintain "finding rubric" tertulis yang dapat dikonsultasikan saat klasifikasi. Praktik baik: review klasifikasi dengan audit manager sebelum finalisasi laporan untuk meminimalisir bias individu.</p>
                            '),
                        'duration_seconds' => $this->durationToSeconds('15 menit'),
                        'steps'            => [
                            ['order' => 1, 'title' => 'Buat Finding Rubric',          'description' => 'Definisi tertulis untuk kriteria High, Medium, Low.'],
                            ['order' => 2, 'title' => 'Klasifikasikan Setiap Finding', 'description' => 'Gunakan rubric secara konsisten, dokumentasikan rasional.'],
                            ['order' => 3, 'title' => 'Peer Review',                  'description' => 'Audit manager validate klasifikasi sebelum laporan dipublikasikan.'],
                        ],
                        'tips'             => [
                            'Finding High otomatis dieskalasi ke board atau audit committee.',
                            'Repeat finding adalah indikator kuat bahwa root cause belum tertangani.',
                        ],
                        'tags'             => ['classification', 'severity', 'rubric'],
                    ],
                    [
                        'slug'             => $this->kebab('Corrective Action Plan dan Follow-Up'),
                        'title'            => 'Corrective Action Plan & Follow-Up',
                        'body'             => $this->htmlToMarkdown('
                              <p>Audit tidak berakhir dengan laporan — value sebenarnya muncul dari tindak lanjut. Setiap finding harus memiliki Corrective Action Plan (CAP) dengan: action item spesifik, owner (nama person, bukan unit), target date, dan progress milestones.</p>
                              <p>Tracker CAP dimaintain auditor dengan status update minimal bulanan. Status umum: Open (belum mulai), In Progress (mulai tapi belum selesai), Closed (selesai dan diverifikasi auditor), Overdue (melewati target tanpa progress). Closed status memerlukan re-test oleh auditor — bukan hanya self-declaration dari auditee.</p>
                              <p>Reporting CAP ke audit committee minimal kuartalan. Trend yang harus dipantau: rasio Open vs Closed (target: closure rate >80% dalam 6 bulan), repeat finding (target: <10% finding berulang), dan accountability (apakah owner sudah berganti tanpa transition).</p>
                            '),
                        'duration_seconds' => $this->durationToSeconds('15 menit'),
                        'steps'            => [
                            ['order' => 1, 'title' => 'Susun CAP per Finding',        'description' => 'Action, owner, target date, milestones.'],
                            ['order' => 2, 'title' => 'Maintain Tracker',             'description' => 'Update minimal bulanan; flag overdue items.'],
                            ['order' => 3, 'title' => 'Lakukan Re-test',              'description' => 'Closure harus diverifikasi auditor, bukan self-declaration.'],
                            ['order' => 4, 'title' => 'Report ke Committee',          'description' => 'Kuartalan: closure rate, repeat findings, accountability.'],
                        ],
                        'tips'             => [
                            'Action item harus SMART — specific, measurable, achievable, relevant, time-bound.',
                            'Overdue items perlu dieskalasi ke management hierarchy yang lebih tinggi.',
                        ],
                        'tags'             => ['cap', 'follow-up', 'corrective-action'],
                    ],
                ],
                'quiz'        => [
                    'passing_score' => 70,
                    'questions'     => [
                        [
                            'type'           => 'mcq',
                            'prompt'         => 'Apa kepanjangan dari 5C framework dalam penulisan finding audit?',
                            'options'        => [
                                ['key' => 'a', 'label' => 'Confirm, Collect, Classify, Communicate, Close'],
                                ['key' => 'b', 'label' => 'Condition, Criteria, Cause, Consequence, Corrective action'],
                                ['key' => 'c', 'label' => 'Compliance, Control, Coverage, Confidence, Confirmation'],
                                ['key' => 'd', 'label' => 'Check, Challenge, Capture, Conclude, Communicate'],
                            ],
                            'correct_answer' => ['b'],
                            'points'         => 2,
                        ],
                        [
                            'type'           => 'true_false',
                            'prompt'         => 'Closure finding cukup berdasarkan self-declaration auditee tanpa perlu re-test oleh auditor.',
                            'options'        => null,
                            'correct_answer' => [false],
                            'points'         => 2,
                        ],
                        [
                            'type'           => 'mcq',
                            'prompt'         => 'Manakah faktor yang TIDAK mempengaruhi klasifikasi severity finding?',
                            'options'        => [
                                ['key' => 'a', 'label' => 'Tingkat pelanggaran terhadap pasal UU PDP'],
                                ['key' => 'b', 'label' => 'Jumlah subjek data yang terdampak'],
                                ['key' => 'c', 'label' => 'Apakah finding bersifat repeat'],
                                ['key' => 'd', 'label' => 'Nama auditor yang menemukannya'],
                            ],
                            'correct_answer' => ['d'],
                            'points'         => 2,
                        ],
                    ],
                ],
            ],
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Module specs — Course 4: Tata Kelola Data Pribadi
    // ──────────────────────────────────────────────────────────────────────────

    private function tataKelolaModuleSpec(): array
    {
        return [
            // ── Module 1: Privacy Governance Framework ─────────────────────────
            [
                'slug'        => $this->kebab('Privacy Governance Framework'),
                'title'       => 'Privacy Governance Framework',
                'description' => 'Membangun fondasi governance privasi yang scalable: prinsip, peran, dan model kematangan.',
                'lessons'     => [
                    [
                        'slug'             => $this->kebab('Prinsip Accountability'),
                        'title'            => 'Prinsip Accountability',
                        'body'             => $this->htmlToMarkdown('
                              <p>Accountability adalah prinsip yang menjadi tulang punggung UU PDP dan kerangka privasi modern. Berbeda dengan kepatuhan reaktif ("kami patuh karena tidak pernah ada keluhan"), accountability menuntut organisasi untuk secara proaktif membuktikan kepatuhan: melalui dokumentasi, audit, training, dan monitoring berkelanjutan.</p>
                              <p>Operasionalisasi accountability meliputi: (1) ROPA yang up-to-date, (2) kebijakan tertulis yang di-approve manajemen, (3) DPIA untuk pemrosesan berisiko tinggi, (4) DPA dengan seluruh prosesor data, (5) program training berkala, (6) incident response plan yang teruji, dan (7) audit berkala oleh auditor independen.</p>
                              <p>Beban pembuktian (burden of proof) berada di pengendali data — saat regulator atau pengadilan menanyakan "apa bukti kalian patuh pada prinsip X?", jawaban "trust us" tidak cukup. Dokumentasi sistematis adalah satu-satunya pertahanan yang valid.</p>
                            '),
                        'duration_seconds' => $this->durationToSeconds('20 menit'),
                        'steps'            => [
                            ['order' => 1, 'title' => 'Bangun Privacy Repository',    'description' => 'Pusat dokumentasi yang menyimpan semua kebijakan, ROPA, DPIA, DPA.'],
                            ['order' => 2, 'title' => 'Definisikan Accountability Metrics', 'description' => 'KPI seperti % policy reviewed, % training completed, % DPIA on time.'],
                            ['order' => 3, 'title' => 'Lakukan Self-Assessment',      'description' => 'Quarterly self-check terhadap principle accountability.'],
                        ],
                        'tips'             => [
                            'Privacy repository sebaiknya berbasis sistem (GRC tool), bukan SharePoint folder.',
                            'Dokumentasi yang tidak pernah diupdate adalah liability, bukan asset.',
                        ],
                        'tags'             => ['accountability', 'governance', 'documentation'],
                    ],
                    [
                        'slug'             => $this->kebab('RACI Matrix DPO Controller Processor'),
                        'title'            => 'RACI Matrix DPO/Controller/Processor',
                        'body'             => $this->htmlToMarkdown('
                              <p>Kejelasan peran adalah dasar governance. RACI matrix (Responsible, Accountable, Consulted, Informed) memetakan siapa yang melakukan apa untuk setiap aktivitas pelindungan data. Praktik umum: Controller accountable untuk keputusan strategis (tujuan, dasar hukum), Processor responsible untuk eksekusi teknis, DPO consulted untuk semua keputusan privasi material.</p>
                              <p>Kesalahan umum: menggabungkan accountable dan responsible pada satu peran. Accountable hanya satu orang per aktivitas — pemilik keputusan akhir. Responsible bisa lebih dari satu — yang mengerjakan. Memisahkan ini mencegah accountability vacuum saat ada masalah.</p>
                              <p>DPO memiliki posisi unik dalam RACI privasi — tidak pernah accountable (karena DPO bukan pengendali data), selalu consulted untuk aktivitas privasi material. Jika DPO ditempatkan sebagai accountable, struktur governance bermasalah secara mendasar dan harus diperbaiki.</p>
                            '),
                        'duration_seconds' => $this->durationToSeconds('20 menit'),
                        'steps'            => [
                            ['order' => 1, 'title' => 'Daftar Aktivitas Privasi',     'description' => 'Mulai dari ROPA: setiap aktivitas pemrosesan, plus aktivitas governance.'],
                            ['order' => 2, 'title' => 'Identifikasi Peran',           'description' => 'Controller, Processor, DPO, IT Security, Legal, dll.'],
                            ['order' => 3, 'title' => 'Petakan RACI',                 'description' => 'Pastikan setiap aktivitas memiliki tepat satu Accountable.'],
                        ],
                        'tips'             => [
                            'Review RACI matrix saat ada perubahan organisasi atau peran baru.',
                            'RACI yang baik di-display visible — bukan disimpan di folder yang tidak pernah dibuka.',
                        ],
                        'tags'             => ['raci', 'roles', 'dpo'],
                    ],
                    [
                        'slug'             => $this->kebab('Governance Maturity Model'),
                        'title'            => 'Governance Maturity Model',
                        'body'             => $this->htmlToMarkdown('
                              <p>Maturity model membantu organisasi memahami posisinya dan menetapkan target realistis. Tingkat umum: Level 1 (Ad-hoc — reaktif, tanpa proses formal), Level 2 (Repeatable — ada SOP tapi belum konsisten), Level 3 (Defined — proses terdokumentasi dan diikuti), Level 4 (Managed — diukur dengan metrics), Level 5 (Optimizing — continuous improvement).</p>
                              <p>Jangan target Level 5 dari hari pertama — itu unrealistic dan sering menghasilkan dokumen yang tidak operasional. Roadmap yang realistis: dari Level 1 ke Level 2 dalam 6-12 bulan, Level 2 ke Level 3 dalam 12-18 bulan, dan seterusnya. Beberapa area kritikal (misalnya incident response) bisa di-fast-track ke Level 3-4 sementara area lain masih di Level 2.</p>
                              <p>Assessment maturity dilakukan oleh pihak independen — bisa internal audit, atau consultant eksternal. Hasil assessment menjadi baseline untuk roadmap improvement dan komunikasi ke board tentang investasi yang diperlukan.</p>
                            '),
                        'duration_seconds' => $this->durationToSeconds('15 menit'),
                        'steps'            => [
                            ['order' => 1, 'title' => 'Lakukan Maturity Assessment',  'description' => 'Pilih model (NIST PF, ISO, atau custom) dan assess setiap area.'],
                            ['order' => 2, 'title' => 'Definisikan Target State',     'description' => 'Realistic 12-18 month target per area, bukan blanket Level 5.'],
                            ['order' => 3, 'title' => 'Susun Improvement Roadmap',    'description' => 'Prioritaskan area dengan gap terbesar dan risiko tertinggi.'],
                        ],
                        'tips'             => [
                            'Maturity bukan kompetisi — beberapa area cukup di Level 3 jika risk profilnya rendah.',
                            'Re-assess maturity setiap tahun untuk track progress dan adjust roadmap.',
                        ],
                        'tags'             => ['maturity', 'roadmap', 'assessment'],
                    ],
                ],
                'quiz'        => [
                    'passing_score' => 70,
                    'questions'     => [
                        [
                            'type'           => 'mcq',
                            'prompt'         => 'Apa yang dimaksud dengan prinsip accountability dalam UU PDP?',
                            'options'        => [
                                ['key' => 'a', 'label' => 'Pengendali data hanya perlu patuh saat ada keluhan'],
                                ['key' => 'b', 'label' => 'Pengendali data wajib secara proaktif membuktikan kepatuhan melalui dokumentasi dan audit'],
                                ['key' => 'c', 'label' => 'DPO yang bertanggung jawab atas semua kewajiban privasi'],
                                ['key' => 'd', 'label' => 'Subjek data yang harus membuktikan adanya pelanggaran'],
                            ],
                            'correct_answer' => ['b'],
                            'points'         => 2,
                        ],
                        [
                            'type'           => 'true_false',
                            'prompt'         => 'Dalam RACI matrix yang benar, setiap aktivitas hanya boleh memiliki satu peran Accountable.',
                            'options'        => null,
                            'correct_answer' => [true],
                            'points'         => 2,
                        ],
                        [
                            'type'           => 'mcq',
                            'prompt'         => 'Berapa level umum dalam governance maturity model?',
                            'options'        => [
                                ['key' => 'a', 'label' => '3 level (Basic, Intermediate, Advanced)'],
                                ['key' => 'b', 'label' => '5 level (Ad-hoc, Repeatable, Defined, Managed, Optimizing)'],
                                ['key' => 'c', 'label' => '7 level'],
                                ['key' => 'd', 'label' => 'Tidak ada standar — bebas'],
                            ],
                            'correct_answer' => ['b'],
                            'points'         => 2,
                        ],
                    ],
                ],
            ],

            // ── Module 2: Kebijakan & Prosedur ─────────────────────────────────
            [
                'slug'        => $this->kebab('Kebijakan dan Prosedur'),
                'title'       => 'Kebijakan & Prosedur',
                'description' => 'Menyusun privacy policy, SOP retensi, dan incident response yang operasional.',
                'lessons'     => [
                    [
                        'slug'             => $this->kebab('Privacy Policy Structure'),
                        'title'            => 'Privacy Policy Structure',
                        'body'             => $this->htmlToMarkdown('
                              <p>Privacy policy yang baik bukan dokumen hukum yang menakutkan — ini adalah komunikasi yang jelas kepada subjek data tentang bagaimana data mereka diperlakukan. Struktur standar: identitas pengendali, jenis data yang dikumpulkan, tujuan pemrosesan, dasar hukum, jangka waktu penyimpanan, transfer pihak ketiga, hak subjek data, dan kontak DPO.</p>
                              <p>Praktik terbaik: layered notice — versi singkat (1 halaman, plain language) di point of collection, dengan link ke versi lengkap. Hindari legalese — gunakan Indonesia yang dimengerti orang awam. Tes readability dengan target SMP — jika SMP kelas 3 tidak mengerti, terlalu kompleks.</p>
                              <p>Privacy policy harus di-review minimal tahunan dan setiap kali ada perubahan material (sistem baru, vendor baru, regulasi baru). Versi historis disimpan untuk membuktikan policy yang berlaku saat pengumpulan data tertentu. Notifikasi perubahan ke subjek data dilakukan via email atau in-app banner.</p>
                            '),
                        'duration_seconds' => $this->durationToSeconds('20 menit'),
                        'steps'            => [
                            ['order' => 1, 'title' => 'Audit Privacy Policy Saat Ini', 'description' => 'Check keselarasan dengan UU PDP dan praktik aktual.'],
                            ['order' => 2, 'title' => 'Rancang Layered Notice',       'description' => 'Versi pendek + link ke versi lengkap.'],
                            ['order' => 3, 'title' => 'Test Readability',             'description' => 'Minta orang awam membaca — apakah mereka mengerti?'],
                        ],
                        'tips'             => [
                            'Visual icons mempermudah pemahaman — gunakan ikon untuk jenis data, hak subjek, dll.',
                            'Privacy policy harus mudah ditemukan — link di footer setiap halaman, di app menu.',
                        ],
                        'tags'             => ['privacy-policy', 'notice', 'transparency'],
                    ],
                    [
                        'slug'             => $this->kebab('SOP Retensi dan Disposal'),
                        'title'            => 'SOP Retensi & Disposal',
                        'body'             => $this->htmlToMarkdown('
                              <p>Prinsip storage limitation menuntut data tidak disimpan lebih lama dari yang diperlukan. SOP retensi mendefinisikan: untuk setiap kategori data, berapa lama disimpan, dasar penentuan jangka waktu (regulasi sektoral, statutory limitation, business need), dan metode disposal saat retensi habis.</p>
                              <p>Tantangan praktis: data tersebar di berbagai sistem — primary database, backup, archive, log files, third-party SaaS. SOP retensi harus mencakup seluruh lokasi ini. Praktik baik: implementasikan automated retention policy di database (TTL pada record), dengan exception handling untuk legal hold (litigasi, audit).</p>
                              <p>Disposal harus secure — bukan sekedar delete. Untuk physical: shredding dengan sertifikat. Untuk digital: secure erase yang memenuhi standar (NIST SP 800-88). Untuk cloud: verifikasi dengan vendor bahwa data benar-benar terhapus dari semua replica dan backup. Disposal didokumentasikan dengan certificate of destruction.</p>
                            '),
                        'duration_seconds' => $this->durationToSeconds('20 menit'),
                        'steps'            => [
                            ['order' => 1, 'title' => 'Buat Retention Schedule',      'description' => 'Tabel: kategori data, retensi, dasar, owner.'],
                            ['order' => 2, 'title' => 'Implementasi Automated TTL',   'description' => 'Database TTL atau scheduled deletion job.'],
                            ['order' => 3, 'title' => 'Definisikan Disposal Procedure', 'description' => 'Metode secure erase dan dokumentasi sertifikasi.'],
                            ['order' => 4, 'title' => 'Atur Legal Hold Exception',    'description' => 'Mekanisme menahan disposal saat ada litigasi.'],
                        ],
                        'tips'             => [
                            'Backup adalah blind spot — pastikan retention policy juga berlaku di backup.',
                            'Test disposal procedure annually dengan simulasi.',
                        ],
                        'tags'             => ['retention', 'disposal', 'storage-limitation'],
                    ],
                    [
                        'slug'             => $this->kebab('Incident Response Policy'),
                        'title'            => 'Incident Response Policy',
                        'body'             => $this->htmlToMarkdown('
                              <p>UU PDP mewajibkan notifikasi insiden dalam 3x24 jam ke subjek data dan lembaga pelindungan data. Incident Response Policy harus mengoperasionalkan deadline ini: mendefinisikan apa yang termasuk insiden, eskalasi path, decision tree notifikasi, dan template komunikasi.</p>
                              <p>Struktur Incident Response Team (IRT): leader (DPO atau CISO), members lintas fungsi (IT, legal, comms, customer service, business owner). Setiap role memiliki on-call rotation 24/7 untuk insiden kritis. Praktik baik: tabletop exercise minimal kuartalan untuk menguji prosedur dan komunikasi tim.</p>
                              <p>Decision tree notifikasi penting karena tidak semua insiden wajib dinotifikasi. Faktor: apakah data pribadi terlibat? Apakah risk to subjek data tinggi? UU PDP tidak memberikan exemption untuk insiden internal yang tidak melibatkan eksfiltrasi — bahkan kebocoran ke pihak internal yang tidak berwenang termasuk insiden yang harus di-notifikasi.</p>
                            '),
                        'duration_seconds' => $this->durationToSeconds('20 menit'),
                        'steps'            => [
                            ['order' => 1, 'title' => 'Susun IRP Document',           'description' => 'Trigger, IRT roster, eskalasi, timelines.'],
                            ['order' => 2, 'title' => 'Buat Notification Templates',  'description' => 'Template untuk subjek data, regulator, media — pre-approved legal.'],
                            ['order' => 3, 'title' => 'Lakukan Tabletop Exercise',    'description' => 'Simulasikan insiden minimal kuartalan dengan IRT.'],
                            ['order' => 4, 'title' => 'Review Post-Insiden',          'description' => 'Lessons learned setiap insiden untuk improve prosedur.'],
                        ],
                        'tips'             => [
                            '3x24 jam termasuk weekend — on-call rotation wajib mencakup hari libur.',
                            'Template komunikasi yang pre-approved menghemat waktu di saat krisis.',
                        ],
                        'tags'             => ['incident-response', 'notifikasi', 'irt'],
                    ],
                ],
                'quiz'        => [
                    'passing_score' => 70,
                    'questions'     => [
                        [
                            'type'           => 'mcq',
                            'prompt'         => 'Berapa lama waktu notifikasi insiden yang diwajibkan UU PDP?',
                            'options'        => [
                                ['key' => 'a', 'label' => '24 jam'],
                                ['key' => 'b', 'label' => '3x24 jam'],
                                ['key' => 'c', 'label' => '7 hari'],
                                ['key' => 'd', 'label' => '30 hari'],
                            ],
                            'correct_answer' => ['b'],
                            'points'         => 2,
                        ],
                        [
                            'type'           => 'true_false',
                            'prompt'         => 'SOP retensi data harus mencakup seluruh lokasi data termasuk backup dan third-party SaaS.',
                            'options'        => null,
                            'correct_answer' => [true],
                            'points'         => 2,
                        ],
                        [
                            'type'           => 'mcq',
                            'prompt'         => 'Praktik terbaik untuk privacy policy adalah:',
                            'options'        => [
                                ['key' => 'a', 'label' => 'Menggunakan bahasa legalese yang formal'],
                                ['key' => 'b', 'label' => 'Layered notice — versi pendek plain language plus link ke versi lengkap'],
                                ['key' => 'c', 'label' => 'Disembunyikan di halaman yang sulit ditemukan'],
                                ['key' => 'd', 'label' => 'Hanya dalam bahasa Inggris untuk professional audience'],
                            ],
                            'correct_answer' => ['b'],
                            'points'         => 2,
                        ],
                    ],
                ],
            ],

            // ── Module 3: Operasionalisasi ─────────────────────────────────────
            [
                'slug'        => $this->kebab('Operasionalisasi'),
                'title'       => 'Operasionalisasi',
                'description' => 'Mengintegrasikan privasi ke dalam operasi sehari-hari: privacy by design, vendor management, dan KPI.',
                'lessons'     => [
                    [
                        'slug'             => $this->kebab('Privacy by Design Integration'),
                        'title'            => 'Privacy by Design Integration',
                        'body'             => $this->htmlToMarkdown('
                              <p>Privacy by Design (PbD) adalah pendekatan di mana pelindungan data dipertimbangkan sejak awal perancangan sistem, bukan ditambal di akhir. Tujuh prinsip PbD Ann Cavoukian: proaktif bukan reaktif, privacy as default, embedded into design, full functionality, end-to-end security, visibility & transparency, dan respect for user privacy.</p>
                              <p>Operasionalisasi PbD: integrasikan privacy review ke SDLC (Software Development Life Cycle). Praktik baik: privacy gate di tahap requirement (DPIA screening), design (privacy patterns review), pre-launch (security & privacy testing), dan post-launch (monitoring). Tanpa gate ini, PbD hanya jargon di slide.</p>
                              <p>Tools yang membantu: privacy patterns library (template untuk consent flow, anonymisasi, dll), privacy linter di CI/CD (otomatis flag code yang menulis PII tanpa logging), dan privacy champion di setiap tim product. Privacy champion bukan DPO — mereka adalah engineer atau PM yang dilatih khusus untuk advocate privacy di tim mereka.</p>
                            '),
                        'duration_seconds' => $this->durationToSeconds('20 menit'),
                        'steps'            => [
                            ['order' => 1, 'title' => 'Integrasikan ke SDLC',         'description' => 'Privacy gate di setiap tahap development.'],
                            ['order' => 2, 'title' => 'Bangun Pattern Library',       'description' => 'Reusable templates untuk consent, anonymisasi, dll.'],
                            ['order' => 3, 'title' => 'Tunjuk Privacy Champions',     'description' => 'Engineer/PM per tim yang advocate privacy.'],
                        ],
                        'tips'             => [
                            'PbD bukan one-time activity — culture shift yang butuh waktu 12-24 bulan.',
                            'Quick wins di awal (misalnya default-off untuk telemetri) build momentum.',
                        ],
                        'tags'             => ['privacy-by-design', 'sdlc', 'pattern'],
                    ],
                    [
                        'slug'             => $this->kebab('Third-Party Management'),
                        'title'            => 'Third-Party Management',
                        'body'             => $this->htmlToMarkdown('
                              <p>Pengendali data tetap bertanggung jawab atas data pribadi meskipun pemrosesan dilakukan oleh prosesor (vendor). UU PDP mewajibkan adanya Data Processing Agreement (DPA) dengan setiap prosesor yang mengatur kewajiban, batasan, dan accountability.</p>
                              <p>Vendor risk management cycle: (1) Pre-engagement due diligence — security questionnaire, SOC 2 review, privacy assessment, (2) Contracting — DPA + SCC jika cross-border, (3) Ongoing monitoring — annual review, audit rights, breach notification clause, (4) Termination — data return atau destruction certificate.</p>
                              <p>Vendor inventory wajib dipertahankan dengan metadata: jenis data yang dishare, lokasi pemrosesan, status DPA, tanggal review terakhir, dan klasifikasi risiko. Tier vendor berdasarkan risiko: Tier 1 (high — akses langsung ke data pribadi skala besar) di-audit annually; Tier 3 (low risk) di-review setiap 2-3 tahun.</p>
                            '),
                        'duration_seconds' => $this->durationToSeconds('20 menit'),
                        'steps'            => [
                            ['order' => 1, 'title' => 'Bangun Vendor Inventory',      'description' => 'Daftar semua prosesor dengan metadata risiko.'],
                            ['order' => 2, 'title' => 'Eksekusi DPA dengan Vendor',   'description' => 'Pastikan setiap vendor memiliki DPA yang ditandatangani.'],
                            ['order' => 3, 'title' => 'Lakukan Ongoing Monitoring',   'description' => 'Annual review untuk Tier 1, bi-annual untuk Tier 2.'],
                        ],
                        'tips'             => [
                            'Audit rights di DPA wajib — tanpa itu, ongoing monitoring lemah.',
                            'Cross-border transfer ke vendor sering luput — periksa lokasi data center sebenarnya.',
                        ],
                        'tags'             => ['vendor', 'dpa', 'third-party-risk'],
                    ],
                    [
                        'slug'             => $this->kebab('Metrics dan KPIs'),
                        'title'            => 'Metrics & KPIs',
                        'body'             => $this->htmlToMarkdown('
                              <p>Privacy yang tidak diukur tidak dapat dikelola. KPI privasi yang baik: leading indicators (proactive — training completion, DPIA on time) dan lagging indicators (reactive — insiden, complaint). Kombinasi keduanya memberikan picture lengkap kepada management.</p>
                              <p>Contoh KPI yang umum digunakan: (1) DSR turnaround time vs SLA, (2) % staff yang complete training tahunan, (3) Jumlah insiden privasi per bulan dan severity, (4) % vendor dengan DPA yang valid, (5) Jumlah DPIA yang diselesaikan vs trigger, (6) Closure rate audit finding, (7) Privacy complaint rate.</p>
                              <p>Reporting cadence: monthly internal dashboard untuk operasional, quarterly executive report untuk board. Visualisasi penting — gunakan trend chart, bukan hanya snapshot. Berbeda dari ekspektasi, beberapa KPI yang stabil (misalnya complaint rate = 0) tidak selalu baik — bisa jadi mekanisme reporting tidak berjalan.</p>
                            '),
                        'duration_seconds' => $this->durationToSeconds('15 menit'),
                        'steps'            => [
                            ['order' => 1, 'title' => 'Pilih 6-8 KPI Utama',          'description' => 'Hindari KPI sprawl — pilih yang actionable.'],
                            ['order' => 2, 'title' => 'Bangun Dashboard',             'description' => 'Real-time untuk operasional, quarterly summary untuk board.'],
                            ['order' => 3, 'title' => 'Review Trend',                 'description' => 'Bulanan: trend analysis, identify anomaly, action item.'],
                        ],
                        'tips'             => [
                            'KPI yang selalu hijau perlu di-challenge — mungkin threshold terlalu longgar.',
                            'Benchmark dengan industry peers — bantu kalibrasi target yang realistis.',
                        ],
                        'tags'             => ['kpi', 'metrics', 'dashboard'],
                    ],
                ],
                'quiz'        => [
                    'passing_score' => 70,
                    'questions'     => [
                        [
                            'type'           => 'mcq',
                            'prompt'         => 'Apa prinsip kunci dari Privacy by Design?',
                            'options'        => [
                                ['key' => 'a', 'label' => 'Menambahkan kontrol privasi setelah sistem launch'],
                                ['key' => 'b', 'label' => 'Mempertimbangkan pelindungan data sejak awal perancangan sistem'],
                                ['key' => 'c', 'label' => 'Hanya melindungi data sensitif, mengabaikan data umum'],
                                ['key' => 'd', 'label' => 'Mengurangi fungsionalitas demi privasi'],
                            ],
                            'correct_answer' => ['b'],
                            'points'         => 2,
                        ],
                        [
                            'type'           => 'true_false',
                            'prompt'         => 'Pengendali data tetap bertanggung jawab atas data pribadi meskipun pemrosesan dilakukan oleh prosesor.',
                            'options'        => null,
                            'correct_answer' => [true],
                            'points'         => 2,
                        ],
                        [
                            'type'           => 'mcq',
                            'prompt'         => 'Manakah contoh leading indicator KPI privasi?',
                            'options'        => [
                                ['key' => 'a', 'label' => 'Jumlah insiden bulan ini'],
                                ['key' => 'b', 'label' => 'Persentase staff yang menyelesaikan training tahunan'],
                                ['key' => 'c', 'label' => 'Jumlah complaint dari subjek data'],
                                ['key' => 'd', 'label' => 'Total denda dari regulator'],
                            ],
                            'correct_answer' => ['b'],
                            'points'         => 2,
                        ],
                    ],
                ],
            ],
        ];
    }
}
