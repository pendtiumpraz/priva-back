<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RegulationFrameworkSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('regulation_frameworks')->insert([
            [
                'id' => Str::uuid()->toString(),
                'code' => 'uupdp',
                'name' => 'UU No. 27 Tahun 2022 (UU PDP)',
                'country' => 'Indonesia',
                'articles' => json_encode([
                    [
                        "id" => "pdp_1",
                        "topic" => "Dasar Pemrosesan",
                        "question" => "Apakah setiap pemrosesan Data Pribadi telah memiliki dasar pemrosesan yang sah (misal: Persetujuan, Perjanjian, Kewajiban Hukum)?",
                        "article" => "Pasal 20",
                        "score_weight" => 15
                    ],
                    [
                        "id" => "pdp_2",
                        "topic" => "Hak Subjek Data",
                        "question" => "Apakah telah tersedia mekanisme untuk subjek data mengakses, mengubah, menghapus, atau menarik persetujuan pemrosesan datanya?",
                        "article" => "Pasal 5 - Pasal 11",
                        "score_weight" => 15
                    ],
                    [
                        "id" => "pdp_3",
                        "topic" => "Keamanan Data",
                        "question" => "Apakah telah diterapkan sistem keamanan memadai untuk mencegah akses tidak sah, kebocoran, atau kerusakan Data Pribadi?",
                        "article" => "Pasal 35",
                        "score_weight" => 20
                    ],
                    [
                        "id" => "pdp_4",
                        "topic" => "Notifikasi Insiden",
                        "question" => "Apakah ada prosedur pelaporan Kegagalan Pelindungan Data Pribadi secara tertulis maksimal 3x24 jam?",
                        "article" => "Pasal 46",
                        "score_weight" => 15
                    ],
                    [
                        "id" => "pdp_5",
                        "topic" => "Pejabat PDP (DPO)",
                        "question" => "Apakah perusahaan telah menunjuk Pejabat Pelindungan Data Pribadi (DPO) untuk mengawasi kepatuhan privasi?",
                        "article" => "Pasal 53",
                        "score_weight" => 10
                    ]
                ]),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'id' => Str::uuid()->toString(),
                'code' => 'gdpr',
                'name' => 'General Data Protection Regulation (GDPR)',
                'country' => 'European Union',
                'articles' => json_encode([
                    [
                        "id" => "gdpr_1",
                        "topic" => "Lawfulness & Transparency",
                        "question" => "Is personal data processed lawfully, fairly, and in a transparent manner in relation to the data subject?",
                        "article" => "Article 5(1)(a)",
                        "score_weight" => 15
                    ],
                    [
                        "id" => "gdpr_2",
                        "topic" => "Data Minimisation",
                        "question" => "Is the data collection adequate, relevant and limited to what is necessary?",
                        "article" => "Article 5(1)(c)",
                        "score_weight" => 15
                    ],
                    [
                        "id" => "gdpr_3",
                        "topic" => "Right to be Forgotten",
                        "question" => "Can data subjects easily request erasure of their personal data without undue delay?",
                        "article" => "Article 17",
                        "score_weight" => 20
                    ],
                    [
                        "id" => "gdpr_4",
                        "topic" => "Data Portability",
                        "question" => "Can data subjects receive their data in a structured, commonly used and machine-readable format?",
                        "article" => "Article 20",
                        "score_weight" => 10
                    ],
                    [
                        "id" => "gdpr_5",
                        "topic" => "Breach Notification",
                        "question" => "Is there a 72-hour reporting mechanism to the supervisory authority regarding data breaches?",
                        "article" => "Article 33",
                        "score_weight" => 15
                    ]
                ]),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'id' => Str::uuid()->toString(),
                'code' => 'pdpa',
                'name' => 'Personal Data Protection Act (PDPA)',
                'country' => 'Singapore',
                'articles' => json_encode([
                    [
                        "id" => "pdpa_1",
                        "topic" => "Consent Obligation",
                        "question" => "Is consent obtained before collecting, using, or disclosing personal data?",
                        "article" => "Section 13",
                        "score_weight" => 20
                    ],
                    [
                        "id" => "pdpa_2",
                        "topic" => "Purpose Limitation",
                        "question" => "Are purposes for data collection clearly notified to the individual?",
                        "article" => "Section 20",
                        "score_weight" => 15
                    ],
                    [
                        "id" => "pdpa_3",
                        "topic" => "Protection Obligation",
                        "question" => "Are reasonable security arrangements implemented to prevent unauthorized access or similar risks?",
                        "article" => "Section 24",
                        "score_weight" => 20
                    ],
                    [
                        "id" => "pdpa_4",
                        "topic" => "Retention Limitation",
                        "question" => "Is data destroyed or anonymised as soon as the purpose for which it was collected is no longer being served?",
                        "article" => "Section 25",
                        "score_weight" => 15
                    ]
                ]),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now()
            ]
        ]);
    }
}
