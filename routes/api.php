<?php

use App\Http\Controllers\Api\Admin\ConsentExtractController;
use App\Http\Controllers\Api\Admin\CookieLogAdminController;
use App\Http\Controllers\Api\Admin\CrmCredentialController;
use App\Http\Controllers\Api\Admin\EmbeddingStatsController;
use App\Http\Controllers\Api\Admin\LandingAdminController;
use App\Http\Controllers\Api\AiAgentController;
use App\Http\Controllers\Api\AiChatController;
use App\Http\Controllers\Api\AiFeatureController;
use App\Http\Controllers\Api\AiJobController;
use App\Http\Controllers\Api\AiProviderController;
use App\Http\Controllers\Api\AlertController;
use App\Http\Controllers\Api\ApiHubController;
use App\Http\Controllers\Api\ApiKeyController;
use App\Http\Controllers\Api\ApprovalConfigController;
use App\Http\Controllers\Api\ApprovalController;
use App\Http\Controllers\Api\AsesmenPublikController;
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
use App\Http\Controllers\Api\CustomSectionController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DatabasePoolController;
use App\Http\Controllers\Api\DataDiscoveryController;
use App\Http\Controllers\Api\DataDiscoveryScanController;
use App\Http\Controllers\Api\DecryptorProfileController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\DiscoveryChangelogController;
use App\Http\Controllers\Api\DocumentImportController;
use App\Http\Controllers\Api\DocumentMakerController;
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
use App\Http\Controllers\Api\LiaController;
use App\Http\Controllers\Api\LicenseController;
use App\Http\Controllers\Api\LogAnalyzerController;
use App\Http\Controllers\Api\MaintenanceController;
use App\Http\Controllers\Api\MaturityController;
use App\Http\Controllers\Api\MenuRegistryController;
use App\Http\Controllers\Api\ModuleCommentController;
use App\Http\Controllers\Api\ModuleCrudController;
use App\Http\Controllers\Api\NotificationPreferenceController;
use App\Http\Controllers\Api\OrganizationAppController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\PlatformConfigController;
use App\Http\Controllers\Api\PlatformStorageSettingsController;
use App\Http\Controllers\Api\PolicyReviewCrudController;
use App\Http\Controllers\Api\PositionController;
use App\Http\Controllers\Api\PostureController;
use App\Http\Controllers\Api\PostureFindingController;
use App\Http\Controllers\Api\ProcessingCategoryController;
use App\Http\Controllers\Api\PublicLandingController;
use App\Http\Controllers\Api\RaciTemplateController;
use App\Http\Controllers\Api\RetentionPolicyController;
use App\Http\Controllers\Api\RiskTreatmentPlanController;
use App\Http\Controllers\Api\Root\QaCenterController;
use App\Http\Controllers\Api\RootDashboardController;
use App\Http\Controllers\Api\SuperadminDashboardController;
use App\Http\Controllers\Api\RopaApprovalController;
use App\Http\Controllers\Api\RopaLinkController;
use App\Http\Controllers\Api\RopaTemplateController;
use App\Http\Controllers\Api\SimulationController;
use App\Http\Controllers\Api\SsoLoginController;
use App\Http\Controllers\Api\StoragePoolController;
use App\Http\Controllers\Api\StorageSettingsController;
use App\Http\Controllers\Api\SystemSettingsController;
use App\Http\Controllers\Api\SystemUpdateController;
use App\Http\Controllers\Api\TemplateExportController;
use App\Http\Controllers\Api\TenantChangeRequestController;
use App\Http\Controllers\Api\TenantExportController;
use App\Http\Controllers\Api\TenantIsolationController;
use App\Http\Controllers\Api\TenantOffboardController;
use App\Http\Controllers\Api\TenantRoleController;
use App\Http\Controllers\Api\TenantSsoController;
use App\Http\Controllers\Api\TenantThemeController;
use App\Http\Controllers\Api\ThirdPartyQuestionController;
use App\Http\Controllers\Api\TprmApprovalController;
use App\Http\Controllers\Api\TprmIncidentController;
use App\Http\Controllers\Api\TprmLibraryController;
use App\Http\Controllers\Api\TprmMonitoringController;
use App\Http\Controllers\Api\TprmReviewController;
use App\Http\Controllers\Api\VendorScreeningController;
use App\Http\Controllers\Api\ThreatIntelController;
use App\Http\Controllers\Api\TiaController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\V1\BreachApiController;
use App\Http\Controllers\Api\V1\ConsentApiV1Controller;
use App\Http\Controllers\Api\V1\DsrApiV1Controller;
use App\Http\Controllers\Api\V2\CookieCaptureController;
use App\Http\Controllers\Api\VendorRiskController;
use App\Http\Controllers\Api\VoiceTtsController;
use App\Http\Controllers\Api\WizardSchemaController;
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

    // Public — active password policy for register/change-password forms.
    // Tidak return common-passwords list (server-side enforcement only).
    Route::get('/auth/password-policy', [AuthController::class, 'passwordPolicy']);

    // 2FA verify (second step setelah login dengan password OK).
    // Dipakai dengan challenge UUID dari respons /auth/login.
    Route::post('/auth/2fa/verify', [AuthController::class, 'verifyTwoFactor']);

    // Email verification — signed link dari email notification + resend.
    Route::get('/auth/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
        ->name('verification.verify')
        ->middleware('throttle:6,1');
    Route::post('/auth/email/resend', [AuthController::class, 'resendEmailVerification'])
        ->middleware('throttle:3,5'); // 3 per 5 menit per IP — anti spam

    // Password reset flow — public, throttled berat anti brute-force /
    // enumeration. Token expired 60 menit (config/auth.php).
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])
        ->middleware('throttle:5,15'); // 5 per 15 menit per IP — anti spam + enumeration
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])
        ->middleware('throttle:5,15'); // 5 per 15 menit per IP — anti token brute-force

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
    Route::post('/v2/cookies/capture', [CookieCaptureController::class, 'capture']);
    Route::get('/v2/cookies/state', [CookieCaptureController::class, 'state']);
    Route::post('/v2/cookies/withdraw', [CookieCaptureController::class, 'withdraw']);

    // =============================================
    // Public DSR endpoints (untuk embed widget di klien websites)
    // =============================================
    Route::get('/public/dsr/config/{embed_token}', [DsrPublicController::class, 'config']);
    Route::post('/public/dsr/submit/{embed_token}', [DsrPublicController::class, 'submit'])
        ->middleware('throttle:30,1');  // 30 req/min per IP
    Route::get('/public/dsr/verify/{token}', [DsrPublicController::class, 'verify']);

    // =============================================
    // Asesmen Publik — TPRM (Sprint G)
    // Pihak ketiga isi vendor questionnaire tanpa login, akses via UUID token.
    // Middleware `public-assessment-token` handle: resolve assessment dari
    // token, validasi expiry, single-use guard untuk write, set tenant
    // context, rate-limit 30 RPM per token.
    // =============================================
    Route::prefix('asesmen-publik/{token}')
        ->middleware('public-assessment-token')
        ->group(function () {
            Route::get('/', [AsesmenPublikController::class, 'show']);
            Route::post('/jawaban', [AsesmenPublikController::class, 'saveDraft']);
            Route::post('/upload', [AsesmenPublikController::class, 'uploadEvidence']);
            Route::post('/submit', [AsesmenPublikController::class, 'submit']);
            Route::get('/result', [AsesmenPublikController::class, 'result']);
        });

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
Route::middleware(['auth:sanctum', 'throttle:api', 'throttle:tenant-api', 'tenant.context', 'tenant.db', 'tenant.readonly'])->group(function () {

    // Auth
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::put('/user/settings', [AuthController::class, 'updateSettings']);
    Route::put('/user/profile', [AuthController::class, 'updateProfile']);

    // 2FA management — semua butuh auth karena per-user. Setup endpoint
    // boleh dipanggil dengan setup_token (ability '2fa:setup') untuk role
    // yang force enable, jadi user yang stuck di "must setup 2FA" bisa
    // tetap akses ini meskipun belum punya full token.
    Route::get('/auth/2fa/status', [AuthController::class, 'twoFactorStatus']);
    Route::get('/auth/whoami-ip', [AuthController::class, 'whoamiIp']);
    Route::post('/auth/verify-password', [AuthController::class, 'verifyPassword']);
    Route::post('/auth/2fa/setup', [AuthController::class, 'setupTwoFactor']);
    Route::post('/auth/2fa/confirm', [AuthController::class, 'confirmTwoFactor']);
    Route::post('/auth/2fa/disable', [AuthController::class, 'disableTwoFactor']);
    Route::post('/auth/2fa/recovery-codes/regenerate', [AuthController::class, 'regenerateRecoveryCodes']);

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
    Route::get('/tenant-roles/entitled-modules', [TenantRoleController::class, 'entitledModules']);
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

    // DPO Users (for auto-fill in RoPA/DPIA)
    Route::get('/dpo-users', [PositionController::class, 'dpoUsers']);

    // =============================================
    // GAP Assessment — Real Compliance Engine
    // =============================================
    Route::prefix('gap')->group(
        function () {
            Route::post('/comparisons', [GapComparisonController::class, 'store'])->middleware('permission:gap_assessment,write');
            Route::post('/comparisons/benchmark', [GapComparisonController::class, 'storeBenchmark'])->middleware('permission:gap_assessment,write');
            Route::get('/comparisons', [GapComparisonController::class, 'index'])->middleware('permission:gap_assessment,read');
            Route::get('/benchmark', [GapComparisonController::class, 'benchmark'])->middleware('permission:gap_assessment,read');
            Route::get('/', [GapAssessmentController::class, 'index'])->middleware('permission:gap_assessment,read');
            Route::get('/compare', [GapAssessmentController::class, 'compare'])->middleware('permission:gap_assessment,read');
            Route::get('/regulations', [GapAssessmentController::class, 'getRegulations'])->middleware('permission:gap_assessment,read');
            Route::get('/questions', [GapAssessmentController::class, 'questions'])->middleware('permission:gap_assessment,read');

            // Custom Questions CRUD (Sprint B2). MUST precede /{id} or Laravel
            // routes GET /gap/custom-questions to show($id='custom-questions') → 404.
            Route::get('/custom-questions', [GapAssessmentController::class, 'customQuestions'])->middleware('permission:gap_assessment,read');
            Route::post('/custom-questions', [GapAssessmentController::class, 'storeCustomQuestion'])->middleware('permission:gap_assessment,write');
            Route::put('/custom-questions/{id}', [GapAssessmentController::class, 'updateCustomQuestion'])->middleware('permission:gap_assessment,write');
            Route::delete('/custom-questions/{id}', [GapAssessmentController::class, 'destroyCustomQuestion'])->middleware('permission:gap_assessment,write');

            Route::post('/', [GapAssessmentController::class, 'store'])->middleware('permission:gap_assessment,write');
            Route::get('/{id}', [GapAssessmentController::class, 'show'])->middleware('permission:gap_assessment,read');
            Route::post('/{id}/submit', [GapAssessmentController::class, 'submitAnswers'])->middleware('permission:gap_assessment,write');
            Route::delete('/{id}', [GapAssessmentController::class, 'destroy'])->middleware('permission:gap_assessment,write');
            Route::post('/{id}/restore', [GapAssessmentController::class, 'restore'])->middleware('permission:gap_assessment,write');
            Route::delete('/{id}/force', [GapAssessmentController::class, 'forceDelete'])->middleware('permission:gap_assessment,write');
            Route::post('/{id}/upload-evidence', [GapAssessmentController::class, 'uploadEvidence'])->middleware('permission:gap_assessment,write');
            // Sprint G.9: AI evidence analyzer (per-question, charges 1 credit)
            Route::post('/{id}/analyze-evidence', [GapAssessmentController::class, 'analyzeEvidence'])->middleware('permission:gap_assessment,write');
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
    // Universal Module CRUD (RoPA, DPIA, DSR, Consent, Breach, Data Discovery)
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
    // RoPA — DPO Approval Workflow
    // =============================================
    Route::prefix('ropa/{id}')->group(function () {
        Route::post('/submit', [RopaApprovalController::class, 'submit'])->middleware('permission:ropa,write');
        Route::post('/approve', [RopaApprovalController::class, 'approve'])->middleware('permission:ropa,write');
        Route::post('/reject', [RopaApprovalController::class, 'reject'])->middleware('permission:ropa,write');
        // Per-section approval (issue ROPA #17a). Section keys must match Ropa::WIZARD_SECTIONS.
        Route::post('/sections/{sectionKey}/approve', [RopaApprovalController::class, 'approveSection'])->middleware('permission:ropa,write');
        Route::post('/sections/{sectionKey}/reject', [RopaApprovalController::class, 'rejectSection'])->middleware('permission:ropa,write');
        Route::post('/sections/{sectionKey}/comment', [RopaApprovalController::class, 'commentSection'])->middleware('permission:ropa,write');
    });

    // =============================================
    // RoPA — Industry Templates (seeded library)
    // =============================================
    Route::get('/ropa-templates', [RopaTemplateController::class, 'index'])->middleware('permission:ropa,read');
    Route::get('/ropa-templates/{id}', [RopaTemplateController::class, 'show'])->middleware('permission:ropa,read');

    // =============================================
    // Processing Categories — used for RoPA/DPIA naming (ROPA-HR-001).
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

    // Phase H2 — slug template React PDF aktif per tenant.
    // Disimpan di tenant_themes.active_template_slug; sumber kebenaran
    // melampaui localStorage supaya pilihan user lintas perangkat.
    Route::get('/template-slug/active', [DocumentTemplateController::class, 'getActiveSlug']);
    Route::put('/template-slug/active', [DocumentTemplateController::class, 'setActiveSlug']);

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

    // Retention master data (Sprint E3) — reusable library referenced from RoPA wizard step 7
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
    // Document Maker — AI-driven Policy + Contract drafts
    //   Wizard input → AI sections JSON → DOCX/PDF download.
    //   Used by /policy-review/add and /contract-review/add.
    // =============================================
    Route::prefix('document-maker')->group(function () {
        Route::get('/', [DocumentMakerController::class, 'index']);
        Route::post('/{kind}/generate', [DocumentMakerController::class, 'generate'])
            ->where('kind', 'policy|contract');
        Route::get('/{id}', [DocumentMakerController::class, 'show'])
            ->where('id', '[0-9a-fA-F-]{36}');
        Route::put('/{id}', [DocumentMakerController::class, 'update'])
            ->where('id', '[0-9a-fA-F-]{36}');
        Route::delete('/{id}', [DocumentMakerController::class, 'destroy'])
            ->where('id', '[0-9a-fA-F-]{36}');
        Route::post('/{id}/restore', [DocumentMakerController::class, 'restore'])
            ->where('id', '[0-9a-fA-F-]{36}');
        Route::delete('/{id}/force', [DocumentMakerController::class, 'forceDelete'])
            ->where('id', '[0-9a-fA-F-]{36}');
        Route::get('/{id}/download.docx', [DocumentMakerController::class, 'downloadDocx'])
            ->where('id', '[0-9a-fA-F-]{36}');
        Route::get('/{id}/download.pdf', [DocumentMakerController::class, 'downloadPdf'])
            ->where('id', '[0-9a-fA-F-]{36}');
        Route::post('/{id}/fix-compliance', [DocumentMakerController::class, 'fixCompliance'])
            ->where('id', '[0-9a-fA-F-]{36}');
        Route::post('/{id}/recheck-compliance', [DocumentMakerController::class, 'recheckCompliance'])
            ->where('id', '[0-9a-fA-F-]{36}');
    });

    // =============================================
    // Phase 2: Vendor Risk Management (Third Party Management)
    // =============================================
    Route::prefix('vendor-risk')->group(function () {
        // Phase 2 — Deterministic questionnaire endpoints. Must precede /{id}.
        Route::get('/categories', [VendorRiskController::class, 'listCategories']);
        Route::get('/questionnaire/{category}', [VendorRiskController::class, 'getQuestionnaire']);
        Route::post('/assess-deterministic', [VendorRiskController::class, 'assessDeterministic']);                  // create + assess in one call
        Route::post('/{id}/assess-deterministic', [VendorRiskController::class, 'assessDeterministic']);             // re-assess existing

        Route::get('/trashed', [VendorRiskController::class, 'trashed']);
        Route::get('/', [VendorRiskController::class, 'index']);
        Route::post('/', [VendorRiskController::class, 'store']);
        Route::get('/{id}', [VendorRiskController::class, 'show']);
        Route::put('/{id}', [VendorRiskController::class, 'update']);
        Route::delete('/{id}', [VendorRiskController::class, 'destroy']);
        Route::post('/{id}/restore', [VendorRiskController::class, 'restore']);
        Route::delete('/{id}/force', [VendorRiskController::class, 'forceDelete']);
        Route::post('/{id}/submit-for-approval', [VendorRiskController::class, 'submitForApproval']);
        // AI Assessment (existing — parallel path to deterministic)
        Route::post('/extract', [VendorRiskController::class, 'extract']);
        Route::post('/generate-questions', [VendorRiskController::class, 'generateQuestions']);
        Route::post('/assess', [VendorRiskController::class, 'assess']);

        // Sprint D3: TPRM document management
        Route::post('/{id}/documents', [VendorRiskController::class, 'uploadDocument']);
        Route::delete('/{id}/documents/{docId}', [VendorRiskController::class, 'deleteDocument']);
        Route::post('/{id}/screen-documents', [VendorRiskController::class, 'screenDocuments']);
        // Re-assessment (manual override atau AI re-run) — legacy path
        Route::post('/{id}/reassess', [VendorRiskController::class, 'reassess']);

        // Sprint G.6 — Generate public assessment link untuk pihak ketiga
        Route::post('/{id}/generate-public-link', [VendorRiskController::class, 'generatePublicLink'])
            ->middleware('permission:vendor_risk,write');
        // Sprint G.2 — Upload dokumen intake typed (akta/ktp/kontrak/CP)
        Route::post('/{id}/intake-documents', [VendorRiskController::class, 'uploadIntakeDocument'])
            ->middleware('permission:vendor_risk,write');

        // TPRM Phase 4 — Assessment history per vendor
        Route::get('/{id}/assessment-history', [VendorRiskController::class, 'assessmentHistory'])
            ->middleware('permission:vendor_risk,read');

        // TPRM Phase 3 — AI Vendor Screening
        Route::post('/bulk-screen', [VendorScreeningController::class, 'bulkScreen'])
            ->middleware('permission:vendor_risk,write');
        Route::post('/{id}/screen', [VendorScreeningController::class, 'run'])
            ->middleware('permission:vendor_risk,write');
        Route::get('/{id}/screenings', [VendorScreeningController::class, 'index'])
            ->middleware('permission:vendor_risk,read');
        Route::get('/{id}/screenings/{sid}', [VendorScreeningController::class, 'show'])
            ->middleware('permission:vendor_risk,read');
    });

    // =============================================
    // Sprint G.4 — Customisasi pertanyaan TPRM per-tenant
    // Tenant admin bisa tambah / edit / nonaktifkan pertanyaan default
    // (56 baseline) plus tambah pertanyaan custom. Permission slug: vendor_risk
    // (modul TPRM tidak punya slug terpisah; lihat CheckPermission middleware).
    // =============================================
    Route::prefix('third-party/questions')->group(function () {
        Route::get('/', [ThirdPartyQuestionController::class, 'index'])
            ->middleware('permission:vendor_risk,read');
        Route::post('/', [ThirdPartyQuestionController::class, 'store'])
            ->middleware('permission:vendor_risk,write');
        Route::put('/{id}', [ThirdPartyQuestionController::class, 'update'])
            ->middleware('permission:vendor_risk,write');
        Route::delete('/{id}', [ThirdPartyQuestionController::class, 'destroy'])
            ->middleware('permission:vendor_risk,write');
    });

    // =============================================
    // TPRM Phase 1 — Question Library + Segment + Question Builder
    // Library wrapper untuk customisasi pertanyaan TPRM per use case
    // (mis. PDP, ISO 27001, Custom Vendor Cloud). Tenant boleh clone
    // template global, edit segment + question. Permission slug: vendor_risk.
    // =============================================
    Route::prefix('tprm/libraries')->group(function () {
        // Library CRUD
        Route::get('/', [TprmLibraryController::class, 'index'])
            ->middleware('permission:vendor_risk,read');
        Route::post('/', [TprmLibraryController::class, 'store'])
            ->middleware('permission:vendor_risk,write');
        Route::get('/{id}', [TprmLibraryController::class, 'show'])
            ->middleware('permission:vendor_risk,read');
        Route::patch('/{id}', [TprmLibraryController::class, 'update'])
            ->middleware('permission:vendor_risk,write');
        Route::delete('/{id}', [TprmLibraryController::class, 'destroy'])
            ->middleware('permission:vendor_risk,write');
        Route::post('/{id}/clone', [TprmLibraryController::class, 'clone'])
            ->middleware('permission:vendor_risk,write');

        // Segment CRUD (nested)
        Route::post('/{id}/segments', [TprmLibraryController::class, 'storeSegment'])
            ->middleware('permission:vendor_risk,write');
        Route::patch('/{id}/segments/{segmentId}', [TprmLibraryController::class, 'updateSegment'])
            ->middleware('permission:vendor_risk,write');
        Route::delete('/{id}/segments/{segmentId}', [TprmLibraryController::class, 'destroySegment'])
            ->middleware('permission:vendor_risk,write');
        Route::post('/{id}/segments/reorder', [TprmLibraryController::class, 'reorderSegments'])
            ->middleware('permission:vendor_risk,write');

        // Question CRUD (nested)
        Route::get('/{id}/questions', [TprmLibraryController::class, 'listQuestions'])
            ->middleware('permission:vendor_risk,read');
        Route::post('/{id}/questions', [TprmLibraryController::class, 'storeQuestion'])
            ->middleware('permission:vendor_risk,write');
        Route::post('/{id}/questions/bulk', [TprmLibraryController::class, 'bulkStoreQuestions'])
            ->middleware('permission:vendor_risk,write');
        Route::patch('/{id}/questions/{questionId}', [TprmLibraryController::class, 'updateQuestion'])
            ->middleware('permission:vendor_risk,write');
        Route::delete('/{id}/questions/{questionId}', [TprmLibraryController::class, 'destroyQuestion'])
            ->middleware('permission:vendor_risk,write');
        Route::post('/{id}/questions/reorder', [TprmLibraryController::class, 'reorderQuestions'])
            ->middleware('permission:vendor_risk,write');
    });

    // =============================================
    // TPRM Phase 3.5 — Helper endpoints
    // =============================================
    Route::get('tprm/context-presets', [VendorScreeningController::class, 'listPresets'])
        ->middleware('permission:vendor_risk,read');

    // =============================================
    // TPRM Phase 2 — Workflow Review (stage Maker→Reviewer)
    // =============================================
    Route::prefix('tprm/review')->group(function () {
        Route::get('/inbox', [TprmReviewController::class, 'inbox'])
            ->middleware('permission:vendor_risk,read');
        Route::get('/{id}', [TprmReviewController::class, 'show'])
            ->middleware('permission:vendor_risk,read');
        Route::post('/{id}/start', [TprmReviewController::class, 'start'])
            ->middleware('permission:vendor_risk,write');
        Route::post('/{id}/adjust', [TprmReviewController::class, 'adjust'])
            ->middleware('permission:vendor_risk,write');
        Route::post('/{id}/submit-to-approver', [TprmReviewController::class, 'submitToApprover'])
            ->middleware('permission:vendor_risk,write');
        Route::post('/{id}/reject-to-vendor', [TprmReviewController::class, 'rejectToVendor'])
            ->middleware('permission:vendor_risk,write');
    });

    // =============================================
    // TPRM Phase 4 — Monitoring berkala vendor + Incident report
    // =============================================
    Route::prefix('tprm/monitoring')->group(function () {
        Route::get('/inbox', [TprmMonitoringController::class, 'inbox'])
            ->middleware('permission:vendor_risk,read');
        Route::get('/by-vendor/{vendorId}', [TprmMonitoringController::class, 'byVendor'])
            ->middleware('permission:vendor_risk,read');
        Route::post('/', [TprmMonitoringController::class, 'store'])
            ->middleware('permission:vendor_risk,write');
        Route::get('/{id}', [TprmMonitoringController::class, 'show'])
            ->middleware('permission:vendor_risk,read');
        Route::post('/{id}/complete', [TprmMonitoringController::class, 'complete'])
            ->middleware('permission:vendor_risk,write');
        Route::delete('/{id}', [TprmMonitoringController::class, 'destroy'])
            ->middleware('permission:vendor_risk,write');
    });

    Route::prefix('tprm/incidents')->group(function () {
        Route::get('/meta', [TprmIncidentController::class, 'meta'])
            ->middleware('permission:vendor_risk,read');
        Route::get('/', [TprmIncidentController::class, 'index'])
            ->middleware('permission:vendor_risk,read');
        Route::post('/', [TprmIncidentController::class, 'store'])
            ->middleware('permission:vendor_risk,write');
        Route::get('/{id}', [TprmIncidentController::class, 'show'])
            ->middleware('permission:vendor_risk,read');
        Route::patch('/{id}', [TprmIncidentController::class, 'update'])
            ->middleware('permission:vendor_risk,write');
        Route::post('/{id}/apply-risk', [TprmIncidentController::class, 'applyRisk'])
            ->middleware('permission:vendor_risk,write');
        Route::delete('/{id}', [TprmIncidentController::class, 'destroy'])
            ->middleware('permission:vendor_risk,write');
    });

    // =============================================
    // TPRM Phase 2 — Workflow Approval (stage Reviewer→Approver)
    // =============================================
    Route::prefix('tprm/approval')->group(function () {
        Route::get('/inbox', [TprmApprovalController::class, 'inbox'])
            ->middleware('permission:vendor_risk,read');
        Route::post('/{id}/approve', [TprmApprovalController::class, 'approve'])
            ->middleware('permission:vendor_risk,write');
        Route::post('/{id}/reject', [TprmApprovalController::class, 'reject'])
            ->middleware('permission:vendor_risk,write');
        Route::post('/{id}/return-to-reviewer', [TprmApprovalController::class, 'returnToReviewer'])
            ->middleware('permission:vendor_risk,write');
        Route::post('/{id}/reopen', [TprmApprovalController::class, 'reopen'])
            ->middleware('permission:vendor_risk,write');
    });

    // =============================================
    // Phase 2: Cross Border Data Transfer
    // =============================================
    Route::prefix('cross-border')->group(function () {
        // Country adequacy lookup (Phase 1) — must precede /{id} so 'countries'
        // doesn't get treated as a UUID.
        Route::get('/countries', [CrossBorderController::class, 'listCountries']);
        Route::get('/countries/{codeOrName}', [CrossBorderController::class, 'resolveCountry']);

        Route::get('/trashed', [CrossBorderController::class, 'trashed']);
        Route::get('/', [CrossBorderController::class, 'index']);
        Route::post('/', [CrossBorderController::class, 'store']);
        Route::get('/{id}', [CrossBorderController::class, 'show']);
        Route::put('/{id}', [CrossBorderController::class, 'update']);
        Route::delete('/{id}', [CrossBorderController::class, 'destroy']);
        Route::post('/{id}/restore', [CrossBorderController::class, 'restore']);
        Route::delete('/{id}/force', [CrossBorderController::class, 'forceDelete']);

        // Legacy AI TIA endpoint — kept for backwards compat with existing
        // CBDT records that have tia_summary/tia_answers data. New flow uses
        // POST /tia/from-cross-border/{cbtId} which spawns a proper Sprint X2
        // TiaAssessment with RACI workflow + 6 risk metrics + 2 security.
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
        // Apply scan recommendation to a column's final status (terima/tolak rekomendasi)
        Route::post('/{id}/columns/apply', [DataDiscoveryController::class, 'applyColumn'])->middleware('permission:data_discovery,write');
        Route::post('/{id}/columns/apply-bulk', [DataDiscoveryController::class, 'applyColumnBulk'])->middleware('permission:data_discovery,write');
        Route::get('/{id}/ropa-links', [DataDiscoveryController::class, 'ropaLinks'])->middleware('permission:data_discovery,read');

        // Many-to-many RoPA pivot management
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

        // Agent #7 — Ephemeral AI Search Execute (multi-case, no persistence)
        //   POST /ai-search/execute → batch text-to-SQL + execute + cap 100 rows per case
        //   Rate-limited 5 req/min (AI calls + DB queries are expensive).
        Route::post('/{id}/ai-search/execute', [DataDiscoveryController::class, 'aiSearchExecute'])
            ->middleware(['permission:data_discovery,write', 'throttle:5,1']);

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

    // =============================================
    // Data Discovery — Person Scan (cross-app PII discovery)
    // =============================================
    Route::prefix('data-discovery/scan')->group(function () {
        Route::post('generate', [DataDiscoveryScanController::class, 'store'])
            ->middleware('permission:data_discovery,write');
        Route::get('plans', [DataDiscoveryScanController::class, 'index'])
            ->middleware('permission:data_discovery,read');
        Route::get('plans/{id}', [DataDiscoveryScanController::class, 'show'])
            ->middleware('permission:data_discovery,read');
        Route::delete('plans/{id}', [DataDiscoveryScanController::class, 'destroy'])
            ->middleware('permission:data_discovery,write');
        Route::post('plans/{id}/restore', [DataDiscoveryScanController::class, 'restore'])
            ->middleware('permission:data_discovery,write');
        Route::delete('plans/{id}/force', [DataDiscoveryScanController::class, 'forceDelete'])
            ->middleware('permission:data_discovery,write');
        Route::post('plans/{id}/execute', [DataDiscoveryScanController::class, 'execute'])
            ->middleware('permission:data_discovery,write');
        Route::get('plans/{id}/results', [DataDiscoveryScanController::class, 'results'])
            ->middleware('permission:data_discovery,read');
        Route::post('plans/{id}/to-dsr', [DataDiscoveryScanController::class, 'toDsr'])
            ->middleware('permission:data_discovery,write');
    });
    Route::post('data-discovery/scan-results/{id}/reveal', [DataDiscoveryScanController::class, 'reveal'])
        ->middleware('permission:data_discovery,reveal');

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
    Route::get('/cookie-logs', [CookieLogAdminController::class, 'index'])->middleware('permission:consent,read');
    Route::get('/cookie-logs/stats', [CookieLogAdminController::class, 'stats'])->middleware('permission:consent,read');
    Route::get('/cookie-logs/{id}', [CookieLogAdminController::class, 'show'])->middleware('permission:consent,read')->where('id', '[0-9a-fA-F-]{36}');
    Route::delete('/cookie-logs/{id}', [CookieLogAdminController::class, 'destroy'])->middleware('permission:consent,write')->where('id', '[0-9a-fA-F-]{36}');

    // RAG infra — vector embedding stats & control plane (admin/superadmin only).
    // Role check di dalam controller (root|superadmin|admin) supaya tenant
    // admin tetap bisa monitor scope org-nya sendiri.
    Route::prefix('admin/embeddings')->group(function () {
        Route::get('/stats', [EmbeddingStatsController::class, 'stats']);
        Route::post('/reembed', [EmbeddingStatsController::class, 'reembedAll']);
        Route::get('/health', [EmbeddingStatsController::class, 'health']);
    });

    // Phase B — Consent Extract (CRM extractor wizard backbone)
    Route::post('/consent-extract/preview', [ConsentExtractController::class, 'preview'])->middleware('permission:consent,read');
    Route::post('/consent-extract/run', [ConsentExtractController::class, 'run'])->middleware('permission:consent,write');
    Route::get('/consent-extract/runs', [ConsentExtractController::class, 'index'])->middleware('permission:consent,read');

    // Phase F — CRM credentials (per-org, encrypted secrets)
    Route::get('/crm-credentials', [CrmCredentialController::class, 'index'])->middleware('permission:consent,read');
    Route::post('/crm-credentials', [CrmCredentialController::class, 'store'])->middleware('permission:consent,write');
    Route::put('/crm-credentials/{id}', [CrmCredentialController::class, 'update'])->middleware('permission:consent,write')->where('id', '[0-9a-fA-F-]{36}');
    Route::delete('/crm-credentials/{id}', [CrmCredentialController::class, 'destroy'])->middleware('permission:consent,write')->where('id', '[0-9a-fA-F-]{36}');
    Route::post('/crm-credentials/{id}/probe', [CrmCredentialController::class, 'probe'])->middleware('permission:consent,write')->where('id', '[0-9a-fA-F-]{36}');
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

    // Approval Workflow Configuration (per module, per org)
    Route::prefix('approval-configs')->group(function () {
        Route::get('/', [ApprovalConfigController::class, 'index']);
        Route::get('/eligible-roles/{module}', [ApprovalConfigController::class, 'eligibleRoles']);
        Route::get('/{module}', [ApprovalConfigController::class, 'show']);
        Route::put('/{module}', [ApprovalConfigController::class, 'update']);
    });

    // Security Posture & Alerts (DSPM) Phase 4
    Route::prefix('security')->group(function () {
        Route::get('/posture', [PostureController::class, 'getPosture']);
        Route::get('/posture/trend', [PostureController::class, 'getTrend']);
        Route::post('/posture/snapshot', [PostureController::class, 'takeSnapshot']); // Phase 3a — manual refresh

        // Phase 3b — Findings workflow
        Route::get('/findings/stats', [PostureFindingController::class, 'stats']);
        Route::post('/findings/rematerialize', [PostureFindingController::class, 'rematerialize']);
        Route::get('/findings', [PostureFindingController::class, 'index']);
        Route::get('/findings/{id}', [PostureFindingController::class, 'show']);
        Route::post('/findings/{id}/assign', [PostureFindingController::class, 'assign']);
        Route::post('/findings/{id}/status', [PostureFindingController::class, 'changeStatus']);

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
    // ai-throttle: per-user rate limit panggilan AI (default 20/menit).
    // Cegah single user spam request / drain kuota tenant. Settings di
    // /platform-admin/system-settings → Security → AI Limits.
    Route::post('/ai/chat', [AiChatController::class, 'chat'])->middleware('ai-throttle');
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
    // ai-throttle: per-user rate limit (default 20/menit) untuk semua
    // endpoint AI feature — analisis, autofill, drill scenario, dst.
    Route::prefix('ai-features')->middleware('ai-throttle')->group(function () {
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
        Route::post('/assessment/{kind}/ask', [AiFeatureController::class, 'assessmentAskAi'])->where('kind', 'lia|tia|maturity'); // Sprint X4 Tanya AI
        Route::post('/autofill/breach', [AiFeatureController::class, 'autofillBreach']);
        Route::post('/autofill/dsr', [AiFeatureController::class, 'autofillDsr']);
        Route::post('/autofill/consent-items/{id}', [AiFeatureController::class, 'autofillConsentItems']);
    });

    // AI Credit Management
    Route::get('/ai-credits/usage', [AiFeatureController::class, 'creditUsage']);
    Route::get('/ai-credits/monthly-history', [AiFeatureController::class, 'creditMonthlyHistory']);
    Route::post('/ai-credits/topup', [AiFeatureController::class, 'creditTopup']);

    // =============================================
    // Sprint C1: Custom Fields & Templates (RoPA / DPIA)
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
    // Sprint X1: LIA — full workflow (RACI + lock + RoPA auto-fill)
    // See backend/docs/LIA_TIA_MATURITY_TRACKER.md and Fitur LIA.pdf.
    // =============================================
    Route::prefix('lia')->group(function () {
        Route::get('/', [LiaController::class, 'index']);
        Route::post('/', [LiaController::class, 'store']);
        Route::post('/from-ropa/{ropaId}', [LiaController::class, 'fromRopa']);
        Route::get('/{id}', [LiaController::class, 'show']);
        Route::put('/{id}', [LiaController::class, 'update']);
        Route::delete('/{id}', [LiaController::class, 'destroy']);
        Route::post('/{id}/restore', [LiaController::class, 'restore']);
        Route::delete('/{id}/force', [LiaController::class, 'forceDelete']);

        // Workflow — see PDF Fitur LIA.pdf §RACI Matrix
        Route::post('/{id}/submit', [LiaController::class, 'submit']);    // Maker: lock + send to checker
        Route::post('/{id}/check', [LiaController::class, 'check']);      // Checker: pass | reject
        Route::post('/{id}/approve', [LiaController::class, 'approve']);  // Approver: 3 verdicts + final
        Route::post('/{id}/reject', [LiaController::class, 'reject']);    // Approver: reject + reason
        Route::post('/{id}/unlock', [LiaController::class, 'unlock']);    // root only — emergency unlock
        Route::get('/{id}/export.pdf', [LiaController::class, 'exportPdf']); // Sprint X4 — branded PDF
    });

    // =============================================
    // Sprint X2: TIA — full workflow (cross-border + vendor + RACI)
    // See backend/docs/LIA_TIA_MATURITY_TRACKER.md and Fitur TIA.pdf.
    // =============================================
    Route::prefix('tia')->group(function () {
        Route::get('/', [TiaController::class, 'index']);
        Route::post('/', [TiaController::class, 'store']);
        Route::post('/from-ropa/{ropaId}', [TiaController::class, 'fromRopa']);
        Route::post('/from-cross-border/{cbtId}', [TiaController::class, 'fromCrossBorder']);
        Route::post('/from-vendor/{vendorId}', [TiaController::class, 'fromVendor']);
        Route::get('/{id}', [TiaController::class, 'show']);
        Route::put('/{id}', [TiaController::class, 'update']);
        Route::delete('/{id}', [TiaController::class, 'destroy']);
        Route::post('/{id}/restore', [TiaController::class, 'restore']);
        Route::delete('/{id}/force', [TiaController::class, 'forceDelete']);

        // Workflow
        Route::post('/{id}/submit', [TiaController::class, 'submit']);
        Route::post('/{id}/check', [TiaController::class, 'check']);
        Route::post('/{id}/approve', [TiaController::class, 'approve']);
        Route::post('/{id}/reject', [TiaController::class, 'reject']);
        Route::post('/{id}/unlock', [TiaController::class, 'unlock']);
        Route::get('/{id}/export.pdf', [TiaController::class, 'exportPdf']); // Sprint X4
    });

    // =============================================
    // Sprint X3: Maturity Assessment — UU PDP self-evaluation
    // 3 input methods (questionnaire / document / auto_derive). No
    // formal approval — status flow draft → submitted → published.
    // =============================================
    Route::prefix('maturity')->group(function () {
        Route::get('/questions', [MaturityController::class, 'questions']);
        Route::get('/trend', [MaturityController::class, 'trend']);

        Route::get('/', [MaturityController::class, 'index']);
        Route::post('/', [MaturityController::class, 'store']);
        Route::get('/{id}', [MaturityController::class, 'show']);
        Route::delete('/{id}', [MaturityController::class, 'destroy']);
        Route::post('/{id}/restore', [MaturityController::class, 'restore']);
        Route::delete('/{id}/force', [MaturityController::class, 'forceDelete']);

        Route::post('/{id}/responses', [MaturityController::class, 'upsertResponse']);
        Route::post('/{id}/responses/bulk', [MaturityController::class, 'bulkUpsertResponses']);
        Route::post('/{id}/auto-derive', [MaturityController::class, 'autoDerive']);
        Route::post('/{id}/submit', [MaturityController::class, 'submit']);
        Route::post('/{id}/publish', [MaturityController::class, 'publish']);
        Route::get('/{id}/recommendations', [MaturityController::class, 'recommendations']);
        Route::get('/{id}/export.pdf', [MaturityController::class, 'exportPdf']); // Sprint X4
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
        Route::get('/entitlements-summary', [MenuRegistryController::class, 'entitlementsSummary']);
        Route::put('/entitlements', [MenuRegistryController::class, 'updateEntitlement']);
        Route::delete('/entitlements/{id}', [MenuRegistryController::class, 'deleteEntitlement']);
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
    Route::get('/superadmin-dashboard', [SuperadminDashboardController::class, 'index']);

    // =============================================
    // QA Center — root-only fitur untuk track test coverage seluruh platform
    // =============================================
    Route::prefix('root/qa')->middleware('role.root_only')->group(function () {
        // Test cases (catalog)
        Route::get('/cases', [QaCenterController::class, 'listCases']);
        Route::get('/cases/modules-summary', [QaCenterController::class, 'modulesSummary']);
        Route::get('/cases/{id}', [QaCenterController::class, 'showCase'])->where('id', '[0-9a-fA-F-]{36}');
        Route::post('/cases', [QaCenterController::class, 'createCase']);
        Route::put('/cases/{id}', [QaCenterController::class, 'updateCase'])->where('id', '[0-9a-fA-F-]{36}');
        Route::delete('/cases/{id}', [QaCenterController::class, 'deleteCase'])->where('id', '[0-9a-fA-F-]{36}');

        // Test runs (cycles)
        Route::get('/active-run', [QaCenterController::class, 'activeRun']);
        Route::get('/runs', [QaCenterController::class, 'listRuns']);
        Route::post('/runs', [QaCenterController::class, 'createRun']);
        Route::get('/runs/{id}', [QaCenterController::class, 'showRun'])->where('id', '[0-9a-fA-F-]{36}');
        Route::put('/runs/{id}', [QaCenterController::class, 'updateRun'])->where('id', '[0-9a-fA-F-]{36}');
        Route::delete('/runs/{id}', [QaCenterController::class, 'deleteRun'])->where('id', '[0-9a-fA-F-]{36}');
        Route::post('/runs/{id}/restore', [QaCenterController::class, 'restoreRun'])->where('id', '[0-9a-fA-F-]{36}');
        Route::delete('/runs/{id}/force', [QaCenterController::class, 'forceDeleteRun'])->where('id', '[0-9a-fA-F-]{36}');

        // AI Analyzer per run
        Route::post('/runs/{runId}/analyze', [QaCenterController::class, 'analyzeRun'])->where('runId', '[0-9a-fA-F-]{36}');

        // Test results (per run)
        Route::get('/runs/{runId}/results', [QaCenterController::class, 'listResults'])->where('runId', '[0-9a-fA-F-]{36}');
        Route::patch('/results/{id}', [QaCenterController::class, 'updateResult'])->where('id', '[0-9a-fA-F-]{36}');
        Route::get('/results/{id}/history', [QaCenterController::class, 'resultHistory'])->where('id', '[0-9a-fA-F-]{36}');

        // Bug reports
        Route::get('/bugs', [QaCenterController::class, 'listBugs']);
        Route::post('/bugs', [QaCenterController::class, 'createBug']);
        Route::get('/bugs/{id}', [QaCenterController::class, 'showBug'])->where('id', '[0-9a-fA-F-]{36}');
        Route::patch('/bugs/{id}', [QaCenterController::class, 'updateBug'])->where('id', '[0-9a-fA-F-]{36}');
        Route::delete('/bugs/{id}', [QaCenterController::class, 'deleteBug'])->where('id', '[0-9a-fA-F-]{36}');

        // Bug screenshots
        Route::post('/bugs/{bugId}/screenshots', [QaCenterController::class, 'uploadScreenshot'])->where('bugId', '[0-9a-fA-F-]{36}');
        Route::get('/screenshots/{id}/download', [QaCenterController::class, 'downloadScreenshot'])->where('id', '[0-9a-fA-F-]{36}');
        Route::delete('/screenshots/{id}', [QaCenterController::class, 'deleteScreenshot'])->where('id', '[0-9a-fA-F-]{36}');

        // Dashboard
        Route::get('/dashboard', [QaCenterController::class, 'dashboard']);
    });

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

    // =============================================
    // Custom Wizard Foundation (CUSTOM_WIZARD_PLAN.md Phase 1+2)
    //   - GET /wizard-schema/{module}      → built-in + org-custom merged schema
    //   - CRUD /custom-sections            → org-custom section management
    //   - CRUD /custom-fields              → org-custom field management
    //
    // NOTE: more-specific routes (`/reorder`) MUST be registered before the
    // dynamic `/{id}` route or Laravel matches `reorder` as an id.
    // =============================================
    Route::get('/wizard-schema/{module}', [WizardSchemaController::class, 'show']);

    Route::prefix('custom-sections')->group(function () {
        Route::get('/', [CustomSectionController::class, 'index']);
        Route::post('/', [CustomSectionController::class, 'store']);
        Route::put('/reorder', [CustomSectionController::class, 'reorder']);
        Route::put('/{id}', [CustomSectionController::class, 'update']);
        Route::delete('/{id}', [CustomSectionController::class, 'destroy']);
        Route::post('/{id}/fields', [CustomFieldController::class, 'storeForSection']);
    });

    Route::prefix('custom-fields')->group(function () {
        Route::get('/', [CustomFieldController::class, 'index']);
        Route::post('/', [CustomFieldController::class, 'store']);
        Route::put('/reorder', [CustomFieldController::class, 'reorder']);
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
        Route::get('/ropa/xlsx', [ExportController::class, 'ropaXlsx']);
        Route::get('/dpia', [ExportController::class, 'dpia']);
        Route::get('/dpia/xlsx', [ExportController::class, 'dpiaXlsx']);
        Route::get('/dpia-risks', [ExportController::class, 'dpiaRisks']);
        Route::get('/breach', [ExportController::class, 'breach']);
        Route::get('/breach/xlsx', [ExportController::class, 'breachXlsx']);
        Route::get('/dsr', [ExportController::class, 'dsr']);
        Route::get('/dsr/xlsx', [ExportController::class, 'dsrXlsx']);
        Route::get('/consent', [ExportController::class, 'consent']);
        Route::get('/consent/xlsx', [ExportController::class, 'consentXlsx']);
        Route::get('/consent-records', [ExportController::class, 'consentRecords']);
        Route::get('/gap-assessment', [ExportController::class, 'gapAssessment']);
        Route::get('/gap-assessment/xlsx', [ExportController::class, 'gapAssessmentXlsx']);
        Route::get('/data-discovery', [ExportController::class, 'dataDiscovery']);
        Route::get('/data-discovery/xlsx', [ExportController::class, 'dataDiscoveryXlsx']);
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
        // ai-throttle hanya di endpoint chat (yang panggil provider AI).
        // Approve/reject/mentions/history murni DB — tidak perlu di-throttle.
        Route::post('/chat', [AiAgentController::class, 'chat'])->middleware('ai-throttle');
        Route::post('/approve-action', [AiAgentController::class, 'approveAction']);
        Route::post('/reject-action', [AiAgentController::class, 'rejectAction']);
        Route::get('/mentions/{type}', [AiAgentController::class, 'mentions']);
        Route::get('/history', [AiAgentController::class, 'history']);
        Route::get('/history/{id}/messages', [AiAgentController::class, 'conversationMessages']);
    });

    // =============================================
    // Avatar 3D Chat (Platform Q&A with Knowledge Base)
    // =============================================
    Route::post('/avatar/chat', [AvatarChatController::class, 'chat'])->middleware('ai-throttle');

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

        // Tenant Isolation Management — list + provision + migrate + status
        Route::get('/tenants', [TenantIsolationController::class, 'index']);
        Route::get('/tenants/{id}', [TenantIsolationController::class, 'show']);
        Route::post('/tenants/{id}/provision', [TenantIsolationController::class, 'provision']);
        Route::post('/tenants/{id}/migrate', [TenantIsolationController::class, 'migrate']);
        Route::get('/tenants/{id}/isolation/status', [TenantIsolationController::class, 'status']);
        Route::post('/tenants/{id}/isolation/reset-failed', [TenantIsolationController::class, 'resetFailed']);

        // Change Request Approval Queue (superadmin)
        Route::get('/change-requests', [TenantChangeRequestController::class, 'adminIndex']);
        Route::get('/change-requests/{id}', [TenantChangeRequestController::class, 'adminShow']);
        Route::post('/change-requests/{id}/approve', [TenantChangeRequestController::class, 'approve']);
        Route::post('/change-requests/{id}/deny', [TenantChangeRequestController::class, 'deny']);
    });

    // =============================================
    // Tenant-side Change Request Submission
    // Tenant admins submit infrastructure-change requests; superadmin
    // reviews above. See BYODB.md §2.6.
    // =============================================
    Route::prefix('tenant')->group(function () {
        Route::get('/infrastructure-status', [TenantChangeRequestController::class, 'tenantStatus']);
        Route::get('/change-requests', [TenantChangeRequestController::class, 'tenantIndex']);
        Route::post('/change-requests', [TenantChangeRequestController::class, 'tenantStore']);
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

    // =============================================
    // AI Background Jobs (INFRASTRUCTURE_PLAN.md §5)
    // Async dispatch + polling endpoints used by the AI Jobs Footer.
    // =============================================
    Route::prefix('ai/jobs')->group(function () {
        Route::post('/', [AiJobController::class, 'store']);
        Route::get('/active', [AiJobController::class, 'active']);
        Route::get('/history', [AiJobController::class, 'history']);
        Route::get('/{id}', [AiJobController::class, 'show']);
    });

    // =============================================
    // Platform System Settings (INFRASTRUCTURE_PLAN.md §4)
    // Section-based config: infrastructure, redis, ai, mail, aws, deployment.
    // Gated by `permission:settings,write` — superadmin role bypasses.
    // =============================================
    Route::prefix('platform-admin/settings')
        ->middleware('permission:settings,write')
        ->group(function () {
            Route::get('/', [SystemSettingsController::class, 'index']);
            Route::get('/health', [SystemSettingsController::class, 'health']);
            Route::put('/{section}', [SystemSettingsController::class, 'update']);
            Route::post('/{section}/test', [SystemSettingsController::class, 'test']);
        });

    // Pentest reports — rekap hasil penetration test dari vendor pihak ketiga.
    // Platform-level (tidak tenant-scoped), gate by settings,write permission.
    Route::prefix('platform-admin/pentest-reports')
        ->middleware('permission:settings,write')
        ->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\PentestReportController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\Api\PentestReportController::class, 'store']);
            // Security posture endpoints — di-mount SEBELUM /{id} supaya
            // tidak ke-conflict dengan UUID route parameter.
            Route::get('/security-posture', [\App\Http\Controllers\Api\PentestReportController::class, 'securityPosture']);
            Route::get('/security-posture/download', [\App\Http\Controllers\Api\PentestReportController::class, 'securityPosturePdf']);
            Route::get('/{id}', [\App\Http\Controllers\Api\PentestReportController::class, 'show']);
            Route::put('/{id}', [\App\Http\Controllers\Api\PentestReportController::class, 'update']);
            Route::post('/{id}', [\App\Http\Controllers\Api\PentestReportController::class, 'update']); // multipart workaround
            Route::delete('/{id}', [\App\Http\Controllers\Api\PentestReportController::class, 'destroy']);
            Route::get('/{id}/download', [\App\Http\Controllers\Api\PentestReportController::class, 'download']);
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
