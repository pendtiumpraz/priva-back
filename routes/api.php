<?php

use App\Http\Controllers\Api\Admin\LandingAdminController;
use App\Http\Controllers\Api\AiAgentController;
use App\Http\Controllers\Api\AiChatController;
use App\Http\Controllers\Api\AiFeatureController;
use App\Http\Controllers\Api\AiProviderController;
use App\Http\Controllers\Api\AlertController;
use App\Http\Controllers\Api\ApiHubController;
use App\Http\Controllers\Api\ApiKeyController;
use App\Http\Controllers\Api\ApprovalController;
use App\Http\Controllers\Api\AssessmentsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AutomationController;
use App\Http\Controllers\Api\AvatarChatController;
use App\Http\Controllers\Api\BreachReportController;
use App\Http\Controllers\Api\ConsentCollectionController;
use App\Http\Controllers\Api\ConsentItemController;
use App\Http\Controllers\Api\ConsentLogController;
use App\Http\Controllers\Api\ContainmentController;
use App\Http\Controllers\Api\ContractReviewCrudController;
use App\Http\Controllers\Api\CrossBorderController;
use App\Http\Controllers\Api\CustomFieldController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DataDiscoveryController;
use App\Http\Controllers\Api\DecryptorProfileController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\DiscoveryChangelogController;
use App\Http\Controllers\Api\DocumentImportController;
use App\Http\Controllers\Api\DocumentTemplateController;
use App\Http\Controllers\Api\DpiaAssessmentFrameworkController;
use App\Http\Controllers\Api\DpiaRiskEventTemplateController;
use App\Http\Controllers\Api\DpiaRtpController;
use App\Http\Controllers\Api\DsrAppController;
use App\Http\Controllers\Api\DsrExecutionController;
use App\Http\Controllers\Api\DsrPublicController;
use App\Http\Controllers\Api\DsrRequestScopeController;
use App\Http\Controllers\Api\DsrSqlPackController;
use App\Http\Controllers\Api\DsrVerificationController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\FeatureRequestController;
use App\Http\Controllers\Api\GapAssessmentController;
use App\Http\Controllers\Api\HoldingDashboardController;
use App\Http\Controllers\Api\IntegrationController;
use App\Http\Controllers\Api\KnowledgeBaseController;
use App\Http\Controllers\Api\LicenseController;
use App\Http\Controllers\Api\LogAnalyzerController;
use App\Http\Controllers\Api\MaintenanceController;
use App\Http\Controllers\Api\MenuRegistryController;
use App\Http\Controllers\Api\ModuleCommentController;
use App\Http\Controllers\Api\ModuleCrudController;
use App\Http\Controllers\Api\NotificationPreferenceController;
use App\Http\Controllers\Api\OrganizationAppController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\PlatformConfigController;
use App\Http\Controllers\Api\PolicyReviewCrudController;
use App\Http\Controllers\Api\PositionController;
use App\Http\Controllers\Api\PostureController;
use App\Http\Controllers\Api\ProcessingCategoryController;
use App\Http\Controllers\Api\PublicLandingController;
use App\Http\Controllers\Api\RaciTemplateController;
use App\Http\Controllers\Api\RetentionPolicyController;
use App\Http\Controllers\Api\RiskTreatmentPlanController;
use App\Http\Controllers\Api\RootDashboardController;
use App\Http\Controllers\Api\RopaApprovalController;
use App\Http\Controllers\Api\RopaLinkController;
use App\Http\Controllers\Api\RopaTemplateController;
use App\Http\Controllers\Api\SimulationController;
use App\Http\Controllers\Api\SsoLoginController;
use App\Http\Controllers\Api\DatabasePoolController;
use App\Http\Controllers\Api\PlatformStorageSettingsController;
use App\Http\Controllers\Api\StoragePoolController;
use App\Http\Controllers\Api\StorageSettingsController;
use App\Http\Controllers\Api\SystemUpdateController;
use App\Http\Controllers\Api\TemplateExportController;
use App\Http\Controllers\Api\TenantExportController;
use App\Http\Controllers\Api\TenantOffboardController;
use App\Http\Controllers\Api\TenantRoleController;
use App\Http\Controllers\Api\TenantSsoController;
use App\Http\Controllers\Api\TenantThemeController;
use App\Http\Controllers\Api\ThreatIntelController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\V1\BreachApiController;
use App\Http\Controllers\Api\V1\ConsentApiV1Controller;
use App\Http\Controllers\Api\V1\DsrApiV1Controller;
use App\Http\Controllers\Api\VendorRiskController;
use App\Http\Controllers\Api\VoiceTtsController;
use App\Http\Controllers\GapComparisonController;
use App\Http\Middleware\AuthenticatePartnerApi;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Support\Facades\Route;

/* |-------------------------------------------------------------------------- | PRIVASIMU API Routes |-------------------------------------------------------------------------- */

// =============================================
// Public Routes
// =============================================
Route::middleware('throttle:api')->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);

    // Public Feature Requests (read-only + upvote)
    Route::get('/public/feature-requests', [FeatureRequestController::class, 'publicIndex']);
    Route::post('/public/feature-requests', [FeatureRequestController::class, 'publicStore']);
    Route::post('/public/feature-requests/{id}/upvote', [FeatureRequestController::class, 'upvote']);

    // Public Consent API (for banner integration)
    Route::post('/public/consent', [ConsentLogController::class, 'capture']);
    Route::post('/public/consent/capture', [ConsentLogController::class, 'capture']); // alias for clarity
    Route::get('/public/consent/config', [ConsentLogController::class, 'config']);
    Route::get('/public/consent/state', [ConsentLogController::class, 'state']);

    // Public Cookie Banner API v2 (Phase B — anonymous visitor capture)
    Route::post('/v2/cookies/capture', [\App\Http\Controllers\Api\V2\CookieCaptureController::class, 'capture']);
    Route::get('/v2/cookies/state', [\App\Http\Controllers\Api\V2\CookieCaptureController::class, 'state']);
    Route::post('/v2/cookies/withdraw', [\App\Http\Controllers\Api\V2\CookieCaptureController::class, 'withdraw']);

    // =============================================
    // Public DSR endpoints (untuk embed widget di klien websites)
    // =============================================
    Route::get('/public/dsr/config/{embed_token}', [DsrPublicController::class, 'config']);
    Route::post('/public/dsr/submit/{embed_token}', [DsrPublicController::class, 'submit'])
        ->middleware('throttle:30,1');  // 30 req/min per IP
    Route::get('/public/dsr/verify/{token}', [DsrPublicController::class, 'verify']);

    // SSO Public Routes
    Route::get('/sso/redirect', [SsoLoginController::class, 'redirect']);
    Route::get('/sso/callback', [SsoLoginController::class, 'callback']);

    // Threat Intel Webhook Receiver (SOCRadar, etc.)
    Route::post('/webhooks/threat-intel/{org_id}', [ThreatIntelController::class, 'receive']);

    // Public: unsubscribe via signed URL (from email footer)
    Route::get('/notifications/unsubscribe', [NotificationPreferenceController::class, 'unsubscribe'])
        ->name('notifications.unsubscribe');

    // =============================================
    // Public Landing Page — read endpoints (cached) + lead capture
    // =============================================
    Route::prefix('public/landing')->group(function () {
        Route::get('/bundle', [PublicLandingController::class, 'bundle']);
        Route::get('/settings', [PublicLandingController::class, 'settings']);
        Route::get('/features', [PublicLandingController::class, 'features']);
        Route::get('/team', [PublicLandingController::class, 'team']);
        Route::get('/testimonials', [PublicLandingController::class, 'testimonials']);
        Route::get('/logos', [PublicLandingController::class, 'logos']);
        Route::get('/products', [PublicLandingController::class, 'products']);
        Route::get('/products/{slug}', [PublicLandingController::class, 'productDetail']);
        Route::get('/stats', [PublicLandingController::class, 'stats']);
        // Contact / Demo lead capture (rate-limited dalam controller)
        Route::post('/lead', [PublicLandingController::class, 'submitLead']);
    });

});

// =============================================
// Protected Routes (Sanctum)
// =============================================
Route::middleware(['auth:sanctum', 'throttle:api', 'tenant.context', 'tenant.db'])->group(function () {

    // Auth
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::put('/user/settings', [AuthController::class, 'updateSettings']);
    Route::put('/user/profile', [AuthController::class, 'updateProfile']);

    // Dashboard
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
    Route::get('/dashboard/charts', [DashboardController::class, 'charts']);
    Route::get('/dashboard/risk-analytics', [DashboardController::class, 'riskAnalytics']);

    // Holding Dashboard (Hierarchical)
    Route::get('/holding/org-tree', [HoldingDashboardController::class, 'orgTree']);
    Route::get('/holding/dashboard', [HoldingDashboardController::class, 'dashboard']);
    Route::get('/holding/compliance-matrix', [HoldingDashboardController::class, 'complianceMatrix']);
    Route::get('/holding/sub-holding-breakdown', [HoldingDashboardController::class, 'subHoldingBreakdown']);

    // Log Analyzer
    Route::get('/system-logs', [LogAnalyzerController::class, 'index']);
    Route::post('/system-logs/analyze', [LogAnalyzerController::class, 'analyze']);

    // Terminal / Maintenance Core
    Route::get('/maintenance/seeders', [MaintenanceController::class, 'getSeeders']);
    Route::post('/maintenance/execute', [MaintenanceController::class, 'execute']);

    // Organization
    Route::get('/organization', [OrganizationController::class, 'show']);
    Route::put('/organization', [OrganizationController::class, 'update']);

    // Organization Configuration (Enterprise SSO)
    Route::get('/tenant-ssos', [TenantSsoController::class, 'show']);
    Route::put('/tenant-ssos', [TenantSsoController::class, 'update']);

    // Users
    Route::apiResource('/users', UserController::class);

    // Tenant Roles & Apps (On-Premise Beli Putus)
    Route::apiResource('/tenant-roles', TenantRoleController::class);
    Route::apiResource('/organization-apps', OrganizationAppController::class);
    Route::post('/users/{id}/restore', [UserController::class, 'restore']);

    // Departments

    Route::get('/departments', [DepartmentController::class, 'index']);
    Route::post('/departments', [DepartmentController::class, 'store']);
    Route::put('/departments/{id}', [DepartmentController::class, 'update']);
    Route::delete('/departments/{id}', [DepartmentController::class, 'destroy']);

    // Positions
    Route::get('/positions', [PositionController::class, 'index']);
    Route::post('/positions', [PositionController::class, 'store']);
    Route::put('/positions/{id}', [PositionController::class, 'update']);
    Route::delete('/positions/{id}', [PositionController::class, 'destroy']);

    // DPO Users (for auto-fill in ROPA/DPIA)
    Route::get('/dpo-users', [PositionController::class, 'dpoUsers']);

    // =============================================
    // GAP Assessment — Real Compliance Engine
    // =============================================
    Route::prefix('gap')->group(
        function () {
            Route::post('/comparisons', [GapComparisonController::class, 'store'])->middleware('permission:gap_assessment,write');
            Route::get('/comparisons', [GapComparisonController::class, 'index'])->middleware('permission:gap_assessment,read');
            Route::get('/', [GapAssessmentController::class, 'index'])->middleware('permission:gap_assessment,read');
            Route::get('/compare', [GapAssessmentController::class, 'compare'])->middleware('permission:gap_assessment,read');
            Route::get('/regulations', [GapAssessmentController::class, 'getRegulations'])->middleware('permission:gap_assessment,read');
            Route::get('/questions', [GapAssessmentController::class, 'questions'])->middleware('permission:gap_assessment,read');
            Route::post('/', [GapAssessmentController::class, 'store'])->middleware('permission:gap_assessment,write');
            Route::get('/{id}', [GapAssessmentController::class, 'show'])->middleware('permission:gap_assessment,read');
            Route::post('/{id}/submit', [GapAssessmentController::class, 'submitAnswers'])->middleware('permission:gap_assessment,write');
            Route::delete('/{id}', [GapAssessmentController::class, 'destroy'])->middleware('permission:gap_assessment,write');
            Route::post('/{id}/restore', [GapAssessmentController::class, 'restore'])->middleware('permission:gap_assessment,write');
            Route::delete('/{id}/force', [GapAssessmentController::class, 'forceDelete'])->middleware('permission:gap_assessment,write');
            Route::post('/{id}/upload-evidence', [GapAssessmentController::class, 'uploadEvidence'])->middleware('permission:gap_assessment,write');

            // Custom Questions CRUD (Sprint B2)
            Route::get('/custom-questions', [GapAssessmentController::class, 'customQuestions'])->middleware('permission:gap_assessment,read');
            Route::post('/custom-questions', [GapAssessmentController::class, 'storeCustomQuestion'])->middleware('permission:gap_assessment,write');
            Route::put('/custom-questions/{id}', [GapAssessmentController::class, 'updateCustomQuestion'])->middleware('permission:gap_assessment,write');
            Route::delete('/custom-questions/{id}', [GapAssessmentController::class, 'destroyCustomQuestion'])->middleware('permission:gap_assessment,write');
        }
    );

    // =============================================
    // Fire Drill Simulation — Interactive Scenarios
    // =============================================
    Route::prefix('simulations')->group(
        function () {

            Route::get('/scenarios', [SimulationController::class, 'scenarios'])->middleware('permission:simulation,read');
            Route::post('/', [SimulationController::class, 'store'])->middleware('permission:simulation,write');
            Route::get('/{id}', [SimulationController::class, 'show'])->middleware('permission:simulation,read');
            Route::post('/{id}/start', [SimulationController::class, 'start'])->middleware('permission:simulation,write');
            Route::post('/{id}/submit', [SimulationController::class, 'submitResponses'])->middleware('permission:simulation,write');
            Route::delete('/{id}', [SimulationController::class, 'destroy'])->middleware('permission:simulation,write');
            Route::post('/{id}/restore', [SimulationController::class, 'restore'])->middleware('permission:simulation,write');
            Route::delete('/{id}/force', [SimulationController::class, 'forceDelete'])->middleware('permission:simulation,write');
        }
    );

    // =============================================
    // Feature Requests
    // =============================================
    Route::prefix('feature-requests')->group(
        function () {
            Route::get('/', [FeatureRequestController::class, 'index']);
            Route::post('/', [FeatureRequestController::class, 'store']);
            Route::get('/{id}', [FeatureRequestController::class, 'show']);
            Route::put('/{id}', [FeatureRequestController::class, 'update']);
            Route::post('/{id}/upvote', [FeatureRequestController::class, 'upvote']);
            Route::delete('/{id}', [FeatureRequestController::class, 'destroy']);
            Route::post('/{id}/restore', [FeatureRequestController::class, 'restore']);
            Route::delete('/{id}/force', [FeatureRequestController::class, 'forceDelete']);
        }
    );

    // =============================================
    // Universal Module CRUD (ROPA, DPIA, DSR, Consent, Breach, Data Discovery)
    // =============================================
    Route::prefix('m/{module}')->where(['module' => 'ropa|dpia|dsr|consent|breach|data-discovery'])->group(function () {
        // Module name mapping for permission check (URL slug -> permission module_id)
        // ropa->ropa, dpia->dpia, dsr->dsr, consent->consent, breach->breach, data-discovery->data_discovery
        Route::get('/', [ModuleCrudController::class, 'index']);
        Route::post('/', [ModuleCrudController::class, 'store']);
        Route::get('/{id}', [ModuleCrudController::class, 'show']);
        Route::put('/{id}', [ModuleCrudController::class, 'update']);
        Route::get('/{id}/history', [ModuleCrudController::class, 'history']);
        Route::delete('/{id}', [ModuleCrudController::class, 'destroy']);
        Route::post('/{id}/restore', [ModuleCrudController::class, 'restore']);
        Route::delete('/{id}/force', [ModuleCrudController::class, 'forceDelete']);
    });

    // =============================================
    // DPIA — Risk Event Template Library (read-only, seeded)
    // =============================================
    Route::get('/dpia/risk-event-templates', [DpiaRiskEventTemplateController::class, 'index'])
        ->middleware('permission:dpia,read');

    // =============================================
    // DPIA — Assessment Framework (DPO-customizable categories + risks)
    // =============================================
    Route::prefix('dpia/framework')->group(function () {
        Route::get('/categories', [DpiaAssessmentFrameworkController::class, 'index'])->middleware('permission:dpia,read');
        Route::post('/categories', [DpiaAssessmentFrameworkController::class, 'storeCategory'])->middleware('permission:dpia,write');
        Route::put('/categories/{id}', [DpiaAssessmentFrameworkController::class, 'updateCategory'])->middleware('permission:dpia,write');
        Route::delete('/categories/{id}', [DpiaAssessmentFrameworkController::class, 'destroyCategory'])->middleware('permission:dpia,write');
        Route::post('/categories/{categoryId}/risks', [DpiaAssessmentFrameworkController::class, 'storeRisk'])->middleware('permission:dpia,write');
        Route::put('/categories/{categoryId}/risks/{id}', [DpiaAssessmentFrameworkController::class, 'updateRisk'])->middleware('permission:dpia,write');
        Route::delete('/categories/{categoryId}/risks/{id}', [DpiaAssessmentFrameworkController::class, 'destroyRisk'])->middleware('permission:dpia,write');
        Route::post('/reset', [DpiaAssessmentFrameworkController::class, 'reset'])->middleware('permission:dpia,write');
    });

    // =============================================
    // DPIA — Risk Treatment Plan (per-DPIA endpoints)
    // Track mitigation execution: status, owner, deadline, evidence, residual risk.
    // =============================================
    Route::prefix('dpia/{id}/rtp')->where(['id' => '[0-9a-fA-F-]{36}'])->group(function () {
        Route::get('/', [DpiaRtpController::class, 'index'])->middleware('permission:dpia,read');
        Route::post('/', [DpiaRtpController::class, 'store'])->middleware('permission:dpia,write');
        Route::post('/auto-generate', [DpiaRtpController::class, 'autoGenerate'])->middleware('permission:dpia,write');
        Route::post('/clean-orphans', [DpiaRtpController::class, 'cleanOrphans'])->middleware('permission:dpia,write');
        Route::put('/{itemId}', [DpiaRtpController::class, 'update'])->middleware('permission:dpia,write');
        Route::delete('/{itemId}', [DpiaRtpController::class, 'destroy'])->middleware('permission:dpia,write');
    });

    // =============================================
    // RTP — Cross-DPIA Aggregate View (menu terpisah)
    // =============================================
    Route::prefix('rtp')->group(function () {
        Route::get('/', [RiskTreatmentPlanController::class, 'index'])->middleware('permission:dpia,read');
        Route::get('/facets', [RiskTreatmentPlanController::class, 'facets'])->middleware('permission:dpia,read');
        Route::get('/dashboard', [RiskTreatmentPlanController::class, 'dashboard'])->middleware('permission:dpia,read');
    });

    // =============================================
    // ROPA — DPO Approval Workflow
    // =============================================
    Route::prefix('ropa/{id}')->group(function () {
        Route::post('/submit', [RopaApprovalController::class, 'submit'])->middleware('permission:ropa,write');
        Route::post('/approve', [RopaApprovalController::class, 'approve'])->middleware('permission:ropa,write');
        Route::post('/reject', [RopaApprovalController::class, 'reject'])->middleware('permission:ropa,write');
    });

    // =============================================
    // ROPA — Industry Templates (seeded library)
    // =============================================
    Route::get('/ropa-templates', [RopaTemplateController::class, 'index'])->middleware('permission:ropa,read');
    Route::get('/ropa-templates/{id}', [RopaTemplateController::class, 'show'])->middleware('permission:ropa,read');

    // =============================================
    // Processing Categories — used for ROPA/DPIA naming (ROPA-HR-001).
    // Org-scoped, user-extendable via LazySearchSelect allowCreate.
    // =============================================
    Route::prefix('processing-categories')->group(function () {
        Route::get('/', [ProcessingCategoryController::class, 'index']);
        Route::post('/', [ProcessingCategoryController::class, 'store']);
        Route::put('/{id}', [ProcessingCategoryController::class, 'update']);
        Route::delete('/{id}', [ProcessingCategoryController::class, 'destroy']);
    });

    // =============================================
    // Breach Containment Templates + RACI Matrix
    // =============================================
    Route::prefix('containment-templates')->group(function () {
        Route::get('/', [ContainmentController::class, 'listTemplates']);
        Route::get('/trash', [ContainmentController::class, 'listTrashed']);
        Route::post('/', [ContainmentController::class, 'createTemplate'])->middleware('permission:breach,write');
        Route::put('/{id}', [ContainmentController::class, 'updateTemplate'])->middleware('permission:breach,write');
        Route::delete('/{id}', [ContainmentController::class, 'deleteTemplate'])->middleware('permission:breach,write');
        Route::post('/{id}/restore', [ContainmentController::class, 'restoreTemplate'])->middleware('permission:breach,write');
        Route::delete('/{id}/force', [ContainmentController::class, 'forceDeleteTemplate'])->middleware('permission:breach,write');
    });
    Route::post('/breach/{breachId}/apply-template', [ContainmentController::class, 'applyTemplate'])->middleware('permission:breach,write');
    Route::post('/breach/{breachId}/containment', [ContainmentController::class, 'addStep'])->middleware('permission:breach,write');
    Route::put('/breach/{breachId}/containment/{stepKey}', [ContainmentController::class, 'updateStep'])->middleware('permission:breach,write');
    Route::delete('/breach/{breachId}/containment/{stepKey}', [ContainmentController::class, 'removeStep'])->middleware('permission:breach,write');

    // Breach PDF report generators (accept ?size=a4|letter|legal|a3|a5|folio & ?orientation=portrait|landscape)
    Route::get('/breach/{id}/pdf/komdigi', [BreachReportController::class, 'komdigi'])->middleware('permission:breach,read');
    Route::get('/breach/{id}/pdf/subject-letter', [BreachReportController::class, 'subjectLetter'])->middleware('permission:breach,read');
    Route::get('/breach/{id}/pdf/full-report', [BreachReportController::class, 'fullReport'])->middleware('permission:breach,read');

    // Document Templates — per-tenant picker + customization
    // NOTE: static-path routes MUST be declared before any `{id}` route
    // or Laravel matches "/active-map", "/preview", "/docx-placeholders"
    // as a DocumentTemplate lookup with id="active-map" → 404 findOrFail.
    Route::prefix('document-templates')->group(function () {
        // Static paths first
        Route::get('/active-map', [DocumentTemplateController::class, 'activeMap']);
        Route::put('/active-map', [DocumentTemplateController::class, 'updateActiveMap']);
        Route::post('/preview', [DocumentTemplateController::class, 'preview'])->name('document-templates.preview');
        Route::post('/upload-asset', [DocumentTemplateController::class, 'uploadAsset']);
        Route::get('/docx-placeholders', [DocumentTemplateController::class, 'docxPlaceholders']);

        // Dynamic `{id}` paths after
        Route::get('/', [DocumentTemplateController::class, 'index']);
        Route::post('/', [DocumentTemplateController::class, 'store']);
        Route::post('/{id}/activate', [DocumentTemplateController::class, 'activate'])->where('id', '[0-9a-fA-F-]{36}');
        Route::post('/{id}/upload-docx', [DocumentTemplateController::class, 'uploadDocx'])->where('id', '[0-9a-fA-F-]{36}');
        Route::delete('/{id}/docx/{kind}', [DocumentTemplateController::class, 'deleteDocx'])->where('id', '[0-9a-fA-F-]{36}');
        Route::get('/{id}', [DocumentTemplateController::class, 'show'])->where('id', '[0-9a-fA-F-]{36}');
        Route::put('/{id}', [DocumentTemplateController::class, 'update'])->where('id', '[0-9a-fA-F-]{36}');
        Route::delete('/{id}', [DocumentTemplateController::class, 'destroy'])->where('id', '[0-9a-fA-F-]{36}');
    });
    Route::get('/raci-matrix', [ContainmentController::class, 'getRaciMatrix']);
    Route::put('/raci-matrix', [ContainmentController::class, 'updateRaciMatrix']);

    // Per-breach RACI matrix edit (Phase G1) — single save endpoint the
    // matrix modal posts to. Apply-template overlays tenant RACI presets.
    Route::put('/breach/{id}/containment-raci', [ContainmentController::class, 'updateRaciForBreach'])->middleware('permission:breach,write');
    Route::post('/breach/{id}/apply-raci-template', [ContainmentController::class, 'applyRaciTemplate'])->middleware('permission:breach,write');

    // RACI template library (per-tenant, with system presets)
    Route::prefix('raci-templates')->middleware('permission:breach,read')->group(function () {
        Route::get('/', [RaciTemplateController::class, 'index']);
        Route::get('/trash', [RaciTemplateController::class, 'trash']);
        Route::get('/{id}', [RaciTemplateController::class, 'show']);
    });
    Route::prefix('raci-templates')->middleware('permission:breach,write')->group(function () {
        Route::post('/', [RaciTemplateController::class, 'store']);
        Route::put('/{id}', [RaciTemplateController::class, 'update']);
        Route::delete('/{id}', [RaciTemplateController::class, 'destroy']);
        Route::post('/{id}/restore', [RaciTemplateController::class, 'restore']);
        Route::delete('/{id}/force', [RaciTemplateController::class, 'forceDelete']);
    });

    // Retention master data (Sprint E3) — reusable library referenced from ROPA wizard step 7
    Route::prefix('retention-policies')->middleware('permission:ropa,read')->group(function () {
        Route::get('/', [RetentionPolicyController::class, 'index']);
        Route::get('/{id}', [RetentionPolicyController::class, 'show']);
    });
    Route::prefix('retention-policies')->middleware('permission:ropa,write')->group(function () {
        Route::post('/', [RetentionPolicyController::class, 'store']);
        Route::put('/{id}', [RetentionPolicyController::class, 'update']);
        Route::delete('/{id}', [RetentionPolicyController::class, 'destroy']);
        Route::post('/{id}/restore', [RetentionPolicyController::class, 'restore']);
        Route::delete('/{id}/force', [RetentionPolicyController::class, 'forceDelete']);
    });

    // =============================================
    // Contract Review CRUD
    // =============================================
    Route::prefix('contract-reviews')->group(function () {
        Route::get('/trashed', [ContractReviewCrudController::class, 'trashed']);
        Route::get('/', [ContractReviewCrudController::class, 'index']);
        Route::get('/{id}', [ContractReviewCrudController::class, 'show']);
        Route::delete('/{id}', [ContractReviewCrudController::class, 'destroy']);
        Route::post('/{id}/restore', [ContractReviewCrudController::class, 'restore']);
        Route::delete('/{id}/force', [ContractReviewCrudController::class, 'forceDelete']);
    });

    // =============================================
    // Policy Review CRUD
    // =============================================
    Route::prefix('policy-reviews')->group(function () {
        Route::get('/trashed', [PolicyReviewCrudController::class, 'trashed']);
        Route::get('/', [PolicyReviewCrudController::class, 'index']);
        Route::get('/{id}', [PolicyReviewCrudController::class, 'show']);
        Route::delete('/{id}', [PolicyReviewCrudController::class, 'destroy']);
        Route::post('/{id}/restore', [PolicyReviewCrudController::class, 'restore']);
        Route::delete('/{id}/force', [PolicyReviewCrudController::class, 'forceDelete']);
    });

    // =============================================
    // Phase 2: Vendor Risk Management (Third Party Management)
    // =============================================
    Route::prefix('vendor-risk')->group(function () {
        Route::get('/trashed', [VendorRiskController::class, 'trashed']);
        Route::get('/', [VendorRiskController::class, 'index']);
        Route::post('/', [VendorRiskController::class, 'store']);
        Route::get('/{id}', [VendorRiskController::class, 'show']);
        Route::put('/{id}', [VendorRiskController::class, 'update']);
        Route::delete('/{id}', [VendorRiskController::class, 'destroy']);
        Route::post('/{id}/restore', [VendorRiskController::class, 'restore']);
        Route::delete('/{id}/force', [VendorRiskController::class, 'forceDelete']);
        // AI Assessment
        Route::post('/extract', [VendorRiskController::class, 'extract']);
        Route::post('/generate-questions', [VendorRiskController::class, 'generateQuestions']);
        Route::post('/assess', [VendorRiskController::class, 'assess']);

        // Sprint D3: TPRM document management
        Route::post('/{id}/documents', [VendorRiskController::class, 'uploadDocument']);
        Route::delete('/{id}/documents/{docId}', [VendorRiskController::class, 'deleteDocument']);
        Route::post('/{id}/screen-documents', [VendorRiskController::class, 'screenDocuments']);
        // Re-assessment (manual atau AI) — replaces "belum diimplementasi" placeholder
        Route::post('/{id}/reassess', [VendorRiskController::class, 'reassess']);
    });

    // =============================================
    // Phase 2: Cross Border Data Transfer
    // =============================================
    Route::prefix('cross-border')->group(function () {
        Route::get('/trashed', [CrossBorderController::class, 'trashed']);
        Route::get('/', [CrossBorderController::class, 'index']);
        Route::post('/', [CrossBorderController::class, 'store']);
        Route::get('/{id}', [CrossBorderController::class, 'show']);
        Route::put('/{id}', [CrossBorderController::class, 'update']);
        Route::delete('/{id}', [CrossBorderController::class, 'destroy']);
        Route::post('/{id}/restore', [CrossBorderController::class, 'restore']);
        Route::delete('/{id}/force', [CrossBorderController::class, 'forceDelete']);

        // AI Transfer Impact Assessment (TIA)
        Route::post('/{id}/tia', [CrossBorderController::class, 'assessTIA']);
    });

    // =============================================
    // Breach Integrations (Telegram War Room, SIEM/SOAR)
    // =============================================
    Route::prefix('integrations')->group(function () {
        Route::get('/settings', [IntegrationController::class, 'getSettings']);
        Route::put('/settings', [IntegrationController::class, 'updateSettings']);
        Route::post('/breach/{id}/notify-telegram', [IntegrationController::class, 'syncBreachTelegram']);
        Route::post('/breach/{id}/notify-siem', [IntegrationController::class, 'syncBreachSiem']);
    });

    // =============================================
    // Data Discovery — Advanced Endpoints
    // =============================================
    Route::prefix('data-discovery')->group(function () {
        Route::post('/{id}/test-connection', [DataDiscoveryController::class, 'testConnection'])->middleware('permission:data_discovery,read');
        Route::post('/{id}/scan', [DataDiscoveryController::class, 'triggerScan'])->middleware('permission:data_discovery,write');
        // AI Deep Scan (replaces standard scan view with AI recommendations)
        Route::post('/{id}/scan-ai', [DataDiscoveryController::class, 'scanAi'])->middleware('permission:data_discovery,write');
        Route::get('/{id}/scan-details', [DataDiscoveryController::class, 'scanDetails'])->middleware('permission:data_discovery,read');
        Route::put('/{id}/classify-column', [DataDiscoveryController::class, 'updateColumnClassification'])->middleware('permission:data_discovery,write');
        Route::get('/{id}/ropa-links', [DataDiscoveryController::class, 'ropaLinks'])->middleware('permission:data_discovery,read');

        // Many-to-many ROPA pivot management
        Route::get('/{id}/ropas', [RopaLinkController::class, 'indexForInformationSystem'])->middleware('permission:data_discovery,read');
        Route::put('/{id}/ropas', [RopaLinkController::class, 'syncForInformationSystem'])->middleware('permission:data_discovery,write');
        Route::post('/{id}/ropas', [RopaLinkController::class, 'attachToInformationSystem'])->middleware('permission:data_discovery,write');
        Route::delete('/{id}/ropas/{ropaId}', [RopaLinkController::class, 'detachFromInformationSystem'])
            ->where(['ropaId' => '[0-9a-fA-F-]{36}'])->middleware('permission:data_discovery,write');
        Route::get('/search-dsr/subject', [DataDiscoveryController::class, 'searchSubject'])->middleware('permission:data_discovery,read');

        // AI Specific Search (Text-to-SQL Flow, two-step for privacy)
        //   POST /search-ai        → AI generates SQL from schema metadata only
        //   POST /search-ai/execute → user explicitly runs the SQL (no AI involved)
        Route::post('/{id}/search-ai', [DataDiscoveryController::class, 'specificSearchAi'])->middleware('permission:data_discovery,read');
        Route::post('/{id}/search-ai/execute', [DataDiscoveryController::class, 'specificSearchExecute'])->middleware('permission:data_discovery,read');

        // Decryptor Profiles (per-system tenant encryption keys, wrapped at rest)
        //   GET    /decryptor-profiles       → list (metadata only, key hidden)
        //   POST   /decryptor-profiles       → create with raw key (wrapped & stored)
        //   PUT    /decryptor-profiles/{pid} → update (optional key rotation)
        //   DELETE /decryptor-profiles/{pid} → remove
        //   POST   /decryptor-profiles/{pid}/test → verify key against a sample ciphertext
        Route::get('/{id}/decryptor-profiles', [DecryptorProfileController::class, 'index'])->middleware('permission:data_discovery,read');
        Route::post('/{id}/decryptor-profiles', [DecryptorProfileController::class, 'store'])->middleware('permission:data_discovery,write');
        Route::put('/{id}/decryptor-profiles/{profileId}', [DecryptorProfileController::class, 'update'])->middleware('permission:data_discovery,write');
        Route::delete('/{id}/decryptor-profiles/{profileId}', [DecryptorProfileController::class, 'destroy'])->middleware('permission:data_discovery,write');
        Route::post('/{id}/decryptor-profiles/{profileId}/test', [DecryptorProfileController::class, 'test'])->middleware('permission:data_discovery,write');

        // Leak Detection (dark-web style match → parametrized verify)
        //   POST /leak/match-schema → AI finds candidate table from leaked column sequence (schema only)
        //   POST /leak/verify       → parametrized query runs with user-supplied values (no AI)
        //   GET  /leak/history      → recent verification history (metadata + masked sample only)
        //   DELETE /leak/history/{hid} → delete one entry
        //   DELETE /leak/history     → clear all for this system
        Route::post('/{id}/leak/match-schema', [DataDiscoveryController::class, 'leakMatchSchema'])->middleware('permission:data_discovery,read');
        Route::post('/{id}/leak/verify', [DataDiscoveryController::class, 'leakVerify'])->middleware('permission:data_discovery,read');
        Route::get('/{id}/leak/history', [DataDiscoveryController::class, 'leakHistory'])->middleware('permission:data_discovery,read');
        Route::delete('/{id}/leak/history', [DataDiscoveryController::class, 'clearLeakHistory'])->middleware('permission:data_discovery,write');
        Route::delete('/{id}/leak/history/{historyId}', [DataDiscoveryController::class, 'deleteLeakHistory'])->middleware('permission:data_discovery,write');
        Route::get('/{id}/search-ai-history', [DataDiscoveryController::class, 'getSearchAiHistory'])->middleware('permission:data_discovery,read');
        Route::delete('/{id}/search-ai-history', [DataDiscoveryController::class, 'clearSearchAiHistory'])->middleware('permission:data_discovery,write');
        Route::delete('/{id}/search-ai-history/{historyId}', [DataDiscoveryController::class, 'deleteSearchAiHistory'])->middleware('permission:data_discovery,write');

        // Protection Assessment (Manual + AI)
        Route::get('/{id}/protection-assessment', [DataDiscoveryController::class, 'getProtectionAssessment'])->middleware('permission:data_discovery,read');
        Route::put('/{id}/protection-assessment', [DataDiscoveryController::class, 'saveProtectionAssessment'])->middleware('permission:data_discovery,write');
        Route::post('/{id}/ai-protection-assessment', [DataDiscoveryController::class, 'aiProtectionAssessment'])->middleware('permission:data_discovery,write');

        // Sprint E1/E3/E4: Unstructured OCR + metadata compare + AI SQL sample
        Route::post('/scan-unstructured', [DataDiscoveryController::class, 'scanUnstructured'])->middleware('permission:data_discovery,write');
        Route::post('/{id}/compare-metadata', [DataDiscoveryController::class, 'compareMetadata'])->middleware('permission:data_discovery,read');
        Route::post('/{id}/sample-query', [DataDiscoveryController::class, 'sampleQuery'])->middleware('permission:data_discovery,read');

        // AI Patrol & Daily Changelogs
        Route::get('/{id}/changelogs', [DiscoveryChangelogController::class, 'index'])->middleware('permission:data_discovery,read');
        Route::post('/{id}/changelogs', [DiscoveryChangelogController::class, 'store'])->middleware('permission:data_discovery,write');
        Route::post('/{id}/patrol-config', [DiscoveryChangelogController::class, 'saveConfig'])->middleware('permission:data_discovery,write');
    });

    // Consent Logs & Items
    // =============================================
    // DSR Scope Picker — DPO assign Information Systems per DSR
    // =============================================
    Route::prefix('dsr/{id}')->where(['id' => '[0-9a-fA-F-]{36}'])->group(function () {
        // Verification helpers — DPO actions saat subject tidak bisa verify via email
        Route::post('/resend-verification', [DsrVerificationController::class, 'resend'])->middleware('permission:dsr,write');
        Route::post('/manual-verify', [DsrVerificationController::class, 'manualVerify'])->middleware('permission:dsr,write');

        // Scope picker
        Route::get('/scopes', [DsrRequestScopeController::class, 'index'])->middleware('permission:dsr,read');
        Route::get('/available-systems', [DsrRequestScopeController::class, 'availableSystems'])->middleware('permission:dsr,read');
        Route::post('/scopes', [DsrRequestScopeController::class, 'store'])->middleware('permission:dsr,write');
        Route::put('/scopes/{scopeId}', [DsrRequestScopeController::class, 'update'])->middleware('permission:dsr,write');
        Route::delete('/scopes/{scopeId}', [DsrRequestScopeController::class, 'destroy'])->middleware('permission:dsr,write');

        // SQL Pack — Privasimu generates, admin klien executes manually
        Route::post('/sql-pack/generate', [DsrSqlPackController::class, 'generate'])->middleware('permission:dsr,write');
        Route::get('/sql-pack/download', [DsrSqlPackController::class, 'download'])->middleware('permission:dsr,read')->name('dsr.sql_pack.download');
        Route::get('/sql-pack/info', [DsrSqlPackController::class, 'info'])->middleware('permission:dsr,read');

        // Execution log — admin klien upload bukti per shard, mark status
        Route::get('/executions', [DsrExecutionController::class, 'index'])->middleware('permission:dsr,read');
        Route::patch('/executions/{execId}', [DsrExecutionController::class, 'update'])
            ->where('execId', '[0-9a-fA-F-]{36}')->middleware('permission:dsr,write');
        Route::post('/executions/{execId}/evidence', [DsrExecutionController::class, 'uploadEvidence'])
            ->where('execId', '[0-9a-fA-F-]{36}')->middleware('permission:dsr,write');
        Route::get('/executions/{execId}/evidence', [DsrExecutionController::class, 'streamEvidence'])
            ->where('execId', '[0-9a-fA-F-]{36}')->middleware('permission:dsr,read');

        // Certificates — generated when allExecutionsComplete; manual regen allowed
        Route::post('/certificates/regenerate', [DsrExecutionController::class, 'regenerateCertificates'])->middleware('permission:dsr,write');
        Route::get('/certificates/{kind}/download', [DsrExecutionController::class, 'downloadCertificate'])
            ->where('kind', 'subject|internal')->middleware('permission:dsr,read');

        // Derived ROPAs (via scope.information_system.ropas)
        Route::get('/affected-ropas', [RopaLinkController::class, 'affectedRopasForDsr'])->middleware('permission:dsr,read');
    });

    // =============================================
    // DSR Apps — registered klien external apps (per-tenant CRUD)
    // =============================================
    Route::prefix('dsr-apps')->group(function () {
        // Helper endpoints (must come before /{id} to avoid pattern collision)
        Route::get('/assignable-users', [DsrAppController::class, 'assignableUsers'])->middleware('permission:dsr,read');
        Route::post('/upload-logo', [DsrAppController::class, 'uploadLogo'])->middleware('permission:dsr,write');

        Route::get('/', [DsrAppController::class, 'index'])->middleware('permission:dsr,read');
        Route::post('/', [DsrAppController::class, 'store'])->middleware('permission:dsr,write');
        Route::get('/{id}', [DsrAppController::class, 'show'])
            ->where('id', '[0-9a-fA-F-]{36}')->middleware('permission:dsr,read');
        Route::put('/{id}', [DsrAppController::class, 'update'])
            ->where('id', '[0-9a-fA-F-]{36}')->middleware('permission:dsr,write');
        Route::delete('/{id}', [DsrAppController::class, 'destroy'])
            ->where('id', '[0-9a-fA-F-]{36}')->middleware('permission:dsr,write');
        Route::post('/{id}/restore', [DsrAppController::class, 'restore'])
            ->where('id', '[0-9a-fA-F-]{36}')->middleware('permission:dsr,write');
        Route::post('/{id}/regenerate-token', [DsrAppController::class, 'regenerateToken'])
            ->where('id', '[0-9a-fA-F-]{36}')->middleware('permission:dsr,write');
        Route::post('/{id}/regenerate-api-keys', [DsrAppController::class, 'regenerateApiKeys'])
            ->where('id', '[0-9a-fA-F-]{36}')->middleware('permission:dsr,write');
        Route::get('/{id}/embed-snippet', [DsrAppController::class, 'embedSnippet'])
            ->where('id', '[0-9a-fA-F-]{36}')->middleware('permission:dsr,read');
        Route::post('/{id}/upload-logo', [DsrAppController::class, 'uploadLogo'])
            ->where('id', '[0-9a-fA-F-]{36}')->middleware('permission:dsr,write');
    });

    Route::get('/consent-logs', [ConsentLogController::class, 'index'])->middleware('permission:consent,read');

    // Phase B — Cookie Logs admin (tenant-scoped, separate from consent_logs)
    Route::get('/cookie-logs', [\App\Http\Controllers\Api\Admin\CookieLogAdminController::class, 'index'])->middleware('permission:consent,read');
    Route::get('/cookie-logs/stats', [\App\Http\Controllers\Api\Admin\CookieLogAdminController::class, 'stats'])->middleware('permission:consent,read');
    Route::get('/cookie-logs/{id}', [\App\Http\Controllers\Api\Admin\CookieLogAdminController::class, 'show'])->middleware('permission:consent,read')->where('id', '[0-9a-fA-F-]{36}');
    Route::delete('/cookie-logs/{id}', [\App\Http\Controllers\Api\Admin\CookieLogAdminController::class, 'destroy'])->middleware('permission:consent,write')->where('id', '[0-9a-fA-F-]{36}');

    // Phase B — Consent Extract (CRM extractor wizard backbone)
    Route::post('/consent-extract/preview', [\App\Http\Controllers\Api\Admin\ConsentExtractController::class, 'preview'])->middleware('permission:consent,read');
    Route::post('/consent-extract/run', [\App\Http\Controllers\Api\Admin\ConsentExtractController::class, 'run'])->middleware('permission:consent,write');
    Route::get('/consent-extract/runs', [\App\Http\Controllers\Api\Admin\ConsentExtractController::class, 'index'])->middleware('permission:consent,read');

    // Phase F — CRM credentials (per-org, encrypted secrets)
    Route::get('/crm-credentials', [\App\Http\Controllers\Api\Admin\CrmCredentialController::class, 'index'])->middleware('permission:consent,read');
    Route::post('/crm-credentials', [\App\Http\Controllers\Api\Admin\CrmCredentialController::class, 'store'])->middleware('permission:consent,write');
    Route::put('/crm-credentials/{id}', [\App\Http\Controllers\Api\Admin\CrmCredentialController::class, 'update'])->middleware('permission:consent,write')->where('id', '[0-9a-fA-F-]{36}');
    Route::delete('/crm-credentials/{id}', [\App\Http\Controllers\Api\Admin\CrmCredentialController::class, 'destroy'])->middleware('permission:consent,write')->where('id', '[0-9a-fA-F-]{36}');
    Route::post('/crm-credentials/{id}/probe', [\App\Http\Controllers\Api\Admin\CrmCredentialController::class, 'probe'])->middleware('permission:consent,write')->where('id', '[0-9a-fA-F-]{36}');
    Route::post('/consent-items', [ConsentItemController::class, 'store'])->middleware('permission:consent,write');
    Route::put('/consent-items/{id}', [ConsentItemController::class, 'update'])->middleware('permission:consent,write');
    Route::delete('/consent-items/{id}', [ConsentItemController::class, 'destroy'])->middleware('permission:consent,write');
    Route::post('/consent/{id}/webhook', [ConsentLogController::class, 'saveWebhook'])->middleware('permission:consent,write');

    // Consent Collection Point — app-level helpers (mirror DSR)
    Route::prefix('consent-collections')->group(function () {
        Route::post('/upload-logo', [ConsentCollectionController::class, 'uploadLogo'])->middleware('permission:consent,write');
        Route::post('/{id}/regenerate-api-keys', [ConsentCollectionController::class, 'regenerateApiKeys'])
            ->where('id', '[0-9a-fA-F-]{36}')->middleware('permission:consent,write');
        Route::post('/{id}/regenerate-embed-token', [ConsentCollectionController::class, 'regenerateEmbedToken'])
            ->where('id', '[0-9a-fA-F-]{36}')->middleware('permission:consent,write');
        Route::get('/{id}/embed-snippet', [ConsentCollectionController::class, 'embedSnippet'])
            ->where('id', '[0-9a-fA-F-]{36}')->middleware('permission:consent,read');
        Route::post('/{id}/upload-logo', [ConsentCollectionController::class, 'uploadLogo'])
            ->where('id', '[0-9a-fA-F-]{36}')->middleware('permission:consent,write');
        Route::get('/{id}/ropas', [RopaLinkController::class, 'indexForConsent'])
            ->where('id', '[0-9a-fA-F-]{36}')->middleware('permission:consent,read');
        Route::put('/{id}/ropas', [RopaLinkController::class, 'syncForConsent'])
            ->where('id', '[0-9a-fA-F-]{36}')->middleware('permission:consent,write');
    });

    // Organization Profile (Onboarding)
    Route::get('/organizations', [OrganizationController::class, 'index']); // Super Admin: list all
    Route::get('/organization', [OrganizationController::class, 'show']);
    Route::put('/organization', [OrganizationController::class, 'update']);
    Route::post('/organizations/create-child', [OrganizationController::class, 'createChild']);
    Route::put('/organizations/{id}/hierarchy', [OrganizationController::class, 'updateHierarchy']);
    Route::post('/organizations/{id}/deactivate', [OrganizationController::class, 'deactivate']);
    Route::post('/organizations/{id}/restore', [OrganizationController::class, 'restore']);
    Route::put('/organizations/{id}/notifications-toggle', [OrganizationController::class, 'toggleNotifications']);

    // CRM Integration
    Route::prefix('crm')->group(function () {
        Route::get('/config', [OrganizationController::class, 'getCrmConfig']);
        Route::put('/config', [OrganizationController::class, 'saveCrmConfig']);
        Route::post('/test-connection', [OrganizationController::class, 'testCrmConnection']);
        Route::post('/sync', [OrganizationController::class, 'syncCrmData']);
        Route::delete('/disconnect', [OrganizationController::class, 'disconnectCrm']);
    });

    // Templates
    Route::get('/templates/dpia', [DashboardController::class, 'downloadDpiaTemplate']);
    Route::get('/regulations', [GapAssessmentController::class, 'getRegulations']);

    // AI Provider Management (Multi-Provider LLM)
    Route::prefix('ai-providers')->group(function () {
        Route::get('/', [AiProviderController::class, 'index']);
        Route::get('/config', [AiProviderController::class, 'getConfig']);
        Route::post('/api-key', [AiProviderController::class, 'saveApiKey']);
        Route::post('/test', [AiProviderController::class, 'testConnection']);
        Route::post('/set-active', [AiProviderController::class, 'setActiveModel']);
        Route::post('/unset-active', [AiProviderController::class, 'unsetActiveModel']);
        Route::delete('/api-key', [AiProviderController::class, 'removeApiKey']);
        // Admin CRUD
        Route::get('/admin', [AiProviderController::class, 'adminIndex']);
        Route::post('/', [AiProviderController::class, 'storeProvider']);
        Route::put('/{id}', [AiProviderController::class, 'updateProvider']);
        Route::delete('/{id}', [AiProviderController::class, 'destroyProvider']);
        // Trash & Restore (Providers)
        Route::get('/trash', [AiProviderController::class, 'trashedProviders']);
        Route::post('/{id}/restore', [AiProviderController::class, 'restoreProvider']);
        Route::delete('/{id}/force', [AiProviderController::class, 'forceDeleteProvider']);
        // Models CRUD
        Route::get('/{providerId}/models', [AiProviderController::class, 'listModels']);
        Route::post('/{providerId}/models', [AiProviderController::class, 'storeModel']);
        Route::put('/models/{modelId}', [AiProviderController::class, 'updateModel']);
        Route::delete('/models/{modelId}', [AiProviderController::class, 'destroyModel']);
        // Trash & Restore (Models)
        Route::get('/{providerId}/models/trash', [AiProviderController::class, 'trashedModels']);
        Route::post('/models/{modelId}/restore', [AiProviderController::class, 'restoreModel']);
        Route::delete('/models/{modelId}/force', [AiProviderController::class, 'forceDeleteModel']);
    });

    // Workflow Approvals
    Route::prefix('approvals')->group(function () {
        Route::get('/pending', [ApprovalController::class, 'pending']);
        Route::post('/{id}/approve', [ApprovalController::class, 'approve']);
        Route::post('/{id}/reject', [ApprovalController::class, 'reject']);
    });

    // Security Posture & Alerts (DSPM) Phase 4
    Route::prefix('security')->group(function () {
        Route::get('/posture', [PostureController::class, 'getPosture']);
        Route::get('/posture/trend', [PostureController::class, 'getTrend']);

        // Alert Engine / Notifications
        Route::get('/alerts', [AlertController::class, 'index']);
        Route::get('/alerts/count', [AlertController::class, 'count']);
        Route::get('/alerts/export', [AlertController::class, 'export']);
        Route::post('/alerts/scan', [AlertController::class, 'scan']);
        Route::post('/alerts/mark-all-read', [AlertController::class, 'markAllRead']);
        Route::post('/alerts/{id}/read', [AlertController::class, 'markRead']);
        Route::post('/alerts/{id}/acknowledge', [AlertController::class, 'acknowledge']);
        Route::post('/alerts/{id}/resolve', [AlertController::class, 'resolve']);
        Route::post('/alerts/{id}/dismiss', [AlertController::class, 'dismiss']);
        // Alias: /notifications → /alerts (semantic preference)
        Route::get('/notifications', [AlertController::class, 'index']);
        Route::get('/notifications/count', [AlertController::class, 'count']);
        Route::get('/notifications/export', [AlertController::class, 'export']);
        Route::post('/notifications/mark-all-read', [AlertController::class, 'markAllRead']);
        Route::post('/notifications/{id}/read', [AlertController::class, 'markRead']);
    });

    // Notification Preferences (per-user toggles)
    Route::prefix('notification-preferences')->group(function () {
        Route::get('/', [NotificationPreferenceController::class, 'index']);
        Route::put('/', [NotificationPreferenceController::class, 'update']);
        Route::post('/reset', [NotificationPreferenceController::class, 'reset']);
    });

    // Automation Rules (Phase 4)
    Route::prefix('automation')->group(function () {
        Route::get('/rules', [AutomationController::class, 'index']);
        Route::put('/rules/{ruleType}', [AutomationController::class, 'update']);
    });

    // =============================================
    // License Management
    // =============================================
    Route::prefix('licenses')->group(function () {
        Route::get('/', [LicenseController::class, 'index']);
        Route::post('/', [LicenseController::class, 'store']);
        Route::get('/verify', [LicenseController::class, 'verify']);
        Route::post('/activate', [LicenseController::class, 'activate']);
        Route::get('/pricing', [LicenseController::class, 'pricingIndex']);
        Route::put('/pricing', [LicenseController::class, 'pricingUpdate']);
        Route::get('/{id}', [LicenseController::class, 'show']);
        Route::put('/{id}', [LicenseController::class, 'update']);
        Route::delete('/{id}', [LicenseController::class, 'destroy']);
        Route::post('/{id}/restore', [LicenseController::class, 'restore']);
        Route::post('/{id}/revoke', [LicenseController::class, 'revoke']);
    });

    // =============================================
    // AI Chat Assistant (Knowledge Base)
    // =============================================
    Route::post('/ai/chat', [AiChatController::class, 'chat']);
    Route::match(['get', 'put'], '/ai/knowledge-base', [AiChatController::class, 'knowledgeBase']);
    Route::match(['get', 'put'], '/ai/settings', [AiChatController::class, 'apiSettings']);
    Route::post('/ai/test-connection', [AiChatController::class, 'testConnection']);

    // Chat History & Admin CS
    Route::get('/ai/conversations', [AiChatController::class, 'conversations']);
    Route::get('/ai/conversations/{id}', [AiChatController::class, 'conversationMessages']);
    Route::post('/ai/conversations/{id}/reply', [AiChatController::class, 'adminReply']);
    Route::get('/ai/conversations/{id}/poll', [AiChatController::class, 'pollMessages']);

    // =============================================
    // AI Features (License-Gated)
    // =============================================
    Route::prefix('ai-features')->group(function () {
        Route::post('/gap_comparison/{id}/generate', [AiFeatureController::class, 'gapComparisonGenerate']);
        Route::post('/gap/{id}/remediation', [AiFeatureController::class, 'gapRemediation']);
        Route::post('/ropa/{id}/analysis', [AiFeatureController::class, 'ropaAnalysis']);
        Route::post('/dpia/{id}/risk-scoring', [AiFeatureController::class, 'dpiaRiskScoring']);
        Route::post('/breach/{id}/advisor', [AiFeatureController::class, 'breachAdvisor']);
        Route::post('/dsr/{id}/draft', [AiFeatureController::class, 'dsrDraft']);
        Route::post('/consent/generate', [AiFeatureController::class, 'consentGenerator']);
        Route::get('/dashboard/summary', [AiFeatureController::class, 'dashboardSummary']);
        Route::post('/drill/scenario', [AiFeatureController::class, 'drillScenario']);
        Route::get('/history/{featureType}/{recordId}', [AiFeatureController::class, 'history']);
        Route::post('/contract/review', [AiFeatureController::class, 'contractReview']);
        Route::post('/contract/upload', [AiFeatureController::class, 'contractUpload']);
        Route::post('/contract/analyze', [AiFeatureController::class, 'contractAnalyze']);
        Route::post('/policy/analyze', [AiFeatureController::class, 'policyAnalyze']);
        Route::post('/policy/review', [AiFeatureController::class, 'policyReview']);
        Route::post('/consent/{id}/audit', [AiFeatureController::class, 'consentAudit']);
        Route::post('/simulation/{id}/analysis', [AiFeatureController::class, 'simulationAnalysis']);
        Route::post('/drill/scenario', [AiFeatureController::class, 'drillScenarioGenerator']);
        Route::post('/data-discovery/{id}/classification', [AiFeatureController::class, 'dataDiscoveryClassification']);

        // Auto-Fill endpoints
        Route::post('/autofill/ropa', [AiFeatureController::class, 'autofillRopa']);
        Route::post('/autofill/dpia', [AiFeatureController::class, 'autofillDpia']);
        Route::post('/generate-raci', [AiFeatureController::class, 'generateRaci']);
        Route::post('/batch-review', [AiFeatureController::class, 'batchReview']);
        Route::get('/batch-review/{batchId}', [AiFeatureController::class, 'batchReviewStatus']);
        Route::post('/breach/containment-steps', [AiFeatureController::class, 'breachContainmentSteps']);
        Route::post('/assessment/{kind}/analysis', [AiFeatureController::class, 'assessmentAnalysis'])->where('kind', 'lia|tia|maturity');
        Route::post('/autofill/breach', [AiFeatureController::class, 'autofillBreach']);
        Route::post('/autofill/dsr', [AiFeatureController::class, 'autofillDsr']);
        Route::post('/autofill/consent-items/{id}', [AiFeatureController::class, 'autofillConsentItems']);
    });

    // AI Credit Management
    Route::get('/ai-credits/usage', [AiFeatureController::class, 'creditUsage']);
    Route::post('/ai-credits/topup', [AiFeatureController::class, 'creditTopup']);

    // =============================================
    // Sprint C1: Custom Fields & Templates (ROPA / DPIA)
    // =============================================
    // =============================================
    // Sprint F1/F2/F3: LIA / TIA / Maturity Assessment
    // =============================================
    Route::prefix('assessments/{kind}')->where(['kind' => 'lia|tia|maturity'])->group(function () {
        Route::get('/', [AssessmentsController::class, 'index']);
        Route::post('/', [AssessmentsController::class, 'store']);
        Route::get('/{id}', [AssessmentsController::class, 'show']);
        Route::put('/{id}', [AssessmentsController::class, 'update']);
        Route::delete('/{id}', [AssessmentsController::class, 'destroy']);
        Route::post('/{id}/restore', [AssessmentsController::class, 'restore']);
        Route::delete('/{id}/force', [AssessmentsController::class, 'forceDelete']);
    });

    // =============================================
    // Knowledge Base (per-tenant + shared)
    // =============================================
    Route::prefix('knowledge-base')->group(function () {
        Route::get('/', [KnowledgeBaseController::class, 'index']);
        Route::post('/', [KnowledgeBaseController::class, 'store']);
        Route::put('/{id}', [KnowledgeBaseController::class, 'update']);
        Route::delete('/{id}', [KnowledgeBaseController::class, 'destroy']);
    });

    // =============================================
    // Menu Registry — 3-layer visibility (root + admin)
    // =============================================
    Route::prefix('menu-registry')->group(function () {
        Route::get('/', [MenuRegistryController::class, 'me']);
        // Root-only
        Route::get('/all', [MenuRegistryController::class, 'allMenus']);
        Route::get('/whitelist', [MenuRegistryController::class, 'whitelist']);
        Route::put('/whitelist', [MenuRegistryController::class, 'updateWhitelist']);
        Route::get('/orgs', [MenuRegistryController::class, 'orgs']);
        Route::get('/entitlements', [MenuRegistryController::class, 'entitlements']);
        Route::put('/entitlements', [MenuRegistryController::class, 'updateEntitlement']);
        Route::post('/bulk-entitlement', [MenuRegistryController::class, 'bulkEntitlement']);
        Route::post('/copy-entitlement', [MenuRegistryController::class, 'copyEntitlement']);
        Route::get('/audit-log', [MenuRegistryController::class, 'auditLog']);
        // Admin + Root
        Route::get('/tenant-overrides', [MenuRegistryController::class, 'tenantOverrides']);
        Route::put('/tenant-overrides', [MenuRegistryController::class, 'updateTenantOverride']);
    });

    // =============================================
    // Tenant Lifecycle / Offboarding (root + superadmin)
    // =============================================
    Route::prefix('tenant-offboard')->group(function () {
        Route::get('/{id}/status', [TenantOffboardController::class, 'status']);
        Route::post('/{id}/freeze', [TenantOffboardController::class, 'freeze']);
        Route::post('/{id}/unfreeze', [TenantOffboardController::class, 'unfreeze']);
        Route::post('/{id}/transfer', [TenantOffboardController::class, 'transfer']);
        Route::post('/{id}/archive', [TenantOffboardController::class, 'archive']);
        Route::post('/{id}/export', [TenantExportController::class, 'export']);
    });

    // =============================================
    // Root Dashboard (platform-level aggregates)
    // =============================================
    Route::get('/root-dashboard', [RootDashboardController::class, 'index']);

    // =============================================
    // Tenant Branding / Theme (per-org isolation)
    // =============================================
    Route::prefix('themes')->group(function () {
        Route::get('/', [TenantThemeController::class, 'index']);
        Route::get('/active', [TenantThemeController::class, 'active']);
        Route::post('/use-default', [TenantThemeController::class, 'useDefault']);
        Route::post('/generate', [TenantThemeController::class, 'generate']);
        Route::post('/import', [TenantThemeController::class, 'import']);
        Route::get('/{id}/export', [TenantThemeController::class, 'export']);
        Route::post('/', [TenantThemeController::class, 'store']);
        Route::post('/upload-asset', [TenantThemeController::class, 'uploadAsset']);
        Route::get('/{id}', [TenantThemeController::class, 'show']);
        Route::put('/{id}', [TenantThemeController::class, 'update']);
        Route::delete('/{id}', [TenantThemeController::class, 'destroy']);
        Route::post('/{id}/activate', [TenantThemeController::class, 'setActive']);
        Route::post('/{id}/deactivate', [TenantThemeController::class, 'deactivate']);
    });

    // =============================================
    // Sprint C4: Module Comments (threaded, cross-module)
    // =============================================
    Route::prefix('comments')->group(function () {
        Route::get('/', [ModuleCommentController::class, 'index']);
        Route::post('/', [ModuleCommentController::class, 'store']);
        Route::put('/{id}', [ModuleCommentController::class, 'update']);
        Route::delete('/{id}', [ModuleCommentController::class, 'destroy']);
    });

    Route::prefix('custom-fields')->group(function () {
        Route::get('/', [CustomFieldController::class, 'index']);
        Route::post('/', [CustomFieldController::class, 'store']);
        Route::put('/{id}', [CustomFieldController::class, 'update']);
        Route::delete('/{id}', [CustomFieldController::class, 'destroy']);
    });
    Route::prefix('module-templates')->group(function () {
        Route::get('/', [CustomFieldController::class, 'templates']);
        Route::post('/', [CustomFieldController::class, 'storeTemplate']);
        Route::put('/{id}', [CustomFieldController::class, 'updateTemplate']);
        Route::delete('/{id}', [CustomFieldController::class, 'destroyTemplate']);
    });

    // =============================================
    // Template Export (Word/Excel — Formatted Documents)
    // =============================================
    Route::prefix('export-doc')->group(function () {
        Route::get('/ropa/{id}', [TemplateExportController::class, 'exportRopa']);
        Route::get('/dpia/{id}', [TemplateExportController::class, 'exportDpia']);
        Route::get('/gap/{id}', [TemplateExportController::class, 'exportGap']);
        Route::get('/gap/{id}/report', [TemplateExportController::class, 'exportGapReport']);
        Route::get('/compliance-report', [TemplateExportController::class, 'exportComplianceReport']);
    });

    // =============================================
    // Export (CSV / JSON)
    // =============================================
    Route::prefix('export')->group(function () {
        Route::get('/ropa', [ExportController::class, 'ropa']);
        Route::get('/dpia', [ExportController::class, 'dpia']);
        Route::get('/breach', [ExportController::class, 'breach']);
        Route::get('/dsr', [ExportController::class, 'dsr']);
        Route::get('/consent', [ExportController::class, 'consent']);
        Route::get('/consent-records', [ExportController::class, 'consentRecords']);
        Route::get('/gap-assessment', [ExportController::class, 'gapAssessment']);
        Route::get('/data-discovery', [ExportController::class, 'dataDiscovery']);
        Route::get('/data-discovery-columns', [ExportController::class, 'dataDiscoveryColumns']);
        Route::get('/simulation', [ExportController::class, 'simulation']);
        Route::get('/ai-results', [ExportController::class, 'aiResults']);
        Route::get('/ai-results/{id}', [ExportController::class, 'aiResultSingle']);
        Route::get('/compliance-report', [ExportController::class, 'complianceReport']);
    });

    // =============================================
    // AI Agent (Enterprise only — function calling)
    // =============================================
    Route::prefix('ai-agent')->group(function () {
        Route::post('/chat', [AiAgentController::class, 'chat']);
        Route::post('/approve-action', [AiAgentController::class, 'approveAction']);
        Route::post('/reject-action', [AiAgentController::class, 'rejectAction']);
        Route::get('/mentions/{type}', [AiAgentController::class, 'mentions']);
        Route::get('/history', [AiAgentController::class, 'history']);
        Route::get('/history/{id}/messages', [AiAgentController::class, 'conversationMessages']);
    });

    // =============================================
    // Avatar 3D Chat (Platform Q&A with Knowledge Base)
    // =============================================
    Route::post('/avatar/chat', [AvatarChatController::class, 'chat']);

    // =============================================
    // Voice TTS Synthesis (AI-powered text-to-speech)
    // =============================================
    Route::post('/voice/synthesize', [VoiceTtsController::class, 'synthesize']);

    // =============================================
    // System / Superadmin Tools
    // =============================================
    // Platform Config (root only) — editable soft knobs + AWS budget estimator
    Route::get('/platform-config', [PlatformConfigController::class, 'index']);
    Route::put('/platform-config', [PlatformConfigController::class, 'update']);
    Route::get('/platform-config/budget', [PlatformConfigController::class, 'budget']);

    Route::get('/system/check-update', [SystemUpdateController::class, 'checkUpdate']);
    Route::post('/system/update-backend', [SystemUpdateController::class, 'updateBackend']);
    Route::post('/system/checkout-version', [SystemUpdateController::class, 'checkoutVersion']);
    Route::get('/system/frontend-status', [SystemUpdateController::class, 'frontendStatus']);
    Route::post('/system/update-frontend', [SystemUpdateController::class, 'updateFrontend']);

    // =============================================
    // API Keys (Developer/Tenant Integration)
    // =============================================
    Route::prefix('api-keys')->group(function () {
        Route::get('/', [ApiKeyController::class, 'index']);
        Route::post('/', [ApiKeyController::class, 'store']);
        Route::delete('/{id}', [ApiKeyController::class, 'destroy']);
    });

    // =============================================
    // Per-Tenant Cloud Storage Settings
    // =============================================
    Route::prefix('storage-settings')->group(function () {
        Route::get('/', [StorageSettingsController::class, 'show']);
        Route::put('/', [StorageSettingsController::class, 'update']);
        Route::post('/test', [StorageSettingsController::class, 'testConnection']);
        Route::delete('/', [StorageSettingsController::class, 'destroy']);
    });

    // =============================================
    // Platform-wide Default Storage (root + superadmin only)
    // Acts as the fallback for tenants that have not set their own storage.
    // Resolution order: tenant override → platform default → Laravel default.
    // =============================================
    Route::middleware('role.root')->prefix('platform-storage')->group(function () {
        Route::get('/', [PlatformStorageSettingsController::class, 'show']);
        Route::put('/', [PlatformStorageSettingsController::class, 'update']);
        Route::post('/test', [PlatformStorageSettingsController::class, 'testConnection']);
        Route::delete('/', [PlatformStorageSettingsController::class, 'destroy']);
    });

    // =============================================
    // BYODB Pool Registry (root + superadmin only)
    // Manage Postgres/MySQL clusters + S3/MinIO/GCS backends that
    // tenants can be assigned to. See BYODB.md §2.5.
    // =============================================
    Route::middleware('role.root')->prefix('platform-admin')->group(function () {
        // Database pools
        Route::get('/database-pools', [DatabasePoolController::class, 'index']);
        Route::post('/database-pools', [DatabasePoolController::class, 'store']);
        Route::post('/database-pools/test', [DatabasePoolController::class, 'testConnection']);
        Route::get('/database-pools/{id}', [DatabasePoolController::class, 'show']);
        Route::put('/database-pools/{id}', [DatabasePoolController::class, 'update']);
        Route::delete('/database-pools/{id}', [DatabasePoolController::class, 'destroy']);

        // Storage pools
        Route::get('/storage-pools', [StoragePoolController::class, 'index']);
        Route::post('/storage-pools', [StoragePoolController::class, 'store']);
        Route::post('/storage-pools/test', [StoragePoolController::class, 'testConnection']);
        Route::get('/storage-pools/{id}', [StoragePoolController::class, 'show']);
        Route::put('/storage-pools/{id}', [StoragePoolController::class, 'update']);
        Route::delete('/storage-pools/{id}', [StoragePoolController::class, 'destroy']);
        Route::post('/storage-pools/{id}/set-default', [StoragePoolController::class, 'setDefault']);
    });

    // =============================================
    // Document Intelligence (Import & AI Mapping)
    // =============================================
    Route::prefix('documents')->group(function () {
        Route::post('/upload', [DocumentImportController::class, 'upload']);
        Route::post('/batch-upload', [DocumentImportController::class, 'batchUpload']);
        Route::get('/imports', [DocumentImportController::class, 'index']);
        Route::get('/imports/{id}', [DocumentImportController::class, 'show']);
        Route::put('/imports/{id}/approve', [DocumentImportController::class, 'approve']);
        Route::put('/imports/{id}/edit-mapping', [DocumentImportController::class, 'editMapping']);
        Route::delete('/imports/{id}', [DocumentImportController::class, 'destroy']);
        Route::get('/batches', [DocumentImportController::class, 'batches']);
        Route::get('/batches/{id}', [DocumentImportController::class, 'batchDetail']);
    });

    // =============================================
    // API Hub (Key Management, Webhooks, Usage)
    // =============================================
    Route::prefix('api-hub')->group(function () {
        Route::get('/docs', [ApiHubController::class, 'docs']);
        // API Keys
        Route::get('/keys', [ApiHubController::class, 'listKeys']);
        Route::post('/keys', [ApiHubController::class, 'createKey']);
        Route::put('/keys/{id}/toggle', [ApiHubController::class, 'toggleKey']);
        Route::delete('/keys/{id}', [ApiHubController::class, 'deleteKey']);
        // Usage
        Route::get('/usage', [ApiHubController::class, 'usage']);
        // Webhooks
        Route::get('/webhooks', [ApiHubController::class, 'listWebhooks']);
        Route::post('/webhooks', [ApiHubController::class, 'createWebhook']);
        Route::put('/webhooks/{id}/toggle', [ApiHubController::class, 'toggleWebhook']);
        Route::delete('/webhooks/{id}', [ApiHubController::class, 'deleteWebhook']);
    });

    // =============================================
    // Integrations (Telegram, SIEM, SOAR, SOCRadar)
    // =============================================
    Route::prefix('integrations')->group(function () {
        Route::get('/', [IntegrationController::class, 'index']);
        Route::put('/{provider}', [IntegrationController::class, 'update']);
        Route::post('/{provider}/test', [IntegrationController::class, 'test']);
        Route::delete('/{provider}', [IntegrationController::class, 'destroy']);
        // Breach sync shortcuts
        Route::post('/breach/{id}/telegram', [IntegrationController::class, 'syncBreachTelegram']);
        Route::post('/breach/{id}/siem', [IntegrationController::class, 'syncBreachSiem']);
    });

});

// =============================================
// Public Partner API v1 (authenticated via X-Api-Key)
// =============================================
Route::prefix('v1')->middleware(AuthenticatePartnerApi::class)->group(function () {
    // Breach Management
    Route::get('/breach/stats', [BreachApiController::class, 'stats']);
    Route::get('/breach', [BreachApiController::class, 'index']);
    Route::get('/breach/{id}', [BreachApiController::class, 'show']);
    Route::post('/breach', [BreachApiController::class, 'store']);
    Route::put('/breach/{id}', [BreachApiController::class, 'update']);
});

// =============================================
// DSR Partner API v1 (per-app HMAC: client_key + server_key)
// Different middleware than Breach (which uses general PartnerApiKey).
// Each DSR app has its own client/server key pair, separate from tenant API keys.
// =============================================
Route::prefix('v1/dsr')->middleware('dsr.api_key')->withoutMiddleware([HandleCors::class])->group(function () {
    Route::post('/submit', [DsrApiV1Controller::class, 'submit']);
    Route::post('/submit-preverified', [DsrApiV1Controller::class, 'submitPreverified']);
    Route::get('/{request_id}/status', [DsrApiV1Controller::class, 'status'])
        ->where('request_id', 'DSR-[0-9]{4}-[0-9]+');
});

// =============================================
// Consent Partner API v1 (per-collection HMAC: client_key + server_key)
// =============================================
Route::prefix('v1/consent')->middleware('consent.api_key')->group(function () {
    Route::post('/capture', [ConsentApiV1Controller::class, 'capture']);
    Route::get('/state', [ConsentApiV1Controller::class, 'state']);
    Route::get('/items', [ConsentApiV1Controller::class, 'items']);
});

// =============================================
// Landing Page Admin — gated `role.root` (root + superadmin only)
// Privasimu's own marketing site management. NOT per-tenant.
// =============================================
Route::middleware(['auth:sanctum', 'role.root', 'tenant.context'])->prefix('admin/landing')->group(function () {
    $c = LandingAdminController::class;

    // Settings (singleton)
    Route::get('/settings', [$c, 'getSettings']);
    Route::put('/settings', [$c, 'updateSettings']);

    // Generic upload — returns relative path
    Route::post('/upload', [$c, 'upload']);

    // Reorder batch
    Route::post('/{resource}/reorder', [$c, 'reorder'])
        ->where('resource', 'features|team|testimonials|logos|products|stats');

    // Features
    Route::get('/features', [$c, 'indexFeatures']);
    Route::post('/features', [$c, 'storeFeature']);
    Route::put('/features/{id}', [$c, 'updateFeature']);
    Route::delete('/features/{id}', [$c, 'destroyFeature']);

    // Team
    Route::get('/team', [$c, 'indexTeam']);
    Route::post('/team', [$c, 'storeTeam']);
    Route::put('/team/{id}', [$c, 'updateTeam']);
    Route::delete('/team/{id}', [$c, 'destroyTeam']);

    // Testimonials
    Route::get('/testimonials', [$c, 'indexTestimonials']);
    Route::post('/testimonials', [$c, 'storeTestimonial']);
    Route::put('/testimonials/{id}', [$c, 'updateTestimonial']);
    Route::delete('/testimonials/{id}', [$c, 'destroyTestimonial']);

    // Logos (partner / customer / integration)
    Route::get('/logos', [$c, 'indexLogos']);
    Route::post('/logos', [$c, 'storeLogo']);
    Route::put('/logos/{id}', [$c, 'updateLogo']);
    Route::delete('/logos/{id}', [$c, 'destroyLogo']);

    // Products
    Route::get('/products', [$c, 'indexProducts']);
    Route::post('/products', [$c, 'storeProduct']);
    Route::put('/products/{id}', [$c, 'updateProduct']);
    Route::delete('/products/{id}', [$c, 'destroyProduct']);

    // Stats
    Route::get('/stats', [$c, 'indexStats']);
    Route::post('/stats', [$c, 'storeStat']);
    Route::put('/stats/{id}', [$c, 'updateStat']);
    Route::delete('/stats/{id}', [$c, 'destroyStat']);

    // Leads inbox (Contact + Demo submissions)
    Route::get('/leads', [$c, 'indexLeads']);
    Route::get('/leads/{id}', [$c, 'showLead']);
    Route::put('/leads/{id}', [$c, 'updateLead']);
    Route::delete('/leads/{id}', [$c, 'destroyLead']);
});
