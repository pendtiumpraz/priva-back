# PRIVASIMU - Backend

**Enterprise-Ready Privacy Hub**
Laravel 12 / PHP 8.3 Core for the PRIVASIMU Ecosystem.

## 🚀 Architecture
- **Multi-Tenant (SaaS)**: Core tables isolate functionality out-of-the-box using UUIDs and `org_id` schemas.
- **Relational Integrity**: Built on robust PostgreSQL/MySQL for audit-proof record keeping of processing activities.
- **Wizard JSON Persistence**: Flexible metadata mapping where `wizard_data` JSON segments structure highly complex schemas.
- **AI Agent Executor**: First native class (`AiAgentToolExecutor`) bridging Open Router intelligence direct into the compliance lifecycle via server-side invocation.
- **Audit Logging Center**: Zero-alteration History Tracking for all actors (Human + AI) with soft-deletion standards.

## 🛠️ Stack
- **Framework**: [Laravel 12](https://laravel.com) / PHP 8.3
- **Database**: PostgreSQL (Development / App Sandbox), MySQL (Deployment) 
- **Dependencies**: DeepSeek (AI models), GuzzleHTTP
- **Authentication**: Laravel Sanctum API Token
- **State Mgmt**: Cache/Redis readiness 

## 🏎️ Getting Started

Setup the development database and migrate:
```bash
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

### Core Logic Notes:
- **`AiAgentToolExecutor`**: Central endpoint interpreting intelligent agent queries into database CRUD. Any tool the agent calls runs securely here.
- **`ModuleCrudController`**: Generic controller managing ROPA, DPIA, Breach, DSR dynamically.
- **Cross-Service Validations**: Core licensing validations are forwarded implicitly when `organization` states change via `SuperAdmins`.

## 🛡️ License
Private Source. Only valid under PRIVASIMU B2B Agreements.
