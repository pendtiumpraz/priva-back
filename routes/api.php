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
Route::middleware('throttle:api')->group(function () {
    Route::post('/auth/register', [AuthController::class , 'register']);
    Route::post('/auth/login', [AuthController::class , 'login']);

// Public Feature Requests (read-only + upvote)
Route::get('/public/feature-requests', [\App\Http\Controllers\Api\FeatureRequestController::class, 'publicIndex']);
Route::post('/public/feature-requests', [\App\Http\Controllers\Api\FeatureRequestController::class, 'publicStore']);
Route::post('/public/feature-requests/{id}/upvote', [\App\Http\Controllers\Api\FeatureRequestController::class, 'upvote']);

// Public Consent API (for banner integration)
Route::post('/public/consent', [\App\Http\Controllers\Api\ConsentLogController::class, 'capture']);
Route::get('/public/consent/config', [\App\Http\Controllers\Api\ConsentLogController::class, 'config']);

});

// =============================================
// Protected Routes (Sanctum)
// =============================================
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {

    // Auth
    Route::get('/auth/me', [AuthController::class , 'me']);
    Route::post('/auth/logout', [AuthController::class , 'logout']);

    // Dashboard
    Route::get('/dashboard/stats', [DashboardController::class , 'stats']);
    Route::get('/dashboard/charts', [DashboardController::class , 'charts']);
    Route::get('/dashboard/risk-analytics', [DashboardController::class , 'riskAnalytics']);

    // Organization
    Route::get('/organization', [OrganizationController::class , 'show']);
    Route::put('/organization', [OrganizationController::class , 'update']);

    // Users
    Route::apiResource('/users', UserController::class);
    Route::post('/users/{id}/restore', [UserController::class, 'restore']);

    // Departments
    Route::get('/departments', [\App\Http\Controllers\Api\DepartmentController::class, 'index']);
    Route::post('/departments', [\App\Http\Controllers\Api\DepartmentController::class, 'store']);
    Route::put('/departments/{id}', [\App\Http\Controllers\Api\DepartmentController::class, 'update']);
    Route::delete('/departments/{id}', [\App\Http\Controllers\Api\DepartmentController::class, 'destroy']);

    // Positions
    Route::get('/positions', [\App\Http\Controllers\Api\PositionController::class, 'index']);
    Route::post('/positions', [\App\Http\Controllers\Api\PositionController::class, 'store']);
    Route::put('/positions/{id}', [\App\Http\Controllers\Api\PositionController::class, 'update']);
    Route::delete('/positions/{id}', [\App\Http\Controllers\Api\PositionController::class, 'destroy']);

    // DPO Users (for auto-fill in ROPA/DPIA)
    Route::get('/dpo-users', [\App\Http\Controllers\Api\PositionController::class, 'dpoUsers']);

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
        });

        // =============================================
        // Breach Integrations (Telegram War Room, SIEM/SOAR)
        // =============================================
        Route::prefix('integrations')->group(function () {
            Route::get('/settings', [\App\Http\Controllers\Api\IntegrationController::class, 'getSettings']);
            Route::put('/settings', [\App\Http\Controllers\Api\IntegrationController::class, 'updateSettings']);
            Route::post('/breach/{id}/notify-telegram', [\App\Http\Controllers\Api\IntegrationController::class, 'syncBreachTelegram']);
            Route::post('/breach/{id}/notify-siem', [\App\Http\Controllers\Api\IntegrationController::class, 'syncBreachSiem']);
        });

        // =============================================
        // Data Discovery — Advanced Endpoints
        // =============================================
        Route::prefix('data-discovery')->group(function () {
            Route::post('/{id}/test-connection', [\App\Http\Controllers\Api\DataDiscoveryController::class, 'testConnection']);
            Route::post('/{id}/scan', [\App\Http\Controllers\Api\DataDiscoveryController::class, 'triggerScan']);
            Route::get('/{id}/scan-details', [\App\Http\Controllers\Api\DataDiscoveryController::class, 'scanDetails']);
            Route::put('/{id}/classify-column', [\App\Http\Controllers\Api\DataDiscoveryController::class, 'updateColumnClassification']);
            Route::get('/{id}/ropa-links', [\App\Http\Controllers\Api\DataDiscoveryController::class, 'ropaLinks']);
            Route::get('/search-dsr/subject', [\App\Http\Controllers\Api\DataDiscoveryController::class, 'searchSubject']);
        });
        
        // Consent Logs & Items
        Route::get('/consent-logs', [\App\Http\Controllers\Api\ConsentLogController::class, 'index']);
        Route::post('/consent-items', [\App\Http\Controllers\Api\ConsentItemController::class, 'store']);
        Route::put('/consent-items/{id}', [\App\Http\Controllers\Api\ConsentItemController::class, 'update']);
        Route::delete('/consent-items/{id}', [\App\Http\Controllers\Api\ConsentItemController::class, 'destroy']);
        Route::post('/consent/{id}/webhook', [\App\Http\Controllers\Api\ConsentLogController::class, 'saveWebhook']);

        // Organization Profile (Onboarding)
        Route::get('/organizations', [\App\Http\Controllers\Api\OrganizationController::class, 'index']); // Super Admin: list all
        Route::get('/organization', [\App\Http\Controllers\Api\OrganizationController::class, 'show']);
        Route::put('/organization', [\App\Http\Controllers\Api\OrganizationController::class, 'update']);

        // CRM Integration
        Route::prefix('crm')->group(function () {
            Route::get('/config', [\App\Http\Controllers\Api\OrganizationController::class, 'getCrmConfig']);
            Route::put('/config', [\App\Http\Controllers\Api\OrganizationController::class, 'saveCrmConfig']);
            Route::post('/test-connection', [\App\Http\Controllers\Api\OrganizationController::class, 'testCrmConnection']);
            Route::post('/sync', [\App\Http\Controllers\Api\OrganizationController::class, 'syncCrmData']);
            Route::delete('/disconnect', [\App\Http\Controllers\Api\OrganizationController::class, 'disconnectCrm']);
        });

        // Templates
        Route::get('/templates/dpia', [\App\Http\Controllers\Api\DashboardController::class, 'downloadDpiaTemplate']);

        // AI Provider Management (Multi-Provider LLM)
        Route::prefix('ai-providers')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\AiProviderController::class, 'index']);
            Route::get('/config', [\App\Http\Controllers\Api\AiProviderController::class, 'getConfig']);
            Route::post('/api-key', [\App\Http\Controllers\Api\AiProviderController::class, 'saveApiKey']);
            Route::post('/test', [\App\Http\Controllers\Api\AiProviderController::class, 'testConnection']);
            Route::post('/set-active', [\App\Http\Controllers\Api\AiProviderController::class, 'setActiveModel']);
            Route::delete('/api-key', [\App\Http\Controllers\Api\AiProviderController::class, 'removeApiKey']);
        });

        // =============================================
        // License Management
        // =============================================
        Route::prefix('licenses')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\LicenseController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\Api\LicenseController::class, 'store']);
            Route::get('/verify', [\App\Http\Controllers\Api\LicenseController::class, 'verify']);
            Route::post('/activate', [\App\Http\Controllers\Api\LicenseController::class, 'activate']);
            Route::get('/pricing', [\App\Http\Controllers\Api\LicenseController::class, 'pricingIndex']);
            Route::put('/pricing', [\App\Http\Controllers\Api\LicenseController::class, 'pricingUpdate']);
            Route::get('/{id}', [\App\Http\Controllers\Api\LicenseController::class, 'show']);
            Route::put('/{id}', [\App\Http\Controllers\Api\LicenseController::class, 'update']);
            Route::delete('/{id}', [\App\Http\Controllers\Api\LicenseController::class, 'destroy']);
            Route::post('/{id}/restore', [\App\Http\Controllers\Api\LicenseController::class, 'restore']);
        });

        // =============================================
        // AI Chat Assistant (Knowledge Base)
        // =============================================
        Route::post('/ai/chat', [\App\Http\Controllers\Api\AiChatController::class, 'chat']);
        Route::match(['get', 'put'], '/ai/knowledge-base', [\App\Http\Controllers\Api\AiChatController::class, 'knowledgeBase']);
        Route::match(['get', 'put'], '/ai/settings', [\App\Http\Controllers\Api\AiChatController::class, 'apiSettings']);
        Route::post('/ai/test-connection', [\App\Http\Controllers\Api\AiChatController::class, 'testConnection']);

        // Chat History & Admin CS
        Route::get('/ai/conversations', [\App\Http\Controllers\Api\AiChatController::class, 'conversations']);
        Route::get('/ai/conversations/{id}', [\App\Http\Controllers\Api\AiChatController::class, 'conversationMessages']);
        Route::post('/ai/conversations/{id}/reply', [\App\Http\Controllers\Api\AiChatController::class, 'adminReply']);
        Route::get('/ai/conversations/{id}/poll', [\App\Http\Controllers\Api\AiChatController::class, 'pollMessages']);

        // =============================================
        // AI Features (License-Gated)
        // =============================================
        Route::prefix('ai-features')->group(function () {
            Route::post('/gap/{id}/remediation', [\App\Http\Controllers\Api\AiFeatureController::class, 'gapRemediation']);
            Route::post('/ropa/{id}/analysis', [\App\Http\Controllers\Api\AiFeatureController::class, 'ropaAnalysis']);
            Route::post('/dpia/{id}/risk-scoring', [\App\Http\Controllers\Api\AiFeatureController::class, 'dpiaRiskScoring']);
            Route::post('/breach/{id}/advisor', [\App\Http\Controllers\Api\AiFeatureController::class, 'breachAdvisor']);
            Route::post('/dsr/{id}/draft', [\App\Http\Controllers\Api\AiFeatureController::class, 'dsrDraft']);
            Route::post('/consent/generate', [\App\Http\Controllers\Api\AiFeatureController::class, 'consentGenerator']);
            Route::get('/dashboard/summary', [\App\Http\Controllers\Api\AiFeatureController::class, 'dashboardSummary']);
            Route::post('/drill/scenario', [\App\Http\Controllers\Api\AiFeatureController::class, 'drillScenario']);
            Route::get('/history/{featureType}/{recordId}', [\App\Http\Controllers\Api\AiFeatureController::class, 'history']);
            Route::post('/contract/review', [\App\Http\Controllers\Api\AiFeatureController::class, 'contractReview']);

            // Auto-Fill endpoints
            Route::post('/autofill/ropa', [\App\Http\Controllers\Api\AiFeatureController::class, 'autofillRopa']);
            Route::post('/autofill/dpia', [\App\Http\Controllers\Api\AiFeatureController::class, 'autofillDpia']);
            Route::post('/autofill/breach', [\App\Http\Controllers\Api\AiFeatureController::class, 'autofillBreach']);
            Route::post('/autofill/dsr', [\App\Http\Controllers\Api\AiFeatureController::class, 'autofillDsr']);
        });

        // AI Credit Management
        Route::get('/ai-credits/usage', [\App\Http\Controllers\Api\AiFeatureController::class, 'creditUsage']);
        Route::post('/ai-credits/topup', [\App\Http\Controllers\Api\AiFeatureController::class, 'creditTopup']);

        // =============================================
        // Export (CSV / JSON)
        // =============================================
        Route::prefix('export')->group(function () {
            Route::get('/ropa', [\App\Http\Controllers\Api\ExportController::class, 'ropa']);
            Route::get('/dpia', [\App\Http\Controllers\Api\ExportController::class, 'dpia']);
            Route::get('/breach', [\App\Http\Controllers\Api\ExportController::class, 'breach']);
            Route::get('/dsr', [\App\Http\Controllers\Api\ExportController::class, 'dsr']);
            Route::get('/consent', [\App\Http\Controllers\Api\ExportController::class, 'consent']);
            Route::get('/consent-records', [\App\Http\Controllers\Api\ExportController::class, 'consentRecords']);
            Route::get('/gap-assessment', [\App\Http\Controllers\Api\ExportController::class, 'gapAssessment']);
            Route::get('/data-discovery', [\App\Http\Controllers\Api\ExportController::class, 'dataDiscovery']);
            Route::get('/data-discovery-columns', [\App\Http\Controllers\Api\ExportController::class, 'dataDiscoveryColumns']);
            Route::get('/simulation', [\App\Http\Controllers\Api\ExportController::class, 'simulation']);
            Route::get('/ai-results', [\App\Http\Controllers\Api\ExportController::class, 'aiResults']);
            Route::get('/ai-results/{id}', [\App\Http\Controllers\Api\ExportController::class, 'aiResultSingle']);
            Route::get('/compliance-report', [\App\Http\Controllers\Api\ExportController::class, 'complianceReport']);
        });

        // =============================================
        // AI Agent (Enterprise only — function calling)
        // =============================================
        Route::prefix('ai-agent')->group(function () {
            Route::post('/chat', [\App\Http\Controllers\Api\AiAgentController::class, 'chat']);
            Route::get('/mentions/{type}', [\App\Http\Controllers\Api\AiAgentController::class, 'mentions']);
            Route::get('/history', [\App\Http\Controllers\Api\AiAgentController::class, 'history']);
            Route::get('/history/{id}/messages', [\App\Http\Controllers\Api\AiAgentController::class, 'conversationMessages']);
        });

        // =============================================
        // System / Superadmin Tools
        // =============================================
        Route::get('/system/check-update', [\App\Http\Controllers\Api\SystemUpdateController::class, 'checkUpdate']);
        Route::post('/system/update-backend', [\App\Http\Controllers\Api\SystemUpdateController::class, 'updateBackend']);

    });

