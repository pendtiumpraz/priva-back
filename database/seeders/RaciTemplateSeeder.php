<?php

namespace Database\Seeders;

use App\Models\RaciTemplate;
use Illuminate\Database\Seeder;

/**
 * Seed three system RACI presets (org_id=null) — starting points tenants
 * can activate on any breach or fork/edit to their own library.
 *
 * Idempotent: updateOrCreate keys on (org_id=null, name, is_system=true).
 */
class RaciTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::PRESETS as $idx => $preset) {
            RaciTemplate::updateOrCreate(
                ['org_id' => null, 'name' => $preset['name'], 'is_system' => true],
                [
                    'description' => $preset['description'],
                    'matrix'      => $preset['matrix'],
                    'is_default'  => $idx === 0,
                    'is_system'   => true,
                ]
            );
        }
        $this->command?->info('Seeded ' . count(self::PRESETS) . ' system RACI templates.');
    }

    private const PRESETS = [
        [
            'name' => 'Default Enterprise (DPO-led)',
            'description' => 'DPO sebagai Accountable untuk sebagian besar fase; IT Security eksekusi teknis; Legal + Direksi consulted.',
            'matrix' => [
                'isolation'     => ['responsible' => 'it-security', 'accountable' => 'ciso',   'consulted' => ['dpo'],          'informed' => ['direksi']],
                'forensics'     => ['responsible' => 'it-security', 'accountable' => 'ciso',   'consulted' => ['dpo', 'legal'], 'informed' => ['direksi']],
                'analysis'      => ['responsible' => 'it-security', 'accountable' => 'ciso',   'consulted' => ['dpo'],          'informed' => []],
                'assessment'    => ['responsible' => 'dpo',         'accountable' => 'ciso',   'consulted' => ['legal'],        'informed' => ['direksi']],
                'communication' => ['responsible' => 'dpo',         'accountable' => 'direksi','consulted' => ['pr', 'legal'],  'informed' => ['all-staff']],
                'legal'         => ['responsible' => 'legal',       'accountable' => 'direksi','consulted' => ['dpo'],          'informed' => ['ciso']],
                'remediation'   => ['responsible' => 'it-security', 'accountable' => 'ciso',   'consulted' => ['dpo'],          'informed' => []],
                'eradication'   => ['responsible' => 'it-security', 'accountable' => 'ciso',   'consulted' => [],               'informed' => []],
                'recovery'      => ['responsible' => 'it-operations','accountable'=> 'ciso',   'consulted' => [],               'informed' => ['dpo']],
                'monitoring'    => ['responsible' => 'it-security', 'accountable' => 'ciso',   'consulted' => [],               'informed' => ['dpo']],
                'prevention'    => ['responsible' => 'ciso',        'accountable' => 'direksi','consulted' => ['dpo'],          'informed' => ['all-staff']],
                'investigation' => ['responsible' => 'it-security', 'accountable' => 'ciso',   'consulted' => ['legal', 'hr'],  'informed' => ['direksi']],
                'closure'       => ['responsible' => 'dpo',         'accountable' => 'direksi','consulted' => ['ciso'],         'informed' => ['all-staff']],
                'administration'=> ['responsible' => 'dpo',         'accountable' => 'direksi','consulted' => [],               'informed' => []],
                'general'       => ['responsible' => 'dpo',         'accountable' => 'ciso',   'consulted' => [],               'informed' => []],
            ],
        ],
        [
            'name' => 'IT-Led (Security Operations)',
            'description' => 'CISO / IT Security accountable untuk fase teknis; DPO consulted untuk notifikasi dan regulasi.',
            'matrix' => [
                'isolation'     => ['responsible' => 'it-security', 'accountable' => 'ciso',   'consulted' => [],               'informed' => ['dpo']],
                'forensics'     => ['responsible' => 'it-security', 'accountable' => 'ciso',   'consulted' => ['legal'],        'informed' => ['dpo']],
                'analysis'      => ['responsible' => 'it-security', 'accountable' => 'ciso',   'consulted' => [],               'informed' => ['dpo']],
                'assessment'    => ['responsible' => 'it-security', 'accountable' => 'ciso',   'consulted' => ['dpo'],          'informed' => ['direksi']],
                'communication' => ['responsible' => 'pr',          'accountable' => 'direksi','consulted' => ['dpo', 'ciso'],  'informed' => ['all-staff']],
                'legal'         => ['responsible' => 'legal',       'accountable' => 'direksi','consulted' => ['dpo'],          'informed' => ['ciso']],
                'remediation'   => ['responsible' => 'it-security', 'accountable' => 'ciso',   'consulted' => [],               'informed' => ['dpo']],
                'eradication'   => ['responsible' => 'it-security', 'accountable' => 'ciso',   'consulted' => [],               'informed' => []],
                'recovery'      => ['responsible' => 'it-operations','accountable'=> 'ciso',   'consulted' => ['it-security'],  'informed' => []],
                'monitoring'    => ['responsible' => 'it-security', 'accountable' => 'ciso',   'consulted' => [],               'informed' => ['dpo']],
                'prevention'    => ['responsible' => 'ciso',        'accountable' => 'direksi','consulted' => [],               'informed' => ['dpo']],
                'investigation' => ['responsible' => 'it-security', 'accountable' => 'ciso',   'consulted' => ['hr'],           'informed' => ['legal']],
                'closure'       => ['responsible' => 'ciso',        'accountable' => 'direksi','consulted' => ['dpo'],          'informed' => []],
                'administration'=> ['responsible' => 'ciso',        'accountable' => 'direksi','consulted' => [],               'informed' => []],
                'general'       => ['responsible' => 'it-security', 'accountable' => 'ciso',   'consulted' => [],               'informed' => []],
            ],
        ],
        [
            'name' => 'Small Team (2–5 people)',
            'description' => 'Struktur ramping — DPO + admin IT merangkap eksekusi, direksi accountable untuk keputusan strategis.',
            'matrix' => [
                'isolation'     => ['responsible' => 'admin-it',    'accountable' => 'dpo',    'consulted' => [],               'informed' => ['direksi']],
                'forensics'     => ['responsible' => 'admin-it',    'accountable' => 'dpo',    'consulted' => [],               'informed' => ['direksi']],
                'analysis'      => ['responsible' => 'admin-it',    'accountable' => 'dpo',    'consulted' => [],               'informed' => []],
                'assessment'    => ['responsible' => 'dpo',         'accountable' => 'direksi','consulted' => [],               'informed' => []],
                'communication' => ['responsible' => 'dpo',         'accountable' => 'direksi','consulted' => [],               'informed' => ['all-staff']],
                'legal'         => ['responsible' => 'dpo',         'accountable' => 'direksi','consulted' => [],               'informed' => []],
                'remediation'   => ['responsible' => 'admin-it',    'accountable' => 'dpo',    'consulted' => [],               'informed' => []],
                'eradication'   => ['responsible' => 'admin-it',    'accountable' => 'dpo',    'consulted' => [],               'informed' => []],
                'recovery'      => ['responsible' => 'admin-it',    'accountable' => 'dpo',    'consulted' => [],               'informed' => []],
                'monitoring'    => ['responsible' => 'admin-it',    'accountable' => 'dpo',    'consulted' => [],               'informed' => []],
                'prevention'    => ['responsible' => 'dpo',         'accountable' => 'direksi','consulted' => [],               'informed' => []],
                'investigation' => ['responsible' => 'admin-it',    'accountable' => 'dpo',    'consulted' => [],               'informed' => ['direksi']],
                'closure'       => ['responsible' => 'dpo',         'accountable' => 'direksi','consulted' => [],               'informed' => []],
                'administration'=> ['responsible' => 'dpo',         'accountable' => 'direksi','consulted' => [],               'informed' => []],
                'general'       => ['responsible' => 'dpo',         'accountable' => 'dpo',    'consulted' => [],               'informed' => []],
            ],
        ],
    ];
}
