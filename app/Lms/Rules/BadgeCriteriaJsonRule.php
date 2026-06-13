<?php

namespace App\Lms\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates the structure of `criteria_json` against a given `criteria_type`.
 *
 * Required shape (per spec §3.9):
 *
 *   {
 *     "theme":  one of {blue, purple, gold, emerald, indigo, rose},
 *     "params": <object> ; structure depends on $criteriaType
 *   }
 *
 * Per criteria_type:
 *
 *   lesson_complete  -> params.lesson_id  (int)
 *   quiz_pass        -> params.quiz_id    (int)
 *   quiz_perfect     -> params.quiz_id    (int)
 *   course_complete  -> params.course_id  (int)
 *   streak           -> params.days       (int 1..365)
 *   xp_threshold     -> params.min_xp     (int 1..100000)
 *   custom           -> any params object
 *
 * Legacy seeded criteria types (`completion`, `quiz_score`, `xp_total`) are
 * accepted by the request validation enum (so admins can edit seeded badges)
 * but receive no structural validation here — they fall through the default
 * case the same way `custom` does.
 */
class BadgeCriteriaJsonRule implements ValidationRule
{
    public function __construct(private string $criteriaType) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            $fail('The :attribute must be a JSON object.');
            return;
        }

        $allowedThemes = ['blue', 'purple', 'gold', 'emerald', 'indigo', 'rose'];
        if (! in_array($value['theme'] ?? null, $allowedThemes, true)) {
            $fail('The :attribute.theme must be one of: blue, purple, gold, emerald, indigo, rose.');
        }

        if (! array_key_exists('params', $value) || ! is_array($value['params'])) {
            $fail('The :attribute.params must be an object.');
            return;
        }

        $params = $value['params'];

        switch ($this->criteriaType) {
            case 'lesson_complete':
                if (! is_int($params['lesson_id'] ?? null)) {
                    $fail('The :attribute.params.lesson_id is required and must be an integer.');
                }
                break;

            case 'quiz_pass':
            case 'quiz_perfect':
                if (! is_int($params['quiz_id'] ?? null)) {
                    $fail('The :attribute.params.quiz_id is required and must be an integer.');
                }
                break;

            case 'course_complete':
                if (! is_int($params['course_id'] ?? null)) {
                    $fail('The :attribute.params.course_id is required and must be an integer.');
                }
                break;

            case 'streak':
                $days = $params['days'] ?? null;
                if (! is_int($days) || $days < 1 || $days > 365) {
                    $fail('The :attribute.params.days is required and must be an integer between 1 and 365.');
                }
                break;

            case 'xp_threshold':
                $minXp = $params['min_xp'] ?? null;
                if (! is_int($minXp) || $minXp < 1 || $minXp > 100000) {
                    $fail('The :attribute.params.min_xp is required and must be an integer between 1 and 100000.');
                }
                break;

            case 'custom':
            default:
                // No structural requirement beyond params being an object.
                break;
        }
    }
}
