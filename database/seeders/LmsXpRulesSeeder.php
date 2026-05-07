<?php

namespace Database\Seeders;

use App\Lms\Models\XpRule;
use Illuminate\Database\Seeder;

class LmsXpRulesSeeder extends Seeder
{
    public const DEFAULTS = [
        ['action_key' => 'lesson.completed',  'xp_amount' => 10],
        ['action_key' => 'quiz.passed',       'xp_amount' => 50],
        ['action_key' => 'course.completed',  'xp_amount' => 200],
        ['action_key' => 'quiz.perfect',      'xp_amount' => 25],
    ];

    public function run(): void
    {
        foreach (self::DEFAULTS as $row) {
            XpRule::updateOrCreate(
                ['action_key' => $row['action_key']],
                ['xp_amount' => $row['xp_amount']]
            );
        }
    }
}
