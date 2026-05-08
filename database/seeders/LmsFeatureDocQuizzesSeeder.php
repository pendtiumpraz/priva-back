<?php

namespace Database\Seeders;

use App\Lms\Models\Quiz;
use App\Lms\Models\QuizQuestion;
use Illuminate\Database\Seeder;

class LmsFeatureDocQuizzesSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->quizData() as $quizDef) {
            $quiz = Quiz::updateOrCreate(
                ['owner_type' => 'feature_doc', 'owner_key' => $quizDef['owner_key']],
                ['title' => $quizDef['title'], 'passing_score' => 70],
            );

            foreach ($quizDef['questions'] as $i => $q) {
                QuizQuestion::updateOrCreate(
                    ['quiz_id' => $quiz->id, 'order' => $i + 1],
                    [
                        'type'           => $q['type'],
                        'prompt'         => $q['body'],
                        'options'        => $q['options'],
                        'correct_answer' => $q['correct_answer'],
                    ],
                );
            }
        }
    }

    private function quizData(): array
    {
        return [

            // ─── 1. DASHBOARD ──────────────────────────────────────────────
            [
                'owner_key' => 'dashboard',
                'title'     => 'Kuis: Privasimu Nexus Dashboard',
                'questions' => [
                    [
                        'type'           => 'mcq',
                        'body'           => 'Widget mana yang menampilkan status kepatuhan keseluruhan organisasi Anda di dashboard Privasimu Nexus?',
                        'options'        => [
                            ['id' => 'a', 'text' => 'Activity Log'],
                            ['id' => 'b', 'text' => 'Compliance Score'],
                            ['id' => 'c', 'text' => 'User Management'],
                            ['id' => 'd', 'text' => 'Billing Overview'],
                        ],
                        'correct_answer' => 'b',
                    ],
                    [
                        'type'           => 'true_false',
                        'body'           => 'Dashboard Privasimu Nexus hanya dapat diakses oleh pengguna dengan peran DPO atau Administrator.',
                        'options'        => [
                            ['id' => 't', 'text' => 'Benar'],
                            ['id' => 'f', 'text' => 'Salah'],
                        ],
                        'correct_answer' => 'f',
                    ],
                    [
                        'type'           => 'mcq',
                        'body'           => 'Apa fungsi tombol "Quick Action" yang muncul di pojok kanan atas dashboard?',
                        'options'        => [
                            ['id' => 'a', 'text' => 'Mengekspor laporan PDF langsung'],
                            ['id' => 'b', 'text' => 'Membuka shortcut ke modul yang paling sering digunakan'],
                            ['id' => 'c', 'text' => 'Mengirim notifikasi ke seluruh tim'],
                            ['id' => 'd', 'text' => 'Mereset semua filter aktif'],
                        ],
                        'correct_answer' => 'b',
                    ],
                ],
            ],

            // ─── 2. ROPA ───────────────────────────────────────────────────
            [
                'owner_key' => 'ropa',
                'title'     => 'Kuis: Modul ROPA',
                'questions' => [
                    [
                        'type'           => 'mcq',
                        'body'           => 'Kepanjangan ROPA dalam konteks UU PDP adalah …',
                        'options'        => [
                            ['id' => 'a', 'text' => 'Record of Processing Activities'],
                            ['id' => 'b', 'text' => 'Register of Privacy Arrangements'],
                            ['id' => 'c', 'text' => 'Report on Personal Audits'],
                            ['id' => 'd', 'text' => 'Regulation of Privacy Obligations'],
                        ],
                        'correct_answer' => 'a',
                    ],
                    [
                        'type'           => 'true_false',
                        'body'           => 'Menurut UU PDP Pasal 32, setiap Pengendali Data wajib memelihara catatan aktivitas pemrosesan data pribadi.',
                        'options'        => [
                            ['id' => 't', 'text' => 'Benar'],
                            ['id' => 'f', 'text' => 'Salah'],
                        ],
                        'correct_answer' => 't',
                    ],
                    [
                        'type'           => 'mcq',
                        'body'           => 'Bidang mana yang wajib diisi ketika menambahkan entri baru di modul ROPA Privasimu?',
                        'options'        => [
                            ['id' => 'a', 'text' => 'Nama prosesor & tujuan pemrosesan'],
                            ['id' => 'b', 'text' => 'Tanggal lahir subjek data'],
                            ['id' => 'c', 'text' => 'Nomor KTP subjek data'],
                            ['id' => 'd', 'text' => 'Anggaran departemen pengendali'],
                        ],
                        'correct_answer' => 'a',
                    ],
                ],
            ],

            // ─── 3. DPIA ───────────────────────────────────────────────────
            [
                'owner_key' => 'dpia',
                'title'     => 'Kuis: Modul DPIA',
                'questions' => [
                    [
                        'type'           => 'mcq',
                        'body'           => 'Kapan organisasi WAJIB melaksanakan DPIA menurut panduan Privasimu?',
                        'options'        => [
                            ['id' => 'a', 'text' => 'Setiap tahun kalender secara rutin'],
                            ['id' => 'b', 'text' => 'Saat memperkenalkan pemrosesan baru yang berisiko tinggi terhadap hak subjek data'],
                            ['id' => 'c', 'text' => 'Hanya jika diminta oleh regulator'],
                            ['id' => 'd', 'text' => 'Setelah terjadi insiden pelanggaran data'],
                        ],
                        'correct_answer' => 'b',
                    ],
                    [
                        'type'           => 'true_false',
                        'body'           => 'DPIA yang sudah disetujui tidak perlu ditinjau ulang meskipun terjadi perubahan signifikan pada proses pemrosesan data.',
                        'options'        => [
                            ['id' => 't', 'text' => 'Benar'],
                            ['id' => 'f', 'text' => 'Salah'],
                        ],
                        'correct_answer' => 'f',
                    ],
                    [
                        'type'           => 'mcq',
                        'body'           => 'Dalam modul DPIA Privasimu, "Residual Risk" merujuk pada …',
                        'options'        => [
                            ['id' => 'a', 'text' => 'Risiko yang tersisa setelah kontrol mitigasi diterapkan'],
                            ['id' => 'b', 'text' => 'Total risiko sebelum analisis dilakukan'],
                            ['id' => 'c', 'text' => 'Risiko yang dialihkan ke pihak ketiga'],
                            ['id' => 'd', 'text' => 'Risiko yang diabaikan karena dianggap tidak material'],
                        ],
                        'correct_answer' => 'a',
                    ],
                ],
            ],

            // ─── 4. BREACH ─────────────────────────────────────────────────
            [
                'owner_key' => 'breach',
                'title'     => 'Kuis: Modul Insiden Pelanggaran Data',
                'questions' => [
                    [
                        'type'           => 'mcq',
                        'body'           => 'UU PDP mewajibkan notifikasi insiden kepada Otoritas Perlindungan Data dalam jangka waktu …',
                        'options'        => [
                            ['id' => 'a', 'text' => '14 hari kerja sejak insiden terdeteksi'],
                            ['id' => 'b', 'text' => '3 × 24 jam sejak insiden terdeteksi'],
                            ['id' => 'c', 'text' => '30 hari kalender sejak akhir bulan'],
                            ['id' => 'd', 'text' => 'Segera setelah investigasi selesai, tanpa batas waktu tetap'],
                        ],
                        'correct_answer' => 'b',
                    ],
                    [
                        'type'           => 'true_false',
                        'body'           => 'Modul Breach di Privasimu memungkinkan pengguna untuk melampirkan bukti forensik digital langsung ke laporan insiden.',
                        'options'        => [
                            ['id' => 't', 'text' => 'Benar'],
                            ['id' => 'f', 'text' => 'Salah'],
                        ],
                        'correct_answer' => 't',
                    ],
                    [
                        'type'           => 'mcq',
                        'body'           => 'Mana dari berikut ini yang BUKAN merupakan status tiket insiden yang tersedia di modul Breach Privasimu?',
                        'options'        => [
                            ['id' => 'a', 'text' => 'Terdeteksi'],
                            ['id' => 'b', 'text' => 'Sedang Diinvestigasi'],
                            ['id' => 'c', 'text' => 'Ditangguhkan'],
                            ['id' => 'd', 'text' => 'Ditutup'],
                        ],
                        'correct_answer' => 'c',
                    ],
                ],
            ],

            // ─── 5. CONSENT ────────────────────────────────────────────────
            [
                'owner_key' => 'consent',
                'title'     => 'Kuis: Modul Manajemen Persetujuan',
                'questions' => [
                    [
                        'type'           => 'mcq',
                        'body'           => 'Menurut UU PDP, persetujuan (consent) yang sah harus memenuhi syarat …',
                        'options'        => [
                            ['id' => 'a', 'text' => 'Diberikan secara eksplisit, bebas, spesifik, dan berdasarkan informasi yang cukup'],
                            ['id' => 'b', 'text' => 'Tercatat dalam formulir kertas dan ditandatangani di atas materai'],
                            ['id' => 'c', 'text' => 'Diberikan sekali dan berlaku seumur hidup tanpa harus dapat ditarik kembali'],
                            ['id' => 'd', 'text' => 'Divalidasi oleh notaris yang berwenang'],
                        ],
                        'correct_answer' => 'a',
                    ],
                    [
                        'type'           => 'true_false',
                        'body'           => 'Di modul Consent Privasimu, subjek data dapat menarik persetujuannya kapan saja dan sistem akan mencatat timestamp penarikan tersebut.',
                        'options'        => [
                            ['id' => 't', 'text' => 'Benar'],
                            ['id' => 'f', 'text' => 'Salah'],
                        ],
                        'correct_answer' => 't',
                    ],
                    [
                        'type'           => 'mcq',
                        'body'           => 'Fitur "Consent Versioning" di Privasimu bertujuan untuk …',
                        'options'        => [
                            ['id' => 'a', 'text' => 'Menyimpan riwayat setiap versi formulir persetujuan agar dapat diaudit'],
                            ['id' => 'b', 'text' => 'Membuat salinan cadangan database secara otomatis'],
                            ['id' => 'c', 'text' => 'Mengelompokkan persetujuan berdasarkan kelompok usia subjek data'],
                            ['id' => 'd', 'text' => 'Mengubah bahasa formulir sesuai lokasi IP pengguna'],
                        ],
                        'correct_answer' => 'a',
                    ],
                ],
            ],
        ];
    }
}
