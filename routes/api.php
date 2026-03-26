<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\GapAssessmentController;
use App\Http\Controllers\Api\SimulationController;
use App\Http\Controllers\Api\ModuleCrudController;
use Illuminate\Support\Facades\Route;

/* |-------------------------------------------------------------------------- | PRIVASIMU API Routes |-------------------------------------------------------------------------- */

// =============================================
// Public Routes
// =============================================
Route::post('/auth/register', [AuthController::class , 'register']);
Route::post('/auth/login', [AuthController::class , 'login']);

// Public Feature Requests (read-only + upvote)
Route::get('/public/feature-requests', [\App\Http\Controllers\Api\FeatureRequestController::class, 'publicIndex']);
Route::post('/public/feature-requests', [\App\Http\Controllers\Api\FeatureRequestController::class, 'publicStore']);
Route::post('/public/feature-requests/{id}/upvote', [\App\Http\Controllers\Api\FeatureRequestController::class, 'upvote']);

// =============================================
// Protected Routes (Sanctum)
// =============================================
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::get('/auth/me', [AuthController::class , 'me']);
    Route::post('/auth/logout', [AuthController::class , 'logout']);

    // Dashboard
    Route::get('/dashboard/stats', [DashboardController::class , 'stats']);
    Route::get('/dashboard/charts', [DashboardController::class , 'charts']);

    // Organization
    Route::get('/organization', [OrganizationController::class , 'show']);
    Route::put('/organization', [OrganizationController::class , 'update']);

    // Users
    Route::apiResource('/users', UserController::class);
    Route::post('/users/{id}/restore', [UserController::class, 'restore']);

    // =============================================
    // GAP Assessment — Real Compliance Engine
    // =============================================
    Route::prefix('gap')->group(function () {
            Route::get('/', [GapAssessmentController::class , 'index']);
            Route::get('/questions', [GapAssessmentController::class , 'questions']);
            Route::post('/', [GapAssessmentController::class , 'store']);
            Route::get('/{id}', [GapAssessmentController::class , 'show']);
            Route::post('/{id}/submit', [GapAssessmentController::class , 'submitAnswers']);
            Route::delete('/{id}', [GapAssessmentController::class , 'destroy']);
            Route::post('/{id}/restore', [GapAssessmentController::class , 'restore']);
            Route::delete('/{id}/force', [GapAssessmentController::class , 'forceDelete']);
        }
        );

        // =============================================
        // Fire Drill Simulation — Interactive Scenarios
        // =============================================
        Route::prefix('simulations')->group(function () {
            Route::get('/', [SimulationController::class , 'index']);
            Route::get('/scenarios', [SimulationController::class , 'scenarios']);
            Route::post('/', [SimulationController::class , 'store']);
            Route::get('/{id}', [SimulationController::class , 'show']);
            Route::post('/{id}/start', [SimulationController::class , 'start']);
            Route::post('/{id}/submit', [SimulationController::class , 'submitResponses']);
            Route::delete('/{id}', [SimulationController::class , 'destroy']);
            Route::post('/{id}/restore', [SimulationController::class , 'restore']);
            Route::delete('/{id}/force', [SimulationController::class , 'forceDelete']);
        }
        );

        // =============================================
        // Feature Requests
        // =============================================
        Route::prefix('feature-requests')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\FeatureRequestController::class , 'index']);
            Route::post('/', [\App\Http\Controllers\Api\FeatureRequestController::class , 'store']);
            Route::get('/{id}', [\App\Http\Controllers\Api\FeatureRequestController::class , 'show']);
            Route::put('/{id}', [\App\Http\Controllers\Api\FeatureRequestController::class , 'update']);
            Route::post('/{id}/upvote', [\App\Http\Controllers\Api\FeatureRequestController::class , 'upvote']);
            Route::delete('/{id}', [\App\Http\Controllers\Api\FeatureRequestController::class , 'destroy']);
            Route::post('/{id}/restore', [\App\Http\Controllers\Api\FeatureRequestController::class , 'restore']);
            Route::delete('/{id}/force', [\App\Http\Controllers\Api\FeatureRequestController::class , 'forceDelete']);
        }
        );

        // =============================================
        // Universal Module CRUD (ROPA, DPIA, DSR, Consent, Breach, Data Discovery)
        // =============================================
        Route::prefix('m/{module}')->where(['module' => 'ropa|dpia|dsr|consent|breach|data-discovery'])->group(function () {
            Route::get('/', [ModuleCrudController::class , 'index']);
            Route::post('/', [ModuleCrudController::class , 'store']);
            Route::get('/{id}', [ModuleCrudController::class , 'show']);
            Route::put('/{id}', [ModuleCrudController::class , 'update']);
            Route::get('/{id}/history', [ModuleCrudController::class , 'history']);
            Route::delete('/{id}', [ModuleCrudController::class , 'destroy']);
            Route::post('/{id}/restore', [ModuleCrudController::class , 'restore']);
            Route::delete('/{id}/force', [ModuleCrudController::class , 'forceDelete']);
        }
        );    });
