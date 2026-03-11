# Coding Conventions

**Analysis Date:** 2026-03-11

## Naming Patterns

**Files:**
- Classes use PascalCase matching the class name: `CancelProformaInvoiceAction.php`, `GeneratePaymentScheduleAction.php`
- Traits use PascalCase with descriptive prefix: `HasStateMachine.php`, `HasReference.php`, `HasDocuments.php`
- Schema classes for Filament forms: `BankAccountForm.php`, `ShipmentForm.php`, `ShipmentInfolist.php`
- Table classes for Filament: `BankAccountsTable.php`, `ShipmentsTable.php`
- Enums: PascalCase: `ProformaInvoiceStatus.php`, `PaymentScheduleStatus.php`, `CalculationBase.php`

**Classes:**
- Actions: `{Verb}{Subject}Action` — `CancelProformaInvoiceAction`, `GeneratePaymentScheduleAction`, `TransitionStatusAction`
- Services: `{Subject}{Purpose}Service` — `ProjectTeamNotificationService`, `PdfGeneratorService`
- Observers: `{Subject}Observer` — `ProjectTeamMemberObserver`
- Factories: `{Model}Factory` — `CompanyFactory`, `UserFactory`
- Filament Resources: `{Model}Resource` — `ShipmentResource`, `PaymentResource`
- Filament Schema/Table classes: `{Model}Form`, `{Model}Infolist`, `{Model}sTable`

**Methods:**
- camelCase throughout
- Actions use `execute()` as the primary public method: `$action->execute($model)`
- Secondary action methods: `regenerate()`, `cancelRelatedRecords()`
- Protected helpers in actions use descriptive verbs: `cancelPurchaseOrders()`, `waivePendingPaymentScheduleItems()`
- Filament Schema/Table classes use `public static function configure(Schema|Table $obj): Schema|Table`
- Model scopes: `scopeActive()`, `scopeForClient()`, `scopeDefault()`
- Model accessors use `get{Name}Attribute()` pattern: `getSubtotalAttribute()`, `getMarginAttribute()`

**Variables:**
- camelCase: `$paymentTerm`, `$shipmentPlanIds`, `$piItemIds`
- Short single-character names avoided except loop indices (`$i`)
- PI abbreviation used in context: `$pi`, `$po` for models representing the domain concept

**Types:**
- Enums: SCREAMING_SNAKE_CASE for cases: `DRAFT`, `CONFIRMED`, `IN_PRODUCTION`, `CANCELLED`
- Enum values: snake_case strings: `'bank_transfer'`, `'before_production'`
- Constants: SCREAMING_SNAKE_CASE: `Money::SCALE`

## Code Style

**Formatting:**
- Tool: Laravel Pint (`laravel/pint` ^1.24 in dev dependencies)
- No custom `pint.json` found — uses Laravel defaults
- 4-space indentation
- Opening braces on same line for closures, new line for class/method declarations
- Trailing commas in multi-line arrays and argument lists

**Linting:**
- No PHPStan or Rector detected
- No custom ruleset beyond Pint defaults

## Import Organization

**Order:**
1. PHP built-in/global classes (`\Carbon\Carbon`, `\BackedEnum`, `\InvalidArgumentException`)
2. Laravel/framework classes (`Illuminate\Database\Eloquent\Model`, `Illuminate\Support\Facades\DB`)
3. Third-party packages (`Spatie\Activitylog\Traits\LogsActivity`, `Filament\...`)
4. App domain classes (`App\Domain\...`)
5. App infrastructure classes (`App\Filament\...`, `App\Models\...`)

**Path Aliases:**
- None — PSR-4 autoloading only
- Two autoload roots: `App\` → `app/` and `App\Domain\` → `app/Domain/`

## Error Handling

**Patterns:**
- Invalid state transitions throw `\InvalidArgumentException` with descriptive messages including from/to status and allowed transitions
- Observers wrap notification calls in `try/catch (\Throwable $e)` and log via `Log::warning()` — errors are non-fatal
- Guard clauses return early: `if (!$paymentTermId) { return 0; }` and `if ($piItemIds->isEmpty()) { return; }`
- DB transactions used in `TransitionStatusAction::execute()` to wrap status change + audit log + side effects atomically
- No custom exception classes detected — uses built-in PHP exceptions

**Example:**
```php
// TransitionStatusAction.php
if (! $model->canTransitionTo($toStatusValue)) {
    $modelClass = class_basename($model);
    throw new \InvalidArgumentException(
        "Invalid status transition for {$modelClass}: [{$fromStatusValue}] → [{$toStatusValue}]. "
        . 'Allowed: [' . implode(', ', $model->getAllowedNextStatuses()) . ']'
    );
}
```

## Logging

**Framework:** Laravel `Log` facade (`Illuminate\Support\Facades\Log`)

**Patterns:**
- `Log::warning()` for non-fatal failures (notification delivery, background processes)
- Structured context arrays: `['member_id' => $member->id, 'error' => $e->getMessage()]`
- Not used in domain Actions — errors surface as exceptions there

## Comments

**When to Comment:**
- PHPDoc blocks on Action classes describing purpose, parameters, and exceptions
- Section dividers using `// --- Section Name ---` within model files to group relationships, accessors, scopes
- Test grouping uses `// === Group Name ===` comments
- Inline comments explaining business logic: `// Paid and already waived items are preserved.`

**PHPDoc:**
- Used on public methods in Actions with `@param`, `@return`, `@throws`
- Generic types on factories: `@extends Factory<Company>`
- Type hints in docblocks for complex intersection types: `@param Model&HasStateMachine $model`

**Example:**
```php
/**
 * Transition a model's status within a DB transaction.
 * Validates the transition, updates the status, logs the change, and executes side-effects.
 *
 * @param  Model&HasStateMachine  $model
 * @param  string|\BackedEnum  $toStatus
 * @throws \InvalidArgumentException if the transition is not allowed
 */
```

## Function Design

**Actions:**
- Single public `execute()` method as primary entry point
- Protected helper methods for sub-tasks: `cancelPurchaseOrders()`, `cancelShipmentPlans()`
- Return values: the mutated model, a count of created records (int), or void
- Parameters use named arguments style at call sites: `execute(model: $pi, toStatus: ..., notes: ...)`

**Models:**
- `casts()` method (not `$casts` property) for type casting — PHP 8.1+ style
- `$fillable` array defined on all models
- `booted()` static method for model event hooks (preferred over `boot()`)
- `allowedTransitions()` static method required on models using `HasStateMachine`
- `getDocumentType()` method required on models using `HasReference`

**Filament Schema/Table Classes:**
- Stateless classes with a single `public static function configure(Schema|Table $obj): Schema|Table`
- All labels use `__('forms.labels.key')` translation helper — no hardcoded English strings in form fields
- All section titles use `__('forms.sections.key')` translation helper

## Module Design

**Domain Modules:**
- Each domain under `app/Domain/{DomainName}/` contains: `Actions/`, `DataTransferObjects/`, `Enums/`, `Models/`, `Services/`, `Traits/` (as applicable)
- Domains reference each other via full class imports — no service locator pattern within domain logic
- `app()` helper used for IoC resolution in traits: `app(TransitionStatusAction::class)->execute(...)`

**Traits:**
- Reusable cross-cutting concerns extracted to traits in `Infrastructure/Traits/`: `HasStateMachine`, `HasReference`, `HasDocuments`
- Domain-specific traits in `Financial/Traits/`: `HasPaymentSchedule`, `HasAdditionalCosts`
- Traits declare abstract methods to enforce contracts on using models

**Exports:**
- No barrel files (index.php) — individual file imports throughout
- Models used directly; no repository pattern

## Enums

All PHP 8.1 backed string enums implementing one or more Filament contracts:
```php
enum ProformaInvoiceStatus: string implements HasLabel, HasColor
{
    case DRAFT = 'draft';
    case CONFIRMED = 'confirmed';

    public function getLabel(): ?string
    {
        return __('enums.proforma_invoice_status.' . $this->value);
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::DRAFT => 'gray',
            self::CONFIRMED => 'success',
        };
    }

    public function getEnglishLabel(): string
    {
        return match ($this) { ... };
    }
}
```
- Always implement `getLabel()` with translation key
- Always implement `getEnglishLabel()` with hardcoded English (for PDF generation)
- Implement `getColor()` when used in Filament badge columns

## Money Handling

All monetary values stored as integers (minor units with 4 decimal precision: `SCALE = 10000`).
Use `App\Domain\Infrastructure\Support\Money` for all conversions:
- `Money::toMinor(float|int|string|null): int` — convert human-readable to storage
- `Money::toMajor(int|null): float` — convert storage to human-readable
- `Money::format(int|null, int $decimals = 2): string` — format for display
- Literal minor unit values in tests use underscore notation: `100_0000` = 100.0000

---

*Convention analysis: 2026-03-11*
