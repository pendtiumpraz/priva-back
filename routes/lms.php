<?php

use App\Http\Controllers\Lms\Admin\BadgeAdminController;
use App\Http\Controllers\Lms\Admin\CourseAdminController;
use App\Http\Controllers\Lms\Admin\LessonAdminController;
use App\Http\Controllers\Lms\Admin\LmsAdminStatsController;
use App\Http\Controllers\Lms\Admin\ModuleAdminController;
use App\Http\Controllers\Lms\Admin\QuizAdminController;
use App\Http\Controllers\Lms\Admin\QuizQuestionAdminController;
use App\Http\Controllers\Lms\Admin\UserAdminController;
use App\Http\Controllers\Lms\Admin\VideoAdminController;
use App\Http\Controllers\Lms\CourseController;
use App\Http\Controllers\Lms\FeatureDocQuizController;
use App\Http\Controllers\Lms\LeaderboardController;
use App\Http\Controllers\Lms\MeController;
use App\Http\Controllers\Lms\QuizController;
use App\Http\Controllers\Lms\VideoPlaybackController;
use Illuminate\Support\Facades\Route;

// ---- Learner: read ----
Route::get('me/dashboard',     [MeController::class, 'dashboard']);
Route::get('me/courses',       [MeController::class, 'courses']);
Route::get('me/badges',        [MeController::class, 'badges']);
Route::get('me/bookmarks',     [MeController::class, 'bookmarks']);
Route::get('me/notes',         [MeController::class, 'notes']);
Route::get('me/progress',      [MeController::class, 'progress']);
Route::get('me/xp-stats',      [MeController::class, 'xpStats']);

Route::get('courses',                                                  [CourseController::class, 'index']);
Route::get('courses/{slug}',                                           [CourseController::class, 'show']);
Route::get('courses/{courseSlug}/modules/{moduleSlug}',                [CourseController::class, 'showModule']);
Route::get('courses/{courseSlug}/modules/{moduleSlug}/lessons/{lessonSlug}', [CourseController::class, 'showLesson']);
Route::get('quizzes',                                                  [QuizController::class, 'findByOwner']);
Route::get('quizzes/{id}',                                             [QuizController::class, 'show']);
Route::get('leaderboard',                                              [LeaderboardController::class, 'index']);
Route::get('videos/{video}/playback-token',                            [VideoPlaybackController::class, 'token']);

// ---- Feature-doc quizzes (Phase 6) ----
Route::get('feature-doc-quizzes',                                      [FeatureDocQuizController::class, 'findByOwner']);
Route::get('feature-doc-quizzes/{quiz}',                               [FeatureDocQuizController::class, 'show']);
Route::post('feature-doc-quizzes/{quiz}/attempt',                      [FeatureDocQuizController::class, 'attempt']);

// ---- Learner: write ----
Route::post('me/lessons/{id}/complete',          [MeController::class, 'completeLesson']);
Route::post('me/lessons/{id}/progress',          [MeController::class, 'lessonProgress']);
Route::post('quizzes/{id}/attempts',             [QuizController::class, 'attempt']);
Route::post('courses/{slug}/exam/attempts',      [CourseController::class, 'examAttempt']);
Route::post('me/bookmarks',                      [MeController::class, 'bookmarkCreate']);
Route::delete('me/bookmarks/{lessonId}',         [MeController::class, 'bookmarkDelete']);
Route::put('me/notes/{lessonId}',                [MeController::class, 'noteUpsert']);

// ---- Admin ----
Route::prefix('admin')->group(function () {
    // Content admin: courses/modules/lessons/quizzes/questions/badges/videos.
    Route::middleware('permission:lms.content_admin')->group(function () {
        Route::apiResource('courses',              CourseAdminController::class);
        // NOTE: reorder must be registered before apiResource('courses.modules') below,
        // otherwise apiResource matches `/reorder` as a module id and shadows this route.
        Route::post('courses/{course}/modules/reorder', [ModuleAdminController::class, 'reorder']);
        Route::apiResource('courses.modules',      ModuleAdminController::class)->shallow();
        Route::apiResource('modules.lessons',      LessonAdminController::class)->shallow();
        Route::apiResource('quizzes',              QuizAdminController::class);
        // reorder before apiResource so it isn't shadowed by the nested store route.
        Route::post('quizzes/{quiz}/questions/reorder', [QuizQuestionAdminController::class, 'reorder']);
        Route::apiResource('quizzes.questions',    QuizQuestionAdminController::class)->shallow();
        Route::get('badges/{badge}/awards',        [BadgeAdminController::class, 'awards']);
        Route::apiResource('badges',               BadgeAdminController::class);
        Route::get('videos',                       [VideoAdminController::class, 'index']);
        Route::post('videos',                      [VideoAdminController::class, 'store']);
    });

    // User admin: read-only viewer (5.4-BE). Spec D2 — separate permission key.
    // Account management is Privasimu Nexus responsibility (no create/update/delete).
    Route::middleware('permission:lms.user_admin')->group(function () {
        Route::get('users', [UserAdminController::class, 'index']);
    });

    // Landing dashboard stats (Task 4-BE). Root/superadmin only.
    Route::middleware('role.root')->group(function () {
        Route::get('stats', [LmsAdminStatsController::class, 'index']);
    });
});
