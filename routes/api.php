<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\GapAssessmentController;
use App\Http\Controllers\Api\SimulationController;
use App\Http\Controllers\Api\ModuleCrudController;
use App\Http\Middleware\CheckPermission;
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
Route::get('/public/consent/state', [\App\Http\Controllers\Api\ConsentLogController::class, 'state']);

// SSO Public Routes
Route::get('/sso/redirect', [\App\Http\Controllers\Api\SsoLoginController::class, 'redirect']);
Route::get('/sso/callback', [\App\Http\Controllers\Api\SsoLoginController::class, 'callback']);

// Threat Intel Webhook Receiver (SOCRadar, etc.)
Route::post('/webhooks/threat-intel/{org_id}', [\App\Http\Controllers\Api\ThreatIntelController::class, 'receive']);

});

// =============================================
// Protected Routes (Sanctum)
// =============================================
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {

    // Auth
    Route::get('/auth/me', [AuthController::class , 'me']);
    Route::post('/auth/logout', [AuthController::class , 'logout']);
    Route::put('/user/settings', [AuthController::class, 'updateSettings']);
    Route::put('/user/profile', [AuthController::class, 'updateProfile']);

    // Dashboard
    Route::get('/dashboard/stats', [DashboardController::class , 'stats']);
    Route::get('/dashboard/charts', [DashboardController::class , 'charts']);
    Route::get('/dashboard/risk-analytics', [DashboardController::class , 'riskAnalytics']);

    // Holding Dashboard (Hierarchical)
    Route::get('/holding/org-tree', [\App\Http\Controllers\Api\HoldingDashboardController::class, 'orgTree']);
    Route::get('/holding/dashboard', [\App\Http\Controllers\Api\HoldingDashboardController::class, 'dashboard']);
    Route::get('/holding/compliance-matrix', [\App\Http\Controllers\Api\HoldingDashboardController::class, 'complianceMatrix']);
    Route::get('/holding/sub-holding-breakdown', [\App\Http\Controllers\Api\HoldingDashboardController::class, 'subHoldingBreakdown']);

    // Log Analyzer
    Route::get('/system-logs', [\App\Http\Controllers\Api\LogAnalyzerController::class, 'index']);
    Route::post('/system-logs/analyze', [\App\Http\Controllers\Api\LogAnalyzerController::class, 'analyze']);

    // Terminal / Maintenance Core
    Route::get('/maintenance/seeders', [\App\Http\Controllers\Api\MaintenanceController::class, 'getSeeders']);
    Route::post('/maintenance/execute', [\App\Http\Controllers\Api\MaintenanceController::class, 'execute']);

    // Organization
    Route::get('/organization', [OrganizationController::class , 'show']);
    Route::put('/organization', [OrganizationController::class , 'update']);

    // Organization Configuration (Enterprise SSO)
    Route::get('/tenant-ssos', [\App\Http\Controllers\Api\TenantSsoController::class, 'show']);
    Route::put('/tenant-ssos', [\App\Http\Controllers\Api\TenantSsoController::class, 'update']);

    // Users
    Route::apiResource('/users', UserController::class);

    // Tenant Roles & Apps (On-Premise Beli Putus)
    Route::apiResource('/tenant-roles', \App\Http\Controllers\Api\TenantRoleController::class);
    Route::apiResource('/organization-apps', \App\Http\Controllers\Api\OrganizationAppController::class);
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
            Route::post('/comparisons', [\App\Http\Controllers\GapComparisonController::class, 'store'])->middleware('permission:gap_assessment,write');
            Route::get('/comparisons', [\App\Http\Controllers\GapComparisonController::class, 'index'])->middleware('permission:gap_assessment,read');
            Route::get('/', [GapAssessmentController::class , 'index'])->middleware('permission:gap_assessment,read');
            Route::get('/compare', [GapAssessmentController::class , 'compare'])->middleware('permission:gap_assessment,read');
            Route::get('/regulations', [GapAssessmentController::class , 'getRegulations'])->middleware('permission:gap_assessment,read');
            Route::get('/questions', [GapAssessmentController::class , 'questions'])->middleware('permission:gap_assessment,read');
            Route::post('/', [GapAssessmentController::class , 'store'])->middleware('permission:gap_assessment,write');
            Route::get('/{id}', [GapAssessmentController::class , 'show'])->middleware('permission:gap_assessment,read');
            Route::post('/{id}/submit', [GapAssessmentController::class , 'submitAnswers'])->middleware('permission:gap_assessment,write');
            Route::delete('/{id}', [GapAssessmentController::class , 'destroy'])->middleware('permission:gap_assessment,write');
            Route::post('/{id}/restore', [GapAssessmentController::class , 'restore'])->middleware('permission:gap_assessment,write');
            Route::delete('/{id}/force', [GapAssessmentController::class , 'forceDelete'])->middleware('permission:gap_assessment,write');
        }
        );

        // =============================================
        // Fire Drill Simulation — Interactive Scenarios
        // =============================================
        Route::prefix('simulations')->group(function () {

            Route::get('/scenarios', [SimulationController::class , 'scenarios'])->middleware('permission:simulation,read');
            Route::post('/', [SimulationController::class , 'store'])->middleware('permission:simulation,write');
            Route::get('/{id}', [SimulationController::class , 'show'])->middleware('permission:simulation,read');
            Route::post('/{id}/start', [SimulationController::class , 'start'])->middleware('permission:simulation,write');
            Route::post('/{id}/submit', [SimulationController::class , 'submitResponses'])->middleware('permission:simulation,write');
            Route::delete('/{id}', [SimulationController::class , 'destroy'])->middleware('permission:simulation,write');
            Route::post('/{id}/restore', [SimulationController::class , 'restore'])->middleware('permission:simulation,write');
            Route::delete('/{id}/force', [SimulationController::class , 'forceDelete'])->middleware('permission:simulation,write');
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
            // Module name mapping for permission check (URL slug -> permission module_id)
            // ropa->ropa, dpia->dpia, dsr->dsr, consent->consent, breach->breach, data-discovery->data_discovery
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
        // Phase 2: Vendor Risk Management
        // =============================================
        Route::prefix('vendor-risk')->group(function () {
            Route::get('/trashed', [\App\Http\Controllers\Api\VendorRiskController::class, 'trashed']);
            Route::get('/', [\App\Http\Controllers\Api\VendorRiskController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\Api\VendorRiskController::class, 'store']);
            Route::get('/{id}', [\App\Http\Controllers\Api\VendorRiskController::class, 'show']);
            Route::put('/{id}', [\App\Http\Controllers\Api\VendorRiskController::class, 'update']);
            Route::delete('/{id}', [\App\Http\Controllers\Api\VendorRiskController::class, 'destroy']);
            Route::post('/{id}/restore', [\App\Http\Controllers\Api\VendorRiskController::class, 'restore']);
            Route::delete('/{id}/force', [\App\Http\Controllers\Api\VendorRiskController::class, 'forceDelete']);
            // AI Assessment
            Route::post('/extract', [\App\Http\Controllers\Api\VendorRiskController::class, 'extract']);
            Route::post('/generate-questions', [\App\Http\Controllers\Api\VendorRiskController::class, 'generateQuestions']);
            Route::post('/assess', [\App\Http\Controllers\Api\VendorRiskController::class, 'assess']);
        });

        // =============================================
        // Phase 2: Cross Border Data Transfer
        // =============================================
        Route::prefix('cross-border')->group(function () {
            Route::get('/trashed', [\App\Http\Controllers\Api\CrossBorderController::class, 'trashed']);
            Route::get('/', [\App\Http\Controllers\Api\CrossBorderController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\Api\CrossBorderController::class, 'store']);
            Route::get('/{id}', [\App\Http\Controllers\Api\CrossBorderController::class, 'show']);
            Route::put('/{id}', [\App\Http\Controllers\Api\CrossBorderController::class, 'update']);
            Route::delete('/{id}', [\App\Http\Controllers\Api\CrossBorderController::class, 'destroy']);
            Route::post('/{id}/restore', [\App\Http\Controllers\Api\CrossBorderController::class, 'restore']);
            Route::delete('/{id}/force', [\App\Http\Controllers\Api\CrossBorderController::class, 'forceDelete']);
            
            // AI Transfer Impact Assessment (TIA)
            Route::post('/{id}/tia', [\App\Http\Controllers\Api\CrossBorderController::class, 'assessTIA']);
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
            Route::post('/{id}/test-connection', [\App\Http\Controllers\Api\DataDiscoveryController::class, 'testConnection'])->middleware('permission:data_discovery,read');
            Route::post('/{id}/scan', [\App\Http\Controllers\Api\DataDiscoveryController::class, 'triggerScan'])->middleware('permission:data_discovery,write');
            // AI Deep Scan (replaces standard scan view with AI recommendations)
            Route::post('/{id}/scan-ai', [\App\Http\Controllers\Api\DataDiscoveryController::class, 'scanAi'])->middleware('permission:data_discovery,write');
            Route::get('/{id}/scan-details', [\App\Http\Controllers\Api\DataDiscoveryController::class, 'scanDetails'])->middleware('permission:data_discovery,read');
            Route::put('/{id}/classify-column', [\App\Http\Controllers\Api\DataDiscoveryController::class, 'updateColumnClassification'])->middleware('permission:data_discovery,write');
            Route::get('/{id}/ropa-links', [\App\Http\Controllers\Api\DataDiscoveryController::class, 'ropaLinks'])->middleware('permission:data_discovery,read');
            Route::get('/search-dsr/subject', [\App\Http\Controllers\Api\DataDiscoveryController::class, 'searchSubject'])->middleware('permission:data_discovery,read');
            
            // AI Specific Search (Text-to-SQL Flow)
            Route::post('/{id}/search-ai', [\App\Http\Controllers\Api\DataDiscoveryController::class, 'specificSearchAi'])->middleware('permission:data_discovery,read');
            Route::get('/{id}/search-ai-history', [\App\Http\Controllers\Api\DataDiscoveryController::class, 'getSearchAiHistory'])->middleware('permission:data_discovery,read');
            Route::delete('/{id}/search-ai-history', [\App\Http\Controllers\Api\DataDiscoveryController::class, 'clearSearchAiHistory'])->middleware('permission:data_discovery,write');
            Route::delete('/{id}/search-ai-history/{historyId}', [\App\Http\Controllers\Api\DataDiscoveryController::class, 'deleteSearchAiHistory'])->middleware('permission:data_discovery,write');

            // Protection Assessment (Manual + AI)
            Route::get('/{id}/protection-assessment', [\App\Http\Controllers\Api\DataDiscoveryController::class, 'getProtectionAssessment'])->middleware('permission:data_discovery,read');
            Route::put('/{id}/protection-assessment', [\App\Http\Controllers\Api\DataDiscoveryController::class, 'saveProtectionAssessment'])->middleware('permission:data_discovery,write');
            Route::post('/{id}/ai-protection-assessment', [\App\Http\Controllers\Api\DataDiscoveryController::class, 'aiProtectionAssessment'])->middleware('permission:data_discovery,write');

            // AI Patrol & Daily Changelogs
            Route::get('/{id}/changelogs', [\App\Http\Controllers\Api\DiscoveryChangelogController::class, 'index'])->middleware('permission:data_discovery,read');
            Route::post('/{id}/changelogs', [\App\Http\Controllers\Api\DiscoveryChangelogController::class, 'store'])->middleware('permission:data_discovery,write');
            Route::post('/{id}/patrol-config', [\App\Http\Controllers\Api\DiscoveryChangelogController::class, 'saveConfig'])->middleware('permission:data_discovery,write');
        });
        
        // Consent Logs & Items
        Route::get('/consent-logs', [\App\Http\Controllers\Api\ConsentLogController::class, 'index'])->middleware('permission:consent,read');
        Route::post('/consent-items', [\App\Http\Controllers\Api\ConsentItemController::class, 'store'])->middleware('permission:consent,write');
        Route::put('/consent-items/{id}', [\App\Http\Controllers\Api\ConsentItemController::class, 'update'])->middleware('permission:consent,write');
        Route::delete('/consent-items/{id}', [\App\Http\Controllers\Api\ConsentItemController::class, 'destroy'])->middleware('permission:consent,write');
        Route::post('/consent/{id}/webhook', [\App\Http\Controllers\Api\ConsentLogController::class, 'saveWebhook'])->middleware('permission:consent,write');

        // Organization Profile (Onboarding)
        Route::get('/organizations', [\App\Http\Controllers\Api\OrganizationController::class, 'index']); // Super Admin: list all
        Route::get('/organization', [\App\Http\Controllers\Api\OrganizationController::class, 'show']);
        Route::put('/organization', [\App\Http\Controllers\Api\OrganizationController::class, 'update']);
        Route::post('/organizations/create-child', [\App\Http\Controllers\Api\OrganizationController::class, 'createChild']);
        Route::put('/organizations/{id}/hierarchy', [\App\Http\Controllers\Api\OrganizationController::class, 'updateHierarchy']);
        Route::post('/organizations/{id}/deactivate', [\App\Http\Controllers\Api\OrganizationController::class, 'deactivate']);
        Route::post('/organizations/{id}/restore', [\App\Http\Controllers\Api\OrganizationController::class, 'restore']);

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
        Route::get('/regulations', [\App\Http\Controllers\Api\GapAssessmentController::class, 'getRegulations']);

        // AI Provider Management (Multi-Provider LLM)
        Route::prefix('ai-providers')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\AiProviderController::class, 'index']);
            Route::get('/config', [\App\Http\Controllers\Api\AiProviderController::class, 'getConfig']);
            Route::post('/api-key', [\App\Http\Controllers\Api\AiProviderController::class, 'saveApiKey']);
            Route::post('/test', [\App\Http\Controllers\Api\AiProviderController::class, 'testConnection']);
            Route::post('/set-active', [\App\Http\Controllers\Api\AiProviderController::class, 'setActiveModel']);
            Route::delete('/api-key', [\App\Http\Controllers\Api\AiProviderController::class, 'removeApiKey']);
            // Admin CRUD
            Route::get('/admin', [\App\Http\Controllers\Api\AiProviderController::class, 'adminIndex']);
            Route::post('/', [\App\Http\Controllers\Api\AiProviderController::class, 'storeProvider']);
            Route::put('/{id}', [\App\Http\Controllers\Api\AiProviderController::class, 'updateProvider']);
            Route::delete('/{id}', [\App\Http\Controllers\Api\AiProviderController::class, 'destroyProvider']);
            // Trash & Restore (Providers)
            Route::get('/trash', [\App\Http\Controllers\Api\AiProviderController::class, 'trashedProviders']);
            Route::post('/{id}/restore', [\App\Http\Controllers\Api\AiProviderController::class, 'restoreProvider']);
            Route::delete('/{id}/force', [\App\Http\Controllers\Api\AiProviderController::class, 'forceDeleteProvider']);
            // Models CRUD
            Route::get('/{providerId}/models', [\App\Http\Controllers\Api\AiProviderController::class, 'listModels']);
            Route::post('/{providerId}/models', [\App\Http\Controllers\Api\AiProviderController::class, 'storeModel']);
            Route::put('/models/{modelId}', [\App\Http\Controllers\Api\AiProviderController::class, 'updateModel']);
            Route::delete('/models/{modelId}', [\App\Http\Controllers\Api\AiProviderController::class, 'destroyModel']);
            // Trash & Restore (Models)
            Route::get('/{providerId}/models/trash', [\App\Http\Controllers\Api\AiProviderController::class, 'trashedModels']);
            Route::post('/models/{modelId}/restore', [\App\Http\Controllers\Api\AiProviderController::class, 'restoreModel']);
            Route::delete('/models/{modelId}/force', [\App\Http\Controllers\Api\AiProviderController::class, 'forceDeleteModel']);
        });

        // Workflow Approvals
        Route::prefix('approvals')->group(function () {
            Route::get('/pending', [\App\Http\Controllers\Api\ApprovalController::class, 'pending']);
            Route::post('/{id}/approve', [\App\Http\Controllers\Api\ApprovalController::class, 'approve']);
            Route::post('/{id}/reject', [\App\Http\Controllers\Api\ApprovalController::class, 'reject']);
        });

        // Security Posture & Alerts (DSPM) Phase 4
        Route::prefix('security')->group(function () {
            Route::get('/posture', [\App\Http\Controllers\Api\PostureController::class, 'getPosture']);
            Route::get('/posture/trend', [\App\Http\Controllers\Api\PostureController::class, 'getTrend']);

            // Alert Engine
            Route::get('/alerts', [\App\Http\Controllers\Api\AlertController::class, 'index']);
            Route::get('/alerts/count', [\App\Http\Controllers\Api\AlertController::class, 'count']);
            Route::post('/alerts/scan', [\App\Http\Controllers\Api\AlertController::class, 'scan']);
            Route::post('/alerts/{id}/acknowledge', [\App\Http\Controllers\Api\AlertController::class, 'acknowledge']);
            Route::post('/alerts/{id}/resolve', [\App\Http\Controllers\Api\AlertController::class, 'resolve']);
            Route::post('/alerts/{id}/dismiss', [\App\Http\Controllers\Api\AlertController::class, 'dismiss']);
        });

        // Automation Rules (Phase 4)
        Route::prefix('automation')->group(function () {
            Route::get('/rules', [\App\Http\Controllers\Api\AutomationController::class, 'index']);
            Route::put('/rules/{ruleType}', [\App\Http\Controllers\Api\AutomationController::class, 'update']);
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
            Route::post('/{id}/revoke', [\App\Http\Controllers\Api\LicenseController::class, 'revoke']);
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
            Route::post('/gap_comparison/{id}/generate', [\App\Http\Controllers\Api\AiFeatureController::class, 'gapComparisonGenerate']);
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
            Route::post('/consent/{id}/audit', [\App\Http\Controllers\Api\AiFeatureController::class, 'consentAudit']);
            Route::post('/simulation/{id}/analysis', [\App\Http\Controllers\Api\AiFeatureController::class, 'simulationAnalysis']);
            Route::post('/drill/scenario', [\App\Http\Controllers\Api\AiFeatureController::class, 'drillScenarioGenerator']);
            Route::post('/data-discovery/{id}/classification', [\App\Http\Controllers\Api\AiFeatureController::class, 'dataDiscoveryClassification']);

            // Auto-Fill endpoints
            Route::post('/autofill/ropa', [\App\Http\Controllers\Api\AiFeatureController::class, 'autofillRopa']);
            Route::post('/autofill/dpia', [\App\Http\Controllers\Api\AiFeatureController::class, 'autofillDpia']);
            Route::post('/autofill/breach', [\App\Http\Controllers\Api\AiFeatureController::class, 'autofillBreach']);
            Route::post('/autofill/dsr', [\App\Http\Controllers\Api\AiFeatureController::class, 'autofillDsr']);
            Route::post('/autofill/consent-items/{id}', [\App\Http\Controllers\Api\AiFeatureController::class, 'autofillConsentItems']);
        });

        // AI Credit Management
        Route::get('/ai-credits/usage', [\App\Http\Controllers\Api\AiFeatureController::class, 'creditUsage']);
        Route::post('/ai-credits/topup', [\App\Http\Controllers\Api\AiFeatureController::class, 'creditTopup']);

        // =============================================
        // Template Export (Word/Excel — Formatted Documents)
        // =============================================
        Route::prefix('export-doc')->group(function () {
            Route::get('/ropa/{id}', [\App\Http\Controllers\Api\TemplateExportController::class, 'exportRopa']);
            Route::get('/dpia/{id}', [\App\Http\Controllers\Api\TemplateExportController::class, 'exportDpia']);
            Route::get('/gap/{id}', [\App\Http\Controllers\Api\TemplateExportController::class, 'exportGap']);
        });

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
        Route::post('/system/checkout-version', [\App\Http\Controllers\Api\SystemUpdateController::class, 'checkoutVersion']);

        // =============================================
        // API Keys (Developer/Tenant Integration)
        // =============================================
        Route::prefix('api-keys')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\ApiKeyController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\Api\ApiKeyController::class, 'store']);
            Route::delete('/{id}', [\App\Http\Controllers\Api\ApiKeyController::class, 'destroy']);
        });

        // =============================================
        // Per-Tenant Cloud Storage Settings
        // =============================================
        Route::prefix('storage-settings')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\StorageSettingsController::class, 'show']);
            Route::put('/', [\App\Http\Controllers\Api\StorageSettingsController::class, 'update']);
            Route::post('/test', [\App\Http\Controllers\Api\StorageSettingsController::class, 'testConnection']);
            Route::delete('/', [\App\Http\Controllers\Api\StorageSettingsController::class, 'destroy']);
        });

        // =============================================
        // Document Intelligence (Import & AI Mapping)
        // =============================================
        Route::prefix('documents')->group(function () {
            Route::post('/upload', [\App\Http\Controllers\Api\DocumentImportController::class, 'upload']);
            Route::post('/batch-upload', [\App\Http\Controllers\Api\DocumentImportController::class, 'batchUpload']);
            Route::get('/imports', [\App\Http\Controllers\Api\DocumentImportController::class, 'index']);
            Route::get('/imports/{id}', [\App\Http\Controllers\Api\DocumentImportController::class, 'show']);
            Route::put('/imports/{id}/approve', [\App\Http\Controllers\Api\DocumentImportController::class, 'approve']);
            Route::put('/imports/{id}/edit-mapping', [\App\Http\Controllers\Api\DocumentImportController::class, 'editMapping']);
            Route::delete('/imports/{id}', [\App\Http\Controllers\Api\DocumentImportController::class, 'destroy']);
            Route::get('/batches', [\App\Http\Controllers\Api\DocumentImportController::class, 'batches']);
            Route::get('/batches/{id}', [\App\Http\Controllers\Api\DocumentImportController::class, 'batchDetail']);
        });

        // =============================================
        // API Hub (Key Management, Webhooks, Usage)
        // =============================================
        Route::prefix('api-hub')->group(function () {
            Route::get('/docs', [\App\Http\Controllers\Api\ApiHubController::class, 'docs']);
            // API Keys
            Route::get('/keys', [\App\Http\Controllers\Api\ApiHubController::class, 'listKeys']);
            Route::post('/keys', [\App\Http\Controllers\Api\ApiHubController::class, 'createKey']);
            Route::put('/keys/{id}/toggle', [\App\Http\Controllers\Api\ApiHubController::class, 'toggleKey']);
            Route::delete('/keys/{id}', [\App\Http\Controllers\Api\ApiHubController::class, 'deleteKey']);
            // Usage
            Route::get('/usage', [\App\Http\Controllers\Api\ApiHubController::class, 'usage']);
            // Webhooks
            Route::get('/webhooks', [\App\Http\Controllers\Api\ApiHubController::class, 'listWebhooks']);
            Route::post('/webhooks', [\App\Http\Controllers\Api\ApiHubController::class, 'createWebhook']);
            Route::put('/webhooks/{id}/toggle', [\App\Http\Controllers\Api\ApiHubController::class, 'toggleWebhook']);
            Route::delete('/webhooks/{id}', [\App\Http\Controllers\Api\ApiHubController::class, 'deleteWebhook']);
        });

        // =============================================
        // Integrations (Telegram, SIEM, SOAR, SOCRadar)
        // =============================================
        Route::prefix('integrations')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\IntegrationController::class, 'index']);
            Route::put('/{provider}', [\App\Http\Controllers\Api\IntegrationController::class, 'update']);
            Route::post('/{provider}/test', [\App\Http\Controllers\Api\IntegrationController::class, 'test']);
            Route::delete('/{provider}', [\App\Http\Controllers\Api\IntegrationController::class, 'destroy']);
            // Breach sync shortcuts
            Route::post('/breach/{id}/telegram', [\App\Http\Controllers\Api\IntegrationController::class, 'syncBreachTelegram']);
            Route::post('/breach/{id}/siem', [\App\Http\Controllers\Api\IntegrationController::class, 'syncBreachSiem']);
        });

    });

// =============================================
// Public Partner API v1 (authenticated via X-Api-Key)
// =============================================
Route::prefix('v1')->middleware(\App\Http\Middleware\AuthenticatePartnerApi::class)->group(function () {
    // Breach Management
    Route::get('/breach/stats', [\App\Http\Controllers\Api\V1\BreachApiController::class, 'stats']);
    Route::get('/breach', [\App\Http\Controllers\Api\V1\BreachApiController::class, 'index']);
    Route::get('/breach/{id}', [\App\Http\Controllers\Api\V1\BreachApiController::class, 'show']);
    Route::post('/breach', [\App\Http\Controllers\Api\V1\BreachApiController::class, 'store']);
    Route::put('/breach/{id}', [\App\Http\Controllers\Api\V1\BreachApiController::class, 'update']);
});
