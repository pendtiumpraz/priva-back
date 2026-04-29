<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class BreachSimulation extends Model
{
    use HasUuids, SoftDeletes, BelongsToOrg;

    protected $fillable = [
        'org_id', 'incident_id', 'scenario_type', 'scenario_title',
        'scenario_description', 'scenario_data', 'timer_mode', 'timer_ratio',
        'participants', 'started_at', 'ended_at', 'overall_score',
        'score_breakdown', 'findings', 'recommendations', 'status', 'created_by',
    ];

    protected $casts = [
        'scenario_data' => 'array',
        'participants' => 'array',
        'score_breakdown' => 'array',
        'findings' => 'array',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    /**
     * Get all scenario templates
     */
    public static function getScenarioTemplates(): array
    {
        return [
            'ransomware' => [
                'type' => 'ransomware',
                'title' => 'Ransomware Attack',
                'emoji' => '🔓',
                'description' => 'Sistem terenkripsi oleh ransomware, data tidak bisa diakses.',
                'briefing' => 'Pada pukul 03:00 WIB, tim IT menemukan bahwa beberapa server production telah terenkripsi. Ransom note meminta pembayaran 5 BTC dalam 48 jam. Database pelanggan yang berisi 50.000+ data pribadi termasuk NIK dan data finansial kemungkinan terdampak. Sistem backup terakhir dilakukan 2 hari lalu.',
                'questions' => [
                    [
                        'id' => 'R1', 'phase' => 'detection', 'time_limit' => 120,
                        'question' => 'Server production terenkripsi ransomware. Apa langkah PERTAMA yang harus dilakukan?',
                        'options' => [
                            ['id' => 'a', 'text' => 'Isolasi server yang terinfeksi dari jaringan', 'score' => 10, 'feedback' => 'BENAR! Isolasi adalah langkah pertama untuk mencegah penyebaran.'],
                            ['id' => 'b', 'text' => 'Bayar ransom untuk mendapatkan kembali data', 'score' => -5, 'feedback' => 'SALAH. Membayar ransom tidak menjamin data dikembalikan dan mendukung kriminal.'],
                            ['id' => 'c', 'text' => 'Format semua server dan install ulang', 'score' => 0, 'feedback' => 'Terlalu dini. Perlu forensik dulu sebelum format.'],
                            ['id' => 'd', 'text' => 'Hubungi vendor antivirus', 'score' => 3, 'feedback' => 'Bisa dilakukan tapi bukan prioritas pertama. Isolasi dulu.'],
                        ],
                    ],
                    [
                        'id' => 'R2', 'phase' => 'assessment', 'time_limit' => 120,
                        'question' => 'Setelah isolasi, data 50.000 pelanggan (NIK, data finansial) mungkin terdampak. Siapa yang harus dinotifikasi PERTAMA secara internal?',
                        'options' => [
                            ['id' => 'a', 'text' => 'Seluruh karyawan via email blast', 'score' => -3, 'feedback' => 'Terlalu luas. Bisa menyebabkan kepanikan.'],
                            ['id' => 'b', 'text' => 'DPO dan Tim Incident Response', 'score' => 10, 'feedback' => 'BENAR! DPO dan IR team harus tahu pertama untuk koordinasi respons.'],
                            ['id' => 'c', 'text' => 'Media/pers untuk transparansi', 'score' => -5, 'feedback' => 'SALAH. Notifikasi media terlalu dini dan bisa memperburuk situasi.'],
                            ['id' => 'd', 'text' => 'Kepolisian/Bareskrim', 'score' => 5, 'feedback' => 'Perlu, tapi setelah assessment internal dan koordinasi dengan DPO.'],
                        ],
                    ],
                    [
                        'id' => 'R3', 'phase' => 'containment', 'time_limit' => 90,
                        'question' => 'Data pribadi termasuk NIK dan data finansial. Apakah insiden ini WAJIB dilaporkan ke KOMDIGI?',
                        'options' => [
                            ['id' => 'a', 'text' => 'Ya, wajib dilaporkan dalam 3x24 jam', 'score' => 10, 'feedback' => 'BENAR! Sesuai Pasal 46 UU PDP, kebocoran data pribadi wajib dilaporkan 3x24 jam.'],
                            ['id' => 'b', 'text' => 'Tidak, cukup laporan internal saja', 'score' => -5, 'feedback' => 'SALAH. UU PDP mewajibkan pelaporan ke lembaga pengawas.'],
                            ['id' => 'c', 'text' => 'Tergantung berapa banyak data yang bocor', 'score' => 2, 'feedback' => 'Kurang tepat. Semua kebocoran data pribadi wajib dilaporkan.'],
                            ['id' => 'd', 'text' => 'Hanya jika ada kerugian finansial', 'score' => -3, 'feedback' => 'SALAH. Kewajiban pelaporan tidak bergantung pada kerugian finansial.'],
                        ],
                    ],
                    [
                        'id' => 'R4', 'phase' => 'notification', 'time_limit' => 120,
                        'question' => 'Berapa batas waktu MAKSIMAL untuk memberitahu subjek data yang terdampak?',
                        'options' => [
                            ['id' => 'a', 'text' => '24 jam', 'score' => 2, 'feedback' => 'Terlalu singkat. UU PDP memberikan waktu lebih.'],
                            ['id' => 'b', 'text' => '3 x 24 jam (72 jam)', 'score' => 10, 'feedback' => 'BENAR! Pasal 46 UU PDP: paling lambat 3x24 jam sejak diketahui.'],
                            ['id' => 'c', 'text' => '14 hari', 'score' => 0, 'feedback' => 'Terlalu lama dan melanggar UU PDP.'],
                            ['id' => 'd', 'text' => '30 hari', 'score' => -5, 'feedback' => 'SALAH dan melanggar UU PDP.'],
                        ],
                    ],
                    [
                        'id' => 'R5', 'phase' => 'notification', 'time_limit' => 120,
                        'question' => 'Apa saja yang WAJIB disertakan dalam notifikasi ke subjek data?',
                        'type' => 'multiple',
                        'options' => [
                            ['id' => 'a', 'text' => 'Jenis data pribadi yang bocor', 'score' => 3, 'correct' => true],
                            ['id' => 'b', 'text' => 'Kapan dan bagaimana kebocoran terjadi', 'score' => 3, 'correct' => true],
                            ['id' => 'c', 'text' => 'Upaya penanganan dan pemulihan', 'score' => 3, 'correct' => true],
                            ['id' => 'd', 'text' => 'Nama pelaku/hacker', 'score' => -2, 'correct' => false],
                            ['id' => 'e', 'text' => 'Detail teknis vulnerability', 'score' => -3, 'correct' => false],
                        ],
                    ],
                    [
                        'id' => 'R6', 'phase' => 'remediation', 'time_limit' => 150,
                        'question' => 'Setelah insiden tertangani, langkah apa yang harus dilakukan untuk mencegah terulang?',
                        'type' => 'multiple',
                        'options' => [
                            ['id' => 'a', 'text' => 'Root cause analysis', 'score' => 3, 'correct' => true],
                            ['id' => 'b', 'text' => 'Update security patches', 'score' => 3, 'correct' => true],
                            ['id' => 'c', 'text' => 'Review dan update incident response plan', 'score' => 3, 'correct' => true],
                            ['id' => 'd', 'text' => 'Pecat seluruh tim IT', 'score' => -5, 'correct' => false],
                            ['id' => 'e', 'text' => 'Pelatihan awareness untuk semua karyawan', 'score' => 2, 'correct' => true],
                        ],
                    ],
                ],
            ],

            'data_leak' => [
                'type' => 'data_leak',
                'title' => 'Data Leak',
                'emoji' => '💧',
                'description' => 'Database pelanggan ditemukan bocor di forum internet.',
                'briefing' => 'Tim keamanan menemukan bahwa dump database pelanggan berisi 15.000 record (nama, email, NIK, nomor telepon) dipublikasikan di forum dark web. Sumber kebocoran diduga berasal dari API endpoint yang tidak diamankan dengan baik. Tim menemukan bukti akses tidak sah selama 2 minggu terakhir.',
                'questions' => [
                    [
                        'id' => 'D1', 'phase' => 'detection', 'time_limit' => 120,
                        'question' => 'Data pelanggan ditemukan di dark web. Apa yang harus dilakukan PERTAMA?',
                        'options' => [
                            ['id' => 'a', 'text' => 'Verifikasi keaslian data yang bocor', 'score' => 10, 'feedback' => 'BENAR! Verifikasi dulu apakah data benar-benar milik organisasi.'],
                            ['id' => 'b', 'text' => 'Langsung umumkan ke publik', 'score' => -5, 'feedback' => 'Terlalu dini. Verifikasi dan assessment dulu.'],
                            ['id' => 'c', 'text' => 'Hapus semua data dari server', 'score' => -3, 'feedback' => 'Ini menghancurkan evidence forensik.'],
                            ['id' => 'd', 'text' => 'Tutup semua API endpoint', 'score' => 5, 'feedback' => 'Langkah containment yang baik tapi verifikasi dulu.'],
                        ],
                    ],
                    [
                        'id' => 'D2', 'phase' => 'assessment', 'time_limit' => 120,
                        'question' => 'API endpoint tidak aman teridentifikasi sebagai sumber. Data yang bocor termasuk NIK. Bagaimana mengklasifikasikan insiden ini?',
                        'options' => [
                            ['id' => 'a', 'text' => 'Severity: LOW — hanya beberapa data', 'score' => -5, 'feedback' => 'SALAH. NIK adalah data pribadi yang dilindungi khusus.'],
                            ['id' => 'b', 'text' => 'Severity: MEDIUM — ada data tapi tidak finansial', 'score' => 0, 'feedback' => 'Kurang tepat. NIK bisa digunakan untuk identity fraud.'],
                            ['id' => 'c', 'text' => 'Severity: HIGH — data pribadi termasuk NIK bocor ke publik', 'score' => 10, 'feedback' => 'BENAR! NIK + data kontak yang bocor di publik adalah severity HIGH.'],
                            ['id' => 'd', 'text' => 'Severity: CRITICAL — tapi hanya jika melibatkan data finansial', 'score' => 2, 'feedback' => 'Kurang tepat. NIK saja sudah cukup untuk severity tinggi.'],
                        ],
                    ],
                    [
                        'id' => 'D3', 'phase' => 'containment', 'time_limit' => 90,
                        'question' => 'Akses tidak sah berlangsung 2 minggu. Apa langkah containment yang tepat?',
                        'type' => 'multiple',
                        'options' => [
                            ['id' => 'a', 'text' => 'Patch vulnerable API endpoint', 'score' => 3, 'correct' => true],
                            ['id' => 'b', 'text' => 'Revoke semua API keys yang compromised', 'score' => 3, 'correct' => true],
                            ['id' => 'c', 'text' => 'Review access logs 30 hari terakhir', 'score' => 3, 'correct' => true],
                            ['id' => 'd', 'text' => 'Ganti semua password karyawan', 'score' => 1, 'correct' => false],
                            ['id' => 'e', 'text' => 'Matikan semua server untuk investigasi', 'score' => -2, 'correct' => false],
                        ],
                    ],
                    [
                        'id' => 'D4', 'phase' => 'notification', 'time_limit' => 120,
                        'question' => '15.000 subjek data terdampak. Bagaimana cara memberitahu mereka?',
                        'options' => [
                            ['id' => 'a', 'text' => 'Email personal ke setiap subjek data + laporan ke KOMDIGI', 'score' => 10, 'feedback' => 'BENAR! Notifikasi individual + laporan ke regulator.'],
                            ['id' => 'b', 'text' => 'Pengumuman di website saja', 'score' => 2, 'feedback' => 'Tidak cukup. Perlu notifikasi langsung ke setiap subjek data.'],
                            ['id' => 'c', 'text' => 'Tidak perlu karena data sudah publik', 'score' => -5, 'feedback' => 'SALAH. Justru karena sudah publik, subjek data harus segera diberitahu.'],
                            ['id' => 'd', 'text' => 'Melalui pihak ketiga saja', 'score' => 0, 'feedback' => 'Tanggung jawab tetap pada pengendali data.'],
                        ],
                    ],
                ],
            ],

            'phishing' => [
                'type' => 'phishing',
                'title' => 'Phishing Campaign',
                'emoji' => '🎣',
                'description' => 'Karyawan terjebak email phishing, credentials bocor.',
                'briefing' => 'Monitoring SIEM mendeteksi bahwa 25 karyawan telah meng-klik link phishing dan memasukkan credentials mereka. Email phishing menyamar sebagai email HR tentang kenaikan gaji. Dari 25 akun yang compromised, 5 diantaranya memiliki akses ke database yang berisi data pribadi pelanggan.',
                'questions' => [
                    [
                        'id' => 'P1', 'phase' => 'detection', 'time_limit' => 90,
                        'question' => '25 karyawan kena phishing, 5 punya akses ke data pelanggan. Langkah pertama?',
                        'options' => [
                            ['id' => 'a', 'text' => 'Reset password semua 25 akun yang compromised', 'score' => 10, 'feedback' => 'BENAR! Reset credentials yang compromised adalah prioritas utama.'],
                            ['id' => 'b', 'text' => 'Kirim email peringatan ke semua karyawan', 'score' => 3, 'feedback' => 'Penting tapi setelah containment akun yang compromised.'],
                            ['id' => 'c', 'text' => 'Blokir domain phishing', 'score' => 5, 'feedback' => 'Baik untuk mencegah lebih banyak korban, tapi reset credentials dulu.'],
                            ['id' => 'd', 'text' => 'Periksa apakah 5 akun mengakses data pelanggan', 'score' => 7, 'feedback' => 'Baik untuk assessment, tapi reset dulu agar attacker kehilangan akses.'],
                        ],
                    ],
                    [
                        'id' => 'P2', 'phase' => 'assessment', 'time_limit' => 120,
                        'question' => 'Log menunjukkan 2 dari 5 akun mengakses database pelanggan setelah phishing. Bagaimana menilai dampak?',
                        'options' => [
                            ['id' => 'a', 'text' => 'Analisis query database dari 2 akun tersebut untuk menentukan data yang diakses', 'score' => 10, 'feedback' => 'BENAR! Perlu tahu persis data apa yang diakses.'],
                            ['id' => 'b', 'text' => 'Asumsikan semua data pelanggan bocor', 'score' => 3, 'feedback' => 'Terlalu pessimistic tapi lebih aman dari worst case.'],
                            ['id' => 'c', 'text' => 'Tidak ada dampak karena bukan hacker profesional', 'score' => -5, 'feedback' => 'SALAH. Phishing bisa dioperasikan oleh hacker profesional.'],
                            ['id' => 'd', 'text' => 'Periksa apakah ada data yang di-download atau di-export', 'score' => 8, 'feedback' => 'Sangat baik! Tapi perlu juga analisis query.'],
                        ],
                    ],
                    [
                        'id' => 'P3', 'phase' => 'containment', 'time_limit' => 90,
                        'question' => 'Confirmed: 3.000 record pelanggan diakses. Apa status insiden ini menurut UU PDP?',
                        'options' => [
                            ['id' => 'a', 'text' => 'Pelanggaran PDP — wajib lapor KOMDIGI & notifikasi subjek data', 'score' => 10, 'feedback' => 'BENAR! Data pribadi telah diakses tanpa otorisasi, wajib lapor.'],
                            ['id' => 'b', 'text' => 'Bukan pelanggaran karena data hanya diakses, bukan dicuri', 'score' => -5, 'feedback' => 'SALAH. Akses tidak sah sudah merupakan pelanggaran.'],
                            ['id' => 'c', 'text' => 'Gray area — tergantung apakah data di-copy', 'score' => 0, 'feedback' => 'Kurang tepat. Akses saja sudah merupakan breach.'],
                            ['id' => 'd', 'text' => 'Hanya perlu laporan internal', 'score' => -3, 'feedback' => 'SALAH. Pelaporan eksternal wajib untuk kebocoran data pribadi.'],
                        ],
                    ],
                ],
            ],

            'insider_threat' => [
                'type' => 'insider_threat',
                'title' => 'Insider Threat',
                'emoji' => '🕵️',
                'description' => 'Karyawan resign mengunduh data sensitif.',
                'briefing' => 'DLP (Data Loss Prevention) mendeteksi bahwa seorang karyawan yang sudah menyerahkan surat resign mengunduh 10.000+ record data pelanggan ke USB drive. Karyawan tersebut bekerja di divisi sales dan memiliki akses legitimate ke CRM. Aksi terdeteksi 2 jam setelah download.',
                'questions' => [
                    [
                        'id' => 'I1', 'phase' => 'detection', 'time_limit' => 90,
                        'question' => 'Karyawan resign download 10.000 data pelanggan. Langkah pertama?',
                        'options' => [
                            ['id' => 'a', 'text' => 'Revoke akses karyawan tersebut SEGERA', 'score' => 10, 'feedback' => 'BENAR! Segera cabut semua akses untuk mencegah kerugian lebih lanjut.'],
                            ['id' => 'b', 'text' => 'Bicara baik-baik dengan karyawan', 'score' => 2, 'feedback' => 'Perlu, tapi setelah aksesnya dicabut.'],
                            ['id' => 'c', 'text' => 'Laporkan ke kepolisian', 'score' => 3, 'feedback' => 'Perlu, tapi setelah containment dan evidence collection.'],
                            ['id' => 'd', 'text' => 'Monitor aktivitasnya dulu', 'score' => -3, 'feedback' => 'Terlalu berisiko. Data sudah di-download.'],
                        ],
                    ],
                    [
                        'id' => 'I2', 'phase' => 'assessment', 'time_limit' => 120,
                        'question' => 'Karyawan mengklaim data untuk "keperluan pekerjaan baru". Apakah ini pelanggaran UU PDP?',
                        'options' => [
                            ['id' => 'a', 'text' => 'Ya, pemrosesan di luar tujuan awal tanpa consent subjek data', 'score' => 10, 'feedback' => 'BENAR! Data dikumpulkan untuk tujuan bisnis perusahaan, bukan untuk dibawa karyawan.'],
                            ['id' => 'b', 'text' => 'Tidak, karena dia punya akses legitimate', 'score' => -5, 'feedback' => 'SALAH. Akses legitimate tidak berarti boleh mentransfer data keluar.'],
                            ['id' => 'c', 'text' => 'Tergantung kontrak kerja', 'score' => 3, 'feedback' => 'Kontrak relevan tapi UU PDP berlaku terlepas dari kontrak.'],
                            ['id' => 'd', 'text' => 'Hanya pelanggaran internal, bukan UU PDP', 'score' => -3, 'feedback' => 'SALAH. Ini termasuk pelanggaran UU PDP.'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Calculate drill score from responses
     */
    public static function calculateDrillScore(array $scenario, array $responses): array
    {
        $totalPossible = 0;
        $totalEarned = 0;
        $questionResults = [];

        foreach ($scenario['questions'] as $q) {
            $maxScore = max(array_column($q['options'], 'score'));
            $totalPossible += $maxScore;

            $response = $responses[$q['id']] ?? null;
            $earned = 0;
            $feedback = '';

            if ($response) {
                if (isset($q['type']) && $q['type'] === 'multiple') {
                    // Multiple choice — sum selected correct answers
                    $selectedIds = is_array($response['answer']) ? $response['answer'] : [$response['answer']];
                    foreach ($q['options'] as $opt) {
                        if (in_array($opt['id'], $selectedIds)) {
                            $earned += $opt['score'];
                        }
                    }
                    $feedback = 'Multiple selection evaluated.';
                }
                else {
                    // Single choice
                    foreach ($q['options'] as $opt) {
                        if ($opt['id'] === $response['answer']) {
                            $earned = $opt['score'];
                            $feedback = $opt['feedback'] ?? '';
                            break;
                        }
                    }
                }

                // Time bonus/penalty
                $timeSpent = $response['time_spent'] ?? $q['time_limit'];
                if ($timeSpent <= $q['time_limit'] * 0.5) {
                    $earned = (int)round($earned * 1.1); // 10% bonus for fast response
                }
                elseif ($timeSpent > $q['time_limit']) {
                    $earned = (int)round($earned * 0.8); // 20% penalty for slow
                }
            }

            $totalEarned += max(0, $earned);
            $questionResults[$q['id']] = [
                'earned' => max(0, $earned),
                'max' => $maxScore,
                'feedback' => $feedback,
                'phase' => $q['phase'],
            ];
        }

        $scorePercent = $totalPossible > 0 ? round(($totalEarned / $totalPossible) * 100) : 0;
        $rating = $scorePercent >= 80 ? 'Excellent' : ($scorePercent >= 60 ? 'Good' : ($scorePercent >= 40 ? 'Needs Improvement' : 'Poor'));

        return [
            'total_score' => $totalEarned,
            'max_score' => $totalPossible,
            'score_percent' => $scorePercent,
            'rating' => $rating,
            'question_results' => $questionResults,
        ];
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class , 'org_id');
    }
}
