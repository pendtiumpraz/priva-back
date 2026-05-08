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
        $course = Course::updateOrCreate(
            ['slug' => 'kepatuhan-uu-pdp-fundamentals'],
            [
                'org_id'           => null,
                'title'            => 'Kepatuhan UU PDP Fundamentals',
                'description'      => 'Pahami dasar-dasar UU No. 27 Tahun 2022 tentang Pelindungan Data Pribadi, prinsip-prinsip utama, dan kewajiban pengendali data. Kursus ini wajib bagi setiap DPO dan tim kepatuhan privasi.',
                'level'            => 'beginner',
                'duration_minutes' => 360,
                'regulation_code'  => 'UU_PDP',
                'thumbnail_url'    => '/images/courses/updp-fundamentals-thumb.png',
                'published'        => true,
                'order'            => 1,
                'created_by'       => null,
            ]
        );

        $this->seedModules($course);
        $this->seedFinalExam($course);
    }

    private function seedModules(Course $course): void
    {
        $previousModuleId = null;
        foreach ($this->moduleSpec() as $i => $modSpec) {
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
        DB::statement('PRAGMA foreign_keys = OFF');
        DB::table('lms_quiz_questions')->where('quiz_id', $quiz->id)->delete();
        DB::statement('PRAGMA foreign_keys = ON');
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
        DB::statement('PRAGMA foreign_keys = OFF');
        DB::table('lms_quiz_questions')->where('quiz_id', $quiz->id)->delete();
        DB::statement('PRAGMA foreign_keys = ON');
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
    // Module specs — ported from mock-academy.ts (academyCourses[0])
    // ──────────────────────────────────────────────────────────────────────────

    private function moduleSpec(): array
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
                // No matching quiz in mock-quizzes.ts for academy-updp-mod-2
                'quiz'        => [
                    'passing_score' => 70,
                    'questions'     => [],
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
                // No matching quiz in mock-quizzes.ts for academy-updp-mod-3
                'quiz'        => [
                    'passing_score' => 70,
                    'questions'     => [],
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
}
