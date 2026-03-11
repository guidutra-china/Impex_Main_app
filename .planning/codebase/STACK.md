# Technology Stack

**Analysis Date:** 2026-03-11

## Languages

**Primary:**
- PHP 8.2+ - All backend logic, models, controllers, domain code
- JavaScript (ESM) - Frontend asset bundling entry points (`resources/js/app.js`)
- CSS - Tailwind-powered stylesheets (`resources/css/app.css`, panel-specific theme files)

**Secondary:**
- Blade (PHP templating) - Views under `resources/views/`, PDF templates rendered via DomPDF

## Runtime

**Environment:**
- PHP 8.2+ (required by `composer.json` constraint `^8.2`)
- Node.js (version unspecified; no `.nvmrc` or `.tool-versions`)

**Package Manager:**
- PHP: Composer (lockfile `composer.lock` present)
- JS: pnpm (lockfile `pnpm-lock.yaml` present; `package-lock.json` also present but secondary)
- `pnpm-workspace.yaml` restricts only `esbuild` as a pre-built dependency

## Frameworks

**Core:**
- Laravel 12.x (`laravel/framework ^12.0`) - Full-stack PHP framework; handles routing, ORM, queues, mail, notifications

**Admin UI:**
- Filament 4.x (`filament/filament ^4.0`) - Full-featured admin panel framework built on Livewire/Alpine.js. Powers four separate panels:
  - `admin` → `/panel` (`app/Providers/Filament/AdminPanelProvider.php`)
  - `portal` → `/portal` — client-facing tenant panel (`app/Providers/Filament/PortalPanelProvider.php`)
  - `supplier-portal` → `/supplier` — supplier-facing tenant panel (`app/Providers/Filament/SupplierPortalPanelProvider.php`)
  - `fair` → `/fair` — trade-fair registration panel (`app/Providers/Filament/FairPanelProvider.php`)

**Build/Dev:**
- Vite 7.x (`vite ^7.0.7`) - Asset bundler, configured in `vite.config.js`
- `laravel-vite-plugin ^2.0.0` - Laravel/Vite integration
- Tailwind CSS 4.x (`tailwindcss ^4.0.0`, `@tailwindcss/vite ^4.0.0`)
- `concurrently ^9.0.1` - Runs server, queue worker, log watcher, and Vite in parallel during dev

**Testing:**
- PHPUnit 11.x (`phpunit/phpunit ^11.5.3`) - Configured in `phpunit.xml`
- FakerPHP `^1.23` - Test data generation
- Mockery `^1.6` - Mocking
- Laravel Pint `^1.24` - Code style fixer (dev tool)

## Key Dependencies

**Critical:**
- `filament/filament ^4.0` - All UI is built through Filament resources, pages, and widgets; removing this would require rewriting the entire interface
- `filament/spatie-laravel-settings-plugin ^4.0` - Connects Filament to Spatie settings; used for company-wide config (`CompanySettings`, `app/Domain/Settings/DataTransferObjects/CompanySettings.php`)
- `bezhansalleh/filament-shield ^4.0` - Role/permission management UI within Filament (`app/Filament/Resources/Settings/Roles/`)

**Infrastructure:**
- `spatie/laravel-settings ^3.7` - DB-backed application settings (group `company`); accessed via `CompanySettings::class`
- `spatie/laravel-activitylog ^4.12` - Audit trail; configured in `config/activitylog.php`; logs stored in `activity_log` table
- `brick/money ^0.11.1` - Precise monetary arithmetic; wrapped by `App\Domain\Infrastructure\Support\Money`
- `barryvdh/laravel-dompdf ^3.1` - PDF generation; used via `App\Domain\Infrastructure\Pdf\PdfRenderer` and `PdfGeneratorService`
- `openspout/openspout ^4.32` - Excel/spreadsheet import/export (product imports, pasting items from spreadsheet)
- `resend/resend-laravel ^1.3` - Transactional email delivery via Resend API
- `laravel/tinker ^2.10.1` - REPL for development

**Role & Permission:**
- Spatie Permission (bundled via `filament-shield`) - `HasRoles` trait on `User` model; roles/permissions managed via Filament Shield UI

## Configuration

**Environment:**
- `.env.example` present; production requires `.env` with all secrets
- Default DB driver in `.env.example` is `mysql` (`DB_CONNECTION=mysql`, database name `trading_app`)
- Queue driver defaults to `database` (`QUEUE_CONNECTION=database`)
- Cache store defaults to `database` (`CACHE_STORE=database`)
- Session driver defaults to `database` (`SESSION_DRIVER=database`)
- Mail transport defaults to `resend` (`MAIL_MAILER=resend`)
- Filesystem disk defaults to `local`

**Key env vars required for production:**
```
APP_KEY          # Laravel app encryption key
DB_CONNECTION    # mysql (default in .env.example)
DB_HOST / DB_PORT / DB_DATABASE / DB_USERNAME / DB_PASSWORD
RESEND_API_KEY   # For transactional email
OPENAI_API_KEY   # For trade fair business card scanning
AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY / AWS_BUCKET  # If using S3 file storage
REDIS_HOST / REDIS_PORT  # If using Redis for cache/queue
```

**Build:**
- `vite.config.js` - Defines four CSS entry points (per-panel themes) plus `resources/js/app.js`
- `phpunit.xml` - Test environment uses SQLite in-memory (`DB_DATABASE=:memory:`)

## Platform Requirements

**Development:**
- PHP 8.2+, Composer, Node.js + pnpm
- MySQL (default per `.env.example`) or SQLite for local dev
- Run `composer run dev` to start all services concurrently (server, queue, log watcher, Vite)

**Production:**
- PHP 8.2+ web server (Apache/Nginx + PHP-FPM)
- MySQL database
- Queue worker process (`php artisan queue:listen` or Horizon)
- Optional: Redis for cache/queue (config supports both database and Redis drivers)
- Optional: AWS S3 for file storage (local disk used by default)

---

*Stack analysis: 2026-03-11*
