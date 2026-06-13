<?php

namespace App\Lms\Concerns;

use App\Lms\Models\Course;
use App\Lms\Models\Module;
use App\Lms\Models\Quiz;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Polymorphic owner-scope helpers for Quiz admin.
 *
 * Used by QuizAdminController and QuizQuestionAdminController to resolve
 * the owner chain (module/course/feature_doc) -> org and enforce the same
 * tenant-scoping rules as OrgScopedQuery applies to first-class resources.
 *
 * Quizzes don't carry a direct org_id; org-scope is derived from the parent:
 *   owner_type='module'      -> Module -> Course (org_id)
 *   owner_type='course'      -> Course (org_id)
 *   owner_type='feature_doc' -> global (root-only mutate; tenants read)
 *
 * Requires the consuming class to also use OrgScopedQuery so isRootUser()
 * and assertMutable() are available.
 */
trait QuizOwnerResolution
{
    /**
     * Restrict an admin index/show query so tenant admins only see quizzes
     * whose parent (module->course or course) is in their org or global.
     * feature_doc quizzes are global; tenant admins read but cannot mutate.
     *
     * Uses correlated whereExists subqueries to avoid materializing a
     * potentially large id set via pluck() at scale.
     */
    protected function scopeQuizzesForAdmin(Builder $query): void
    {
        $user = auth()->user();
        if ($this->isRootUser($user)) {
            return;
        }

        $query->where(function ($q) use ($user) {
            // module-owned: parent module's course must be in scope
            $q->where(function ($qq) use ($user) {
                $qq->where('owner_type', 'module')
                   ->whereExists(function ($sub) use ($user) {
                       $sub->select(DB::raw(1))
                           ->from('lms_modules')
                           ->whereColumn('lms_modules.id', DB::raw('CAST(lms_quizzes.owner_key AS INTEGER)'))
                           ->whereExists(function ($csub) use ($user) {
                               $csub->select(DB::raw(1))
                                    ->from('lms_courses')
                                    ->whereColumn('lms_courses.id', 'lms_modules.course_id')
                                    ->where(function ($org) use ($user) {
                                        $org->where('lms_courses.org_id', $user->org_id)
                                            ->orWhereNull('lms_courses.org_id');
                                    });
                           });
                   });
            })
            // course-owned: course in scope
            ->orWhere(function ($qq) use ($user) {
                $qq->where('owner_type', 'course')
                   ->whereExists(function ($sub) use ($user) {
                       $sub->select(DB::raw(1))
                           ->from('lms_courses')
                           ->whereColumn('lms_courses.id', DB::raw('CAST(lms_quizzes.owner_key AS INTEGER)'))
                           ->where(function ($org) use ($user) {
                               $org->where('lms_courses.org_id', $user->org_id)
                                   ->orWhereNull('lms_courses.org_id');
                           });
                   });
            })
            // feature_doc: global, readable by all tenants
            ->orWhere('owner_type', 'feature_doc');
        });
    }

    /**
     * Whether the currently authed user may at least *see* this quiz.
     * Mirrors scopeQuizzesForAdmin with single-row checks.
     */
    protected function canViewQuiz(Quiz $quiz): bool
    {
        $user = auth()->user();
        if ($this->isRootUser($user)) {
            return true;
        }

        if ($quiz->owner_type === 'feature_doc') {
            return true; // global, read-only for tenants
        }

        if ($quiz->owner_type === 'module') {
            $module = Module::with('course')->find((int) $quiz->owner_key);
            if (! $module || ! $module->course) {
                return false;
            }
            return $module->course->org_id === $user->org_id || $module->course->org_id === null;
        }

        if ($quiz->owner_type === 'course') {
            $course = Course::find((int) $quiz->owner_key);
            if (! $course) {
                return false;
            }
            return $course->org_id === $user->org_id || $course->org_id === null;
        }

        return false;
    }

    /**
     * Throw 403/404 if the current user cannot mutate the parent of this quiz.
     */
    protected function assertOwnerMutable(string $ownerType, string $ownerKey): void
    {
        if ($ownerType === 'feature_doc') {
            $user = auth()->user();
            if (! $this->isRootUser($user)) {
                abort(403, 'Feature-doc quizzes are read-only for tenant admins.');
            }
            return;
        }

        if ($ownerType === 'module') {
            $module = Module::with('course')->find((int) $ownerKey);
            if (! $module || ! $module->course) {
                abort(404);
            }
            $this->assertMutable($module->course);
            return;
        }

        if ($ownerType === 'course') {
            $course = Course::find((int) $ownerKey);
            if (! $course) {
                abort(404);
            }
            $this->assertMutable($course);
            return;
        }

        abort(422, 'Unknown owner_type.');
    }
}
