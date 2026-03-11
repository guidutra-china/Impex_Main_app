# Testing Patterns

**Analysis Date:** 2026-03-11

## Test Framework

**Runner:**
- PHPUnit 11.5.x
- Config: `phpunit.xml` at project root

**Assertion Library:**
- PHPUnit built-in assertions (`assertEquals`, `assertCount`, `assertDatabaseHas`, `assertTrue`, `assertNull`, `assertEqualsWithDelta`)
- No Pest — plain PHPUnit test classes only

**Run Commands:**
```bash
composer test              # Clears config cache then runs full suite
php artisan test           # Equivalent direct artisan command
php artisan test --filter=ClassName        # Run specific test class
php artisan test --filter=test_method_name # Run single test
```

## Test File Organization

**Location:**
- Separate `tests/` directory, not co-located with source
- `tests/Unit/` — pure logic, no database
- `tests/Feature/` — integration tests, uses database

**Naming:**
- Test class files: `{Subject}Test.php`
- Test method names: snake_case with `test_` prefix: `test_cancel_transitions_pi_to_cancelled()`
- No `@test` annotation — method name prefix is the convention

**Structure:**
```
tests/
├── TestCase.php              # Base class extending Laravel's TestCase
├── Unit/
│   ├── ExampleTest.php
│   ├── MoneyTest.php                          # Pure static utility tests
│   ├── PaymentScheduleTest.php                # Pure math/logic tests
│   └── CommercialInvoicePricingTest.php       # Uses RefreshDatabase despite being in Unit/
└── Feature/
    ├── ExampleTest.php
    ├── CancelProformaInvoiceActionTest.php    # Action integration tests
    └── GeneratePaymentScheduleActionTest.php  # Action integration tests
```

## Test Structure

**Suite Organization:**
```php
class CancelProformaInvoiceActionTest extends TestCase
{
    use RefreshDatabase;

    // Typed private properties for shared state
    private CancelProformaInvoiceAction $action;
    private Company $clientCompany;
    private Inquiry $inquiry;

    // setUp instantiates action + creates minimal shared records
    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new CancelProformaInvoiceAction();
        $this->clientCompany = Company::create([...]);
    }

    // Private factory helpers for test data creation
    private function createPI(string $status = 'confirmed'): ProformaInvoice { ... }
    private function createPOForPI(ProformaInvoice $pi, string $status = 'draft'): PurchaseOrder { ... }

    // Test grouping comments
    // === Tests ===

    public function test_cancel_transitions_pi_to_cancelled(): void { ... }
    public function test_cancel_cancels_draft_purchase_orders(): void { ... }
}
```

**Patterns:**
- `setUp()` creates shared fixtures used across many tests, keeping individual tests concise
- Private helper methods (`createPI()`, `createPOForPI()`, `createShipmentPlanForPI()`) encapsulate object creation
- Helper methods accept status strings as parameters to vary state: `createPI('draft')`, `createPI('confirmed')`
- `->refresh()` called on models after action execution to reload from database
- Group comments use `// === Section Name ===` to organize related tests within a class

## Mocking

**Framework:** `mockery/mockery` ^1.6 is installed as a dev dependency, but no mocks are used in existing tests.

**Current Approach:** No mocking. All tests use real model instances with `RefreshDatabase`. Actions are instantiated directly with `new ActionClass()` or resolved via `new GeneratePaymentScheduleAction()`.

**What to Mock (guideline for new tests):**
- External services (email sending, file storage, third-party HTTP calls)
- `auth()` facade when testing user-specific behavior without a full user session

**What NOT to Mock:**
- Eloquent models — use real database with `RefreshDatabase`
- Domain actions — instantiate directly
- Laravel facades that have test doubles built-in (Mail, Storage, etc.) — use their built-in faking

## Fixtures and Factories

**Two approaches are used:**

**1. Inline `Model::create()` in tests (dominant pattern):**
```php
// In setUp() or private helpers
$this->company = Company::create([
    'name' => 'Test Company',
    'status' => 'active',
]);
$this->company->companyRoles()->create(['role' => 'client']);

$this->inquiry = Inquiry::create([
    'reference' => 'INQ-TEST-001',
    'company_id' => $this->company->id,
    'status' => 'received',
    'source' => 'email',
    'currency_code' => 'USD',
]);
```

**2. Eloquent Factories (available but not used in current tests):**
- `database/factories/CompanyFactory.php` — with `->supplier()` and `->client()` states
- `database/factories/ContactFactory.php`
- `database/factories/UserFactory.php`
- `database/factories/ProductFactory.php`

Factory states are defined for domain-specific scenarios:
```php
Company::factory()->supplier()->create();
Company::factory()->client()->create();
Company::factory()->prospect()->create();
```

**Location:** `database/factories/` — not inside domain directories.

**Test data helpers:** Private methods within test classes:
```php
private function createPaymentTerm(array $stages): PaymentTerm
{
    $term = PaymentTerm::create(['name' => 'Test Term', 'is_active' => true]);
    foreach ($stages as $i => $stage) {
        PaymentTermStage::create(array_merge([
            'payment_term_id' => $term->id,
            'sort_order' => $i + 1,
        ], $stage));
    }
    return $term;
}
```

## Database Configuration

**Testing database:** SQLite in-memory (`:memory:`)

```xml
<!-- phpunit.xml -->
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
<env name="APP_ENV" value="testing"/>
<env name="CACHE_STORE" value="array"/>
<env name="QUEUE_CONNECTION" value="sync"/>
<env name="SESSION_DRIVER" value="array"/>
```

**Trait:** `RefreshDatabase` — used in all Feature tests and in `CommercialInvoicePricingTest` (Unit)

**Note:** `CommercialInvoicePricingTest` extends `Tests\TestCase` (Laravel base) and uses `RefreshDatabase` despite being in `tests/Unit/`. Pure Unit tests (`MoneyTest`, `PaymentScheduleTest`) extend `PHPUnit\Framework\TestCase` directly, skipping the Laravel bootstrap entirely.

## Coverage

**Requirements:** Not enforced — no coverage thresholds configured in `phpunit.xml`

**Source coverage scope:** `app/` directory (configured in `phpunit.xml` `<source>`)

**View Coverage:**
```bash
php artisan test --coverage
php artisan test --coverage --min=80   # Not currently enforced
```

## Test Types

**Unit Tests (`tests/Unit/`):**
- Target pure calculation logic and static utility classes
- `MoneyTest` — tests `Money::toMinor()`, `Money::toMajor()`, `Money::format()` with many edge cases
- `PaymentScheduleTest` — tests percentage splits, rounding, due date arithmetic, payment allocation logic
- No database, no Laravel bootstrapping for pure unit tests (extend `PHPUnit\Framework\TestCase`)
- `CommercialInvoicePricingTest` is nominally Unit but uses database (a placement inconsistency)

**Feature Tests (`tests/Feature/`):**
- Test Action classes end-to-end with real database and Eloquent models
- Verify database state after action execution using `->refresh()` and `assertDatabaseHas()`
- Test state machine transitions (valid and invalid)
- Test cascading effects: cancelling a PI cancels related POs, ShipmentPlans, PaymentScheduleItems
- Test idempotency: running an action twice does not create duplicate records

**E2E Tests:** Not used.

## Common Patterns

**Testing state transitions:**
```php
public function test_cancel_transitions_pi_to_cancelled(): void
{
    $pi = $this->createPI('confirmed');
    $result = $this->action->execute($pi, 'Client withdrew.');
    $this->assertEquals(ProformaInvoiceStatus::CANCELLED, $result->status);
}
```

**Testing invalid transitions (expect exception):**
```php
public function test_cannot_cancel_finalized_pi(): void
{
    $pi = $this->createPI('finalized');
    $this->expectException(\InvalidArgumentException::class);
    $this->action->execute($pi);
}
```

**Testing cascading effects with refresh:**
```php
public function test_cancel_cancels_draft_purchase_orders(): void
{
    $pi = $this->createPI('confirmed');
    $po = $this->createPOForPI($pi, 'draft');

    $this->action->execute($pi);

    $po->refresh();
    $this->assertEquals(PurchaseOrderStatus::CANCELLED, $po->status);
}
```

**Testing database state:**
```php
$this->assertDatabaseHas('state_transitions', [
    'model_type' => ProformaInvoice::class,
    'model_id' => $pi->id,
    'from_status' => 'confirmed',
    'to_status' => 'cancelled',
]);
```

**Testing collections with closures:**
```php
$this->assertTrue($items->every(fn ($item) => $item->status === PaymentScheduleStatus::WAIVED));
```

**Testing numeric precision:**
```php
$this->assertEqualsWithDelta($total, $part1 + $part2 + $part3, 1); // allow 1 minor unit
$this->assertEqualsWithDelta(33.33, $marginPercent, 0.01);         // allow 0.01%
```

**Testing with unique references:**
```php
// Use uniqid() to prevent constraint violations when creating multiple records:
'reference' => 'PI-TEST-' . uniqid(),
```

---

*Testing analysis: 2026-03-11*
