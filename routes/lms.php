<?php

use App\Http\Controllers\Lms\Admin\BadgeAdminController;
use App\Http\Controllers\Lms\Admin\CertificateAdminController;
use App\Http\Controllers\Lms\Admin\CourseAdminController;
use App\Http\Controllers\Lms\Admin\LessonAdminController;
use App\Http\Controllers\Lms\Admin\ModuleAdminController;
use App\Http\Controllers\Lms\Admin\QuizAdminController;
use App\Http\Controllers\Lms\Admin\QuizQuestionAdminController;
use App\Http\Controllers\Lms\Admin\UserAdminController;
use App\Http\Controllers\Lms\Admin\VideoAdminController;
use App\Http\Controllers\Lms\CourseController;
use App\Http\Controllers\Lms\LeaderboardController;
use App\Http\Controllers\Lms\MeController;
use App\Http\Controllers\Lms\QuizController;
use Illuminate\Support\Facades\Route;

// ---- Learner: read ----
Route::get('me/dashboard',     [MeController::class, 'dashboard']);
Route::get('me/courses',       [MeController::class, 'courses']);
Route::get('me/badges',        [MeController::class, 'badges']);
Route::get('me/certificates',  [MeController::class, 'certificates']);
Route::get('me/bookmarks',     [MeController::class, 'bookmarks']);
Route::get('me/notes',         [MeController::class, 'notes']);
Route::get('me/progress',      [MeController::class, 'progress']);

Route::get('courses',                                                  [CourseController::class, 'index']);
Route::get('courses/{slug}',                                           [CourseController::class, 'show']);
Route::get('courses/{courseSlug}/modules/{moduleSlug}',                [CourseController::class, 'showModule']);
Route::get('courses/{courseSlug}/modules/{moduleSlug}/lessons/{lessonSlug}', [CourseController::class, 'showLesson']);
Route::get('quizzes',                                                  [QuizController::class, 'findByOwner']);
Route::get('quizzes/{id}',                                             [QuizController::class, 'show']);
Route::get('leaderboard',                                              [LeaderboardController::class, 'index']);

// ---- Learner: write ----
Route::post('me/lessons/{id}/complete',          [MeController::class, 'completeLesson']);
Route::post('me/lessons/{id}/progress',          [MeController::class, 'lessonProgress']);
Route::post('quizzes/{id}/attempts',             [QuizController::class, 'attempt']);
Route::post('courses/{slug}/exam/attempts',      [CourseController::class, 'examAttempt']);
Route::post('me/bookmarks',                      [MeController::class, 'bookmarkCreate']);
Route::delete('me/bookmarks/{lessonId}',         [MeController::class, 'bookmarkDelete']);
Route::put('me/notes/{lessonId}',                [MeController::class, 'noteUpsert']);
Route::post('courses/{slug}/certificate',        [CourseController::class, 'issueCertificate']);

// ---- Admin ----
Route::prefix('admin')->group(function () {
    Route::apiResource('courses',              CourseAdminController::class);
    Route::apiResource('courses.modules',      ModuleAdminController::class)->shallow();
    Route::apiResource('modules.lessons',      LessonAdminController::class)->shallow();
    Route::apiResource('quizzes',              QuizAdminController::class);
    Route::apiResource('quizzes.questions',    QuizQuestionAdminController::class)->shallow();
    Route::get('users',                        [UserAdminController::class, 'index']);
    Route::post('certificates/{id}/revoke',    [CertificateAdminController::class, 'revoke']);
    Route::apiResource('badges',               BadgeAdminController::class);
    Route::post('videos',                      [VideoAdminController::class, 'store']);
});
