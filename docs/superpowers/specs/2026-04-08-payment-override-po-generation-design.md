# Payment Override for Purchase Order Generation

**Date:** 2026-04-08
**Status:** Design approved, ready for implementation plan
**Author:** Guido Dutra (with Claude)

## Problem

Today, when a Proforma Invoice has a payment term with an upfront stage (e.g. 30% deposit), the PO cannot be generated until that stage is fully `PAID` or `WAIVED`. There is no middle ground:

- If the client confirmed payment but the wire is in transit, PO generation is blocked.
- If the client paid 25% of the required 30%, PO generation is blocked.
- The only escape hatch today is `WaivePaymentScheduleItemAction`, which marks the obligation as waived — i.e. it pretends the debt no longer exists. That is semantically wrong when the debt is real and the client is expected to pay.

The system needs a way for an authorized user to **override** the payment block — explicitly authorizing PO generation while keeping the financial obligation visible and pending in the schedule.

## Goals

1. Allow an authorized user to bypass payment blocks for PO generation **without** marking the payment as paid or waived.
2. Keep a clear audit trail of who authorized the override, when, and why.
3. Allow non-authorized users to request authorization through the existing in-app messaging system, with full context delivered to the authorizer.
4. Override is **scoped to PO generation only** — it does not unblock subsequent cycles (e.g. shipment-stage payments).
5. Override is **permanent** — no "un-authorize" mechanism.
6. Override is **per-item-snapshot** — new blocking items added after an override is granted are NOT silently authorized.

## Non-Goals

- Override for shipment / production / other downstream cycles (same pattern can be replicated later if needed).
- Reversibility of override (use existing PO cancellation flow if mistake).
- Approval-via-message (clicking a button inside the chat to authorize). The conversation is the communication channel; the act of authorizing is deliberate, on the PI page.
- Configurable "minimum % paid to unblock" thresholds.
- Splitting partially-paid items into paid+waived sub-items.

## Design

### Data Model

Add three columns to `payment_schedule_items` (mirrors the existing `waived_by` / `waived_at` / `notes` pattern):

| Column            | Type             | Notes                                  |
|-------------------|------------------|----------------------------------------|
| `overridden_by`   | `bigint nullable` | FK → `users.id`, `nullOnDelete`        |
| `overridden_at`   | `timestamp nullable` |                                    |
| `override_reason` | `text nullable`  | Min 10 chars enforced at form layer    |

**Critical:** override does NOT change the item's `status`. The item stays `PENDING` / `DUE` / `OVERDUE` so the financial obligation remains visible in collection workflows. Override is metadata of authorization, not of settlement.

**Why per-item, not per-PI:** the blocking check evaluates each `PaymentScheduleItem` independently. Marking items individually keeps the check simple (extends "is paid OR waived" to "is paid OR waived OR overridden"). It also prevents the silent-authorization edge case: if the PI is reopened later and a new blocking item is created, that new item is NOT covered by a prior override and forces a fresh authorization decision.

### Blocking Logic Changes

`app/Domain/Financial/Models/PaymentScheduleItem.php`:

**`blocksPurchaseOrderGeneration()` (line ~200):**
```php
public function blocksPurchaseOrderGeneration(): bool
{
    if (! $this->is_blocking || $this->isResolved() || $this->is_credit) {
        return false;
    }

    if ($this->overridden_at !== null) {
        return false;
    }

    return in_array($this->due_condition, [
        CalculationBase::BEFORE_PRODUCTION,
        CalculationBase::ORDER_DATE,
        CalculationBase::PO_DATE,
    ]);
}
```

**`blocksTransitionTo()` (line ~185):** apply the same `overridden_at !== null` short-circuit. Without this, the user would be able to generate the PO but get blocked one step later when transitioning the PI to `confirmed` / `in_production`.

**Scope:** the override short-circuit affects ONLY items whose `due_condition` is in `{BEFORE_PRODUCTION, ORDER_DATE, PO_DATE}`. An item with `due_condition = BEFORE_SHIPMENT` continues to block shipment normally, even if some other item on the same PI was overridden. Each unblock is for the specific cycle.

### Permission

Add to `database/seeders/PermissionSeeder.php` under `// Financial`:

```php
'override-payment-block',
```

No new role. The permission is attached to existing roles (financial-manager, admin, etc.) by the operator after seeding. The seeder is idempotent via `firstOrCreate`.

### Action Layer

Two new domain actions, both thin and reusable:

**`app/Domain/Financial/Actions/OverridePaymentBlocksAction.php`**
```php
public function execute(ProformaInvoice $pi, string $reason): int
```
- Loads all blocking-eligible items for `$pi` (via `PaymentScheduleItem::blockingPurchaseOrderGeneration($pi)`).
- For each: `update(['overridden_by' => auth()->id(), 'overridden_at' => now(), 'override_reason' => $reason])`.
- Returns the count of items overridden.
- Caller is responsible for permission check.

**`app/Domain/Financial/Actions/RequestPaymentOverrideAuthorizationAction.php`**
```php
public function execute(ProformaInvoice $pi, User $requester, array $authorizerUserIds, string $justification): Conversation
```
- Calls `MessagingService::createConversation()` with:
  - `subject = "Authorization request: PO generation for {pi->reference}"`
  - `subjectEntity = $pi`
  - `type = 'request'`
  - `participantUserIds = $authorizerUserIds` (creator added automatically)
- Calls `MessagingService::sendMessage()` with a body that includes:
  - The requester's justification
  - A bullet list of blocking items (label + amount + status)
  - A reference token like `@PI-2026-00123` (the existing `ReferenceResolver` renders it as a clickable chip)
- Returns the created conversation.

### Filament UI Changes

Edit `app/Filament/Resources/ProformaInvoices/Concerns/ProformaInvoiceHeaderActions.php::generatePurchaseOrdersAction()`.

The current modal shows blockers and a disabled-feeling "cannot generate POs" message. Replace with two branches based on the authenticated user's permissions:

**Branch A — user has `override-payment-block`:**

When blockers are present, the modal additionally shows:
- A list of the blocking items (label, amount, current status)
- `Checkbox::make('override_payment_block')` → "Authorize PO generation despite pending payments"
- `Textarea::make('override_reason')` → "Override reason", `visible(fn (Get $get) => $get('override_payment_block'))`, `requiredIf('override_payment_block', true)`, `minLength(10)`

Submit handler:
1. If `override_payment_block` is checked → call `OverridePaymentBlocksAction` with the reason.
2. Re-check blockers (defensive — should be empty now).
3. Call `GeneratePurchaseOrdersAction::execute($pi)` as today.
4. Notification of success mentions "Generated with payment override by {user}".

**Branch B — user does NOT have `override-payment-block`:**

When blockers are present, the modal shows:
- The list of blocking items
- An alternative button: **"Request authorization"**

Clicking "Request authorization" opens a sub-form (Filament nested action or replaces the modal content):
- `Select::make('authorizer_ids')` → multi-select, populated by `User::permission('override-payment-block')->get()`. If the result is empty, the select is omitted and the modal shows "No users with payment override permission found — contact your administrator". The Send button is disabled in that case.
- `Textarea::make('justification')` → required, `minLength(10)`
- Submit → call `RequestPaymentOverrideAuthorizationAction`. Notification: "Authorization request sent to :names".

The "Generate" button itself stays disabled in Branch B.

### Audit Trail

`PaymentScheduleItem` already uses the `LogsActivity` trait. Adding the three new columns to `$fillable` makes them automatically tracked in the activity log. No custom audit code needed.

The conversation created in Branch B is itself a permanent record of the request and any back-and-forth before authorization.

### i18n

Add to `lang/en/messages.php` (and `lang/pt_BR/messages.php` for parity):

| Key                                | en                                                                  |
|------------------------------------|---------------------------------------------------------------------|
| `override_payment_block`           | Authorize PO generation despite pending payments                    |
| `override_reason`                  | Override reason                                                     |
| `request_authorization`            | Request authorization                                               |
| `authorization_request_sent`       | Authorization request sent to :names                                |
| `no_authorized_users`              | No users with payment override permission found                     |
| `authorization_request_subject`    | Authorization request: PO generation for :reference                 |
| `generated_with_payment_override`  | POs generated with payment override                                 |

## Testing

### Unit (`tests/Unit/Financial/`)

- `PaymentScheduleItemTest::overridden_item_does_not_block_po_generation`
- `PaymentScheduleItemTest::overridden_item_does_not_block_status_transition`
- `PaymentScheduleItemTest::overridden_item_keeps_pending_status_after_override`
- `PaymentScheduleItemTest::overridden_item_for_before_shipment_still_blocks_shipment` (verifies cycle scoping)
- `OverridePaymentBlocksActionTest::marks_all_blocking_items_with_user_and_reason`
- `OverridePaymentBlocksActionTest::skips_already_resolved_items` (paid / waived)
- `OverridePaymentBlocksActionTest::skips_non_blocking_items`
- `OverridePaymentBlocksActionTest::skips_credit_items`

### Feature (`tests/Feature/ProformaInvoices/`)

- `GeneratePurchaseOrdersActionTest::generates_pos_when_blockers_are_overridden`
- `OverridePoBlockActionTest::user_with_permission_can_override_and_generate`
- `OverridePoBlockActionTest::override_requires_reason_min_10_chars`
- `OverridePoBlockActionTest::user_without_permission_sees_request_authorization_button`
- `OverridePoBlockActionTest::request_authorization_creates_conversation_with_blockers_and_justification`
- `OverridePoBlockActionTest::request_authorization_lists_only_users_with_permission`
- `OverridePoBlockActionTest::request_authorization_disabled_when_no_authorized_users_exist`
- `OverridePoBlockActionTest::new_blocking_item_added_after_override_is_not_silently_authorized` (key edge case)

## File Manifest

**New:**
- `database/migrations/YYYY_MM_DD_add_override_to_payment_schedule_items.php`
- `app/Domain/Financial/Actions/OverridePaymentBlocksAction.php`
- `app/Domain/Financial/Actions/RequestPaymentOverrideAuthorizationAction.php`
- Test files listed above

**Edited:**
- `app/Domain/Financial/Models/PaymentScheduleItem.php` — `$fillable`, `$casts`, `blocksPurchaseOrderGeneration()`, `blocksTransitionTo()`
- `app/Filament/Resources/ProformaInvoices/Concerns/ProformaInvoiceHeaderActions.php` — `generatePurchaseOrdersAction()` adds Branch A / Branch B logic
- `database/seeders/PermissionSeeder.php` — add `override-payment-block`
- `lang/en/messages.php` — add new keys
- `lang/pt_BR/messages.php` — add parity translations

## Open Questions

None at design time. Implementation plan to be created next.
