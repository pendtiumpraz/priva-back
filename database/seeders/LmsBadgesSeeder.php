<?php

namespace Database\Seeders;

use App\Lms\Models\Badge;
use Illuminate\Database\Seeder;

class LmsBadgesSeeder extends Seeder
{
    public const BADGES = [
        ['slug' => 'first-lesson', 'name' => 'First Lesson', 'description' => 'Complete your first lesson.', 'icon' => 'BookOpen', 'criteria_type' => 'completion', 'criteria_json' => ['theme' => 'blue', 'params' => ['what' => 'lessons', 'min' => 1]]],
        ['slug' => 'learner-novice', 'name' => 'Learner Novice', 'description' => 'Complete 5 lessons.', 'icon' => 'Sparkles', 'criteria_type' => 'completion', 'criteria_json' => ['theme' => 'blue', 'params' => ['what' => 'lessons', 'min' => 5]]],
        ['slug' => 'learner-apprentice', 'name' => 'Learner Apprentice', 'description' => 'Complete 15 lessons.', 'icon' => 'BookMarked', 'criteria_type' => 'completion', 'criteria_json' => ['theme' => 'blue', 'params' => ['what' => 'lessons', 'min' => 15]]],
        ['slug' => 'quiz-master', 'name' => 'Quiz Master', 'description' => 'Pass 3 quizzes.', 'icon' => 'ListChecks', 'criteria_type' => 'completion', 'criteria_json' => ['theme' => 'purple', 'params' => ['what' => 'quizzes', 'min' => 3]]],
        ['slug' => 'perfect-score', 'name' => 'Perfect Score', 'description' => 'Get 100% on any quiz.', 'icon' => 'Medal', 'criteria_type' => 'quiz_score', 'criteria_json' => ['theme' => 'gold', 'params' => ['score' => 100]]],
        ['slug' => 'perfect-streak', 'name' => 'Perfect Streak', 'description' => 'Get 100% on 3 quizzes in a row.', 'icon' => 'Flame', 'criteria_type' => 'custom', 'criteria_json' => ['theme' => 'gold', 'params' => ['kind' => 'perfect_streak', 'length' => 3]]],
        ['slug' => 'first-course', 'name' => 'First Course', 'description' => 'Complete a full course.', 'icon' => 'GraduationCap', 'criteria_type' => 'completion', 'criteria_json' => ['theme' => 'emerald', 'params' => ['what' => 'courses', 'min' => 1]]],
        ['slug' => 'course-collector', 'name' => 'Course Collector', 'description' => 'Complete 3 courses.', 'icon' => 'Library', 'criteria_type' => 'completion', 'criteria_json' => ['theme' => 'emerald', 'params' => ['what' => 'courses', 'min' => 3]]],
        ['slug' => 'xp-rookie', 'name' => 'XP Rookie', 'description' => 'Earn 100 XP.', 'icon' => 'Zap', 'criteria_type' => 'xp_total', 'criteria_json' => ['theme' => 'indigo', 'params' => ['min_xp' => 100]]],
        ['slug' => 'xp-veteran', 'name' => 'XP Veteran', 'description' => 'Earn 500 XP.', 'icon' => 'Award', 'criteria_type' => 'xp_total', 'criteria_json' => ['theme' => 'indigo', 'params' => ['min_xp' => 500]]],
        ['slug' => 'daily-streak-3', 'name' => '3-Day Streak', 'description' => 'Be active 3 consecutive days.', 'icon' => 'CalendarCheck', 'criteria_type' => 'custom', 'criteria_json' => ['theme' => 'rose', 'params' => ['kind' => 'daily_streak', 'length' => 3]]],
        ['slug' => 'uu-pdp-expert', 'name' => 'UU PDP Expert', 'description' => 'Complete the UU PDP Fundamentals course with at least 90% on the final exam.', 'icon' => 'ShieldCheck', 'criteria_type' => 'custom', 'criteria_json' => ['theme' => 'indigo', 'params' => ['kind' => 'course_with_exam_score', 'course_slug' => 'kepatuhan-uu-pdp-fundamentals', 'exam_score_min' => 90]]],
    ];

    public function run(): void
    {
        foreach (self::BADGES as $b) {
            Badge::updateOrCreate(
                ['slug' => $b['slug']],
                [
                    'name' => $b['name'],
                    'description' => $b['description'],
                    'icon' => $b['icon'],
                    'criteria_type' => $b['criteria_type'],
                    'criteria_json' => $b['criteria_json'],
                ]
            );
        }
    }
}
