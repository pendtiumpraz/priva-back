<?php

/**
 * AI background job & provider runtime config.
 *
 * Defaults below are bootstrap fallbacks — SettingsServiceProvider overrides
 * them at runtime from the system_settings table. Treat values here as
 * "what the app falls back to before the DB is reachable" (e.g. fresh install,
 * artisan commands before migrations).
 */
return [

    /*
    |---------------------------------------------------------------------
    | Background jobs
    |---------------------------------------------------------------------
    |
    | When false, ProcessAiJob short-circuits to status=cancelled and
    | AiJobController::store returns 503. Used as a kill-switch when the AI
    | provider is unconfigured, over budget, or being maintained.
    */
    'jobs_enabled' => env('AI_JOBS_ENABLED', true),

    /*
    |---------------------------------------------------------------------
    | Deployment mode (saas | onprem)
    |---------------------------------------------------------------------
    |
    | Affects which AI tools are available to the agent. See
    | INFRASTRUCTURE_PLAN.md §9. Overridden by deployment.mode setting.
    */
    'deployment_mode' => env('AI_DEPLOYMENT_MODE', 'saas'),

    /*
    |---------------------------------------------------------------------
    | Per-user concurrent job quota
    |---------------------------------------------------------------------
    |
    | AiJobController rejects with 429 when a user already has this many
    | jobs in pending/running status. Prevents one user from monopolizing
    | the queue.
    */
    'max_concurrent_per_user' => env('AI_MAX_CONCURRENT_PER_USER', 5),

    /*
    |---------------------------------------------------------------------
    | History retention (days)
    |---------------------------------------------------------------------
    |
    | ai_jobs older than this are eligible for pruning by a scheduled
    | command (separate task — see open question §11.1).
    */
    'history_retention_days' => env('AI_HISTORY_RETENTION_DAYS', 30),

    /*
    |---------------------------------------------------------------------
    | Provider config (for reference only — runtime values come from DB)
    |---------------------------------------------------------------------
    |
    | These mirror the system_settings ai.* keys so unit tests / artisan can
    | run without DB. Production uses DB.
    */
    'provider' => env('AI_PROVIDER', 'openrouter'),
    'provider_url' => env('AI_PROVIDER_URL', 'https://openrouter.ai/api/v1'),
    'provider_api_key' => env('AI_PROVIDER_API_KEY'),
    'local_llm_url' => env('AI_LOCAL_LLM_URL'),
];
