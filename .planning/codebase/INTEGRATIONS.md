# External Integrations

**Analysis Date:** 2026-03-11

## APIs & External Services

**AI / Vision:**
- OpenAI GPT-4.1-mini (Chat Completions API with vision) — scans business card photos at trade fairs and auto-fills supplier registration form fields
  - SDK/Client: Laravel HTTP client (`Illuminate\Support\Facades\Http`) — direct REST call, no dedicated SDK
  - Auth: `OPENAI_API_KEY` via `config('services.openai.key')` (`config/services.php`)
  - Usage: `app/Filament/Fair/Pages/RegisterAtFair.php` — method `scanBusinessCard()`
  - Endpoint: `https://api.openai.com/v1/chat/completions`

**Email Delivery:**
- Resend — transactional email (document delivery, fair inquiry notifications)
  - SDK/Client: `resend/resend-laravel ^1.3`
  - Auth: `RESEND_API_KEY` via `config('services.resend.key')` (`config/services.php`)
  - Default mailer in `.env.example`: `MAIL_MAILER=resend`
  - From address configured as `noreply@impex.ltd`
  - Mail classes: `app/Mail/DocumentMail.php`, `app/Mail/FairInquiryMail.php`

## Data Storage

**Databases:**
- MySQL (primary, per `.env.example`)
  - Connection vars: `DB_HOST`, `DB_PORT`, `DB_DATABASE` (`trading_app` default), `DB_USERNAME`, `DB_PASSWORD`
  - ORM/Client: Laravel Eloquent (included in `laravel/framework`)
  - Supports MariaDB and PostgreSQL configurations also defined in `config/database.php`
- SQLite — used in test environment only (`phpunit.xml`: `DB_DATABASE=:memory:`)

**Queue Storage:**
- Database queue (default driver: `QUEUE_CONNECTION=database`, table `jobs`)
- Redis queue driver also configured (`config/queue.php`) but not default

**Cache Storage:**
- Database cache (default driver: `CACHE_STORE=database`, table `cache`)
- Redis cache driver also configured but not default

**Session Storage:**
- Database sessions (default: `SESSION_DRIVER=database`)

**File Storage:**
- Local filesystem (default: `FILESYSTEM_DISK=local`, stored under `storage/app/private`)
- Public disk: `storage/app/public` (symlinked to `public/storage`)
- AWS S3 disk configured in `config/filesystems.php` but not the default
  - Auth: `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`
  - Controlled by `FILESYSTEM_DISK=s3` env var to activate

**Activity Logging:**
- Spatie Laravel Activitylog (`spatie/laravel-activitylog ^4.12`) — writes to `activity_log` table
  - Config: `config/activitylog.php` — records retained 365 days
  - Enabled via `ACTIVITY_LOGGER_ENABLED` (default `true`)

## Authentication & Identity

**Auth Provider:**
- Custom (Laravel session-based authentication, no external identity provider)
  - Implementation: Laravel `Authenticate` guard with Eloquent user provider
  - User model: `app/Models/User.php`
  - Auth configured in `config/auth.php`; default guard is `web` (session)

**Authorization:**
- Spatie Laravel Permission (via `bezhansalleh/filament-shield ^4.0`)
  - `User` model uses `HasRoles` trait
  - Roles and permissions managed via Filament Shield UI at `/panel`
  - Permission tables: `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`
  - Config: `config/permission.php`

**Multi-Tenancy (Filament):**
- Filament built-in tenant isolation using `Company` as tenant model
  - Portal panel (`/portal`): CLIENT users scoped to their `Company`
  - Supplier Portal panel (`/supplier`): SUPPLIER users scoped to their `Company`
  - Admin panel (`/panel`): internal staff only, no tenancy
  - Fair panel (`/fair`): internal staff only, no tenancy

**Panel Access Control:**
- `User::canAccessPanel()` in `app/Models/User.php` enforces user type (`INTERNAL`, `CLIENT`, `SUPPLIER`) and active status per panel

## Monitoring & Observability

**Error Tracking:**
- Not detected (no Sentry, Bugsnag, or Flare SDK in `composer.json`)

**Logs:**
- Monolog via Laravel logging (`config/logging.php`)
- Default channel: `stack` → `single` (writes to `storage/logs/laravel.log`)
- Slack log channel configured (webhook URL via `LOG_SLACK_WEBHOOK_URL`) — critical level only
- Papertrail channel configured but not active by default
- Dev tool: Laravel Pail (`laravel/pail ^1.2.2`) for real-time log tailing during dev

**Application Settings:**
- Spatie Laravel Settings (`spatie/laravel-settings ^3.7`) — DB-backed settings stored in `settings` table
  - Company settings group: `app/Domain/Settings/DataTransferObjects/CompanySettings.php`
  - Managed via Filament settings plugin

## CI/CD & Deployment

**Hosting:**
- Not detected (no Forge, Vapor, Railway, Heroku, or similar config files present)

**CI Pipeline:**
- Not detected (no `.github/workflows/`, `.gitlab-ci.yml`, or similar)

**Dev Workflow:**
- `composer run setup` — installs deps, generates app key, runs migrations, builds assets
- `composer run dev` — starts PHP server, queue worker, log watcher, and Vite via `concurrently`
- `composer run test` — clears config cache then runs PHPUnit

## Environment Configuration

**Required env vars (production):**
```
APP_KEY
APP_URL
DB_CONNECTION=mysql
DB_HOST
DB_PORT
DB_DATABASE
DB_USERNAME
DB_PASSWORD
MAIL_MAILER=resend
MAIL_FROM_ADDRESS
RESEND_API_KEY
OPENAI_API_KEY        # Required for trade fair business card scan feature
```

**Optional env vars:**
```
AWS_ACCESS_KEY_ID
AWS_SECRET_ACCESS_KEY
AWS_DEFAULT_REGION
AWS_BUCKET            # Set FILESYSTEM_DISK=s3 to use S3
REDIS_HOST
REDIS_PORT
REDIS_PASSWORD        # Set CACHE_STORE=redis / QUEUE_CONNECTION=redis to activate
LOG_SLACK_WEBHOOK_URL # For Slack critical-error log channel
ACTIVITY_LOGGER_ENABLED=false  # Disable audit trail if needed
```

**Secrets location:**
- `.env` file at project root (never committed; `.env.example` is the template)

## Webhooks & Callbacks

**Incoming:**
- None detected — no webhook receiver routes or controllers found

**Outgoing:**
- OpenAI API call from `app/Filament/Fair/Pages/RegisterAtFair.php` (HTTP POST, not a persistent webhook)
- Resend email delivery (fire-and-forget via Laravel Mailer)

---

*Integration audit: 2026-03-11*
