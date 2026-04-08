# Default Email Message in Send Modal

**Date:** 2026-04-08
**Status:** Approved for implementation planning
**Author:** brainstormed with user

## Problem

When users click "Send by Email" across the system (quotations, purchase orders, proforma invoices, RFQs, packing lists, commercial invoices, fair inquiries), the modal opens with an empty optional "message" textarea. The real default text ("Please find the attached document for your reference.") is hardcoded inside the Blade template `resources/views/emails/document.blade.php` and only materializes in the final email *if the user left the textarea blank*.

This has two practical problems:
1. Users who want to *add* to the default text must retype it from scratch.
2. Users never *see* what will actually be sent, because the default lives in the template, not in the UI.

## Goal

Pre-populate the message textarea in every user-initiated "Send by Email" modal with a document-type-specific default text, configurable by admins in Company Settings, with support for placeholder variables that resolve against the current record at the moment the modal opens.

## Out of scope

- The four automatic project notifications emitted by `ProjectNotificationService` (milestone approval requested, milestone approved, milestone revision requested, new project message). The Projects resource is not production-ready yet and these fire without any UI modal.
- Any BCC-to-archive feature. Was considered early but dropped once the user confirmed Projects isn't ready.
- Subject-line editing in the modal. Subjects today are auto-generated from the document reference; out of scope for this iteration.
- Multi-language template storage. Initial template values are written in English, matching current system convention.

## Inventory of affected send points

All user-initiated email entry points in the system, confirmed exhaustive via codebase grep of `Mail::(to|send|queue|later)` and `new \w+Mail\(`:

**Group A — `SendDocumentByEmailAction` (8 usages, all share one Action class):**

| # | Callsite | Document type | Settings key |
|---|---|---|---|
| 1 | `QuotationHeaderActions.php:49` | Quotation PDF | `email_default_message_quotation` |
| 2 | `PurchaseOrderHeaderActions.php:50` | Purchase Order PDF | `email_default_message_purchase_order` |
| 3 | `ProformaInvoiceHeaderActions.php:71` | Proforma Invoice PDF | `email_default_message_proforma_invoice` |
| 4 | `SupplierQuotationHeaderActions.php:70` | RFQ PDF | `email_default_message_rfq` |
| 5 | `SupplierQuotationHeaderActions.php:74` | RFQ Excel | `email_default_message_rfq` *(shared with #4)* |
| 6 | `ShipmentHeaderActions.php:54` | Packing List PDF | `email_default_message_packing_list` |
| 7 | `ShipmentHeaderActions.php:74` | Commercial Invoice PDF | `email_default_message_commercial_invoice` |

**Group B — `RegisterAtFair` wizard (1 usage, separate Mailable):**

| # | Callsite | Document type | Settings key |
|---|---|---|---|
| 8 | `RegisterAtFair.php:864` | Fair Inquiry | `email_default_message_fair_inquiry` |

Total: **7 settings keys** (RFQ PDF and RFQ Excel share one), **8 send points touched**.

## Architecture

Changes span four layers. All are additive except the Blade simplification, which removes the now-redundant `@if/@else` fallback.

### Layer 1 — Configuration: `CompanySettings`

Add 7 nullable string properties to `app/Domain/Settings/DataTransferObjects/CompanySettings.php`:

```php
public ?string $email_default_message_quotation;
public ?string $email_default_message_purchase_order;
public ?string $email_default_message_proforma_invoice;
public ?string $email_default_message_rfq;
public ?string $email_default_message_packing_list;
public ?string $email_default_message_commercial_invoice;
public ?string $email_default_message_fair_inquiry;
```

Settings migration in `database/settings/` adds each key with an English default value (see "Initial template values" below).

Nullable was chosen deliberately: admins can clear a field to restore the pre-feature behavior (textarea opens blank) without deleting the column.

### Layer 2 — Placeholder resolver

New stateless service: `app/Domain/Infrastructure/Services/EmailMessagePlaceholderResolver.php`.

```php
class EmailMessagePlaceholderResolver
{
    public function resolve(?string $template, array $context): string
    {
        if ($template === null || $template === '') {
            return '';
        }

        $resolved = $template;
        foreach ($context as $key => $value) {
            $resolved = str_replace('{' . $key . '}', (string) ($value ?? ''), $resolved);
        }

        // Collapse any double spaces left by empty-value substitutions.
        return trim(preg_replace('/ {2,}/', ' ', $resolved));
    }
}
```

Design decisions:
- **Unknown placeholders stay literal.** If the admin typos `{recepient_name}`, the user sees `{recepient_name}` in the modal on first use and can go fix the settings. Validating at save time is overkill for v1.
- **Missing context values become empty strings**, not literal `{variable}`. Prevents leaking template syntax to the end customer.
- **Double-space collapse** after substitution handles the common case of `"attached {reference} from"` becoming `"attached  from"` when the record has no reference yet.
- **Stateless and unit-testable in isolation** — no Eloquent, no service locator, no config lookups inside.

### Layer 3 — UI: `SendDocumentByEmailAction`

Extend `make()` with a new required parameter `string $settingsKey`:

```php
public static function make(
    string $documentType,
    string $settingsKey,
    string $label = 'Send by Email',
    string $icon = 'heroicon-o-envelope',
    ?string $name = null,
): Action
```

In `buildForm($record)`, the `message` Textarea gains a resolved default:

```php
Textarea::make('message')
    ->label(__('forms.labels.message_optional'))
    ->default(static::resolveDefaultMessage($settingsKey, $record))
    ->helperText(static::placeholderHelpText())
    ->rows(6),
```

Where `resolveDefaultMessage()`:
1. Loads `CompanySettings` via `app(CompanySettings::class)`.
2. Reads `$settings->{$settingsKey}` (raw template or null).
3. Builds context from `$record` via a private `buildPlaceholderContext($record)` method:
   - `recipient_name` ← `$contact?->name ?? $company?->name ?? ''`
   - `company_name` ← resolved via existing `resolveCompany($record)->name`
   - `reference` ← `$record->reference ?? ''`
   - `document_name` ← `$record->getLatestDocument($documentType)?->name ?? ''`
4. Calls `app(EmailMessagePlaceholderResolver::class)->resolve($template, $context)`.
5. Returns the resolved string.

`placeholderHelpText()` returns a short helper string listing the four supported variables, so users understand the defaults can be edited but also why the text looks personalized.

All 7 Group A callsites are updated to pass the matching `settingsKey`. Example:

```php
// PurchaseOrderHeaderActions.php
SendDocumentByEmailAction::make(
    documentType: 'purchase_order',
    settingsKey: 'email_default_message_purchase_order',
)
```

### Layer 3b — UI: `RegisterAtFair` wizard

The Fair Inquiry form has its own wizard schema (not using `SendDocumentByEmailAction`). The `email_message` Textarea at `RegisterAtFair.php:~887` is updated to compute a default the same way, but with a Fair-specific context:

- `recipient_name` ← resolved from supplier contact
- `company_name` ← supplier company name
- `trade_fair_name` ← fair name from wizard state
- `product_names` ← comma-joined list of selected product names

The resolver service is agnostic to context keys, so no changes to Layer 2 are needed — only the callsite decides what variables to pass.

### Layer 4 — Blade template cleanup

`resources/views/emails/document.blade.php` currently:

```blade
@if($customMessage)
{{ $customMessage }}
@else
Please find the attached document for your reference.
@endif
```

Becomes:

```blade
@if($customMessage)
{{ $customMessage }}
@endif
```

The `@else` fallback is removed because the textarea is now always pre-populated. The outer `@if` is preserved as a safety net: if an admin deliberately clears a settings template and a user sends without typing anything, the email still renders cleanly (greeting + document name + signature), just without a custom paragraph. No new hardcoded fallback text.

### Layer 5 — Company Settings UI

`app/Filament/Pages/ManageCompanySettings.php` gets a new Filament form Section titled "Email Templates" (or equivalent), containing 7 Textareas bound to the new settings properties. Each Textarea has a `helperText` listing the supported placeholder variables for that template type (the Fair Inquiry one lists different variables than the document templates).

Rows: 4 per Textarea, sufficient for a paragraph or two.

## Data flow

### Admin configures templates (one-time setup)

1. Admin opens `/admin/company-settings` → Email Templates section.
2. Edits `Purchase Order default email message`:
   ```
   Dear {recipient_name}, please find attached PO {reference} from {company_name}.
   Kindly confirm receipt and expected delivery date.
   ```
3. Saves. `spatie/laravel-settings` persists to the `settings` table (group=`company`).

### User clicks "Send by Email" on a Purchase Order

1. `SendDocumentByEmailAction::buildForm($record)` is invoked by Filament.
2. Loads `CompanySettings`, reads `email_default_message_purchase_order` → raw template.
3. Builds context from `$record`:
   - `recipient_name` = "John Silva"
   - `company_name` = "Acme Corp"
   - `reference` = "PO-2026-0042"
   - `document_name` = "PO-2026-0042.pdf"
4. Resolver substitutes placeholders → final text.
5. Textarea `->default(finalText)`.
6. Modal renders with the textarea already containing:
   ```
   Dear John Silva, please find attached PO PO-2026-0042 from Acme Corp.
   Kindly confirm receipt and expected delivery date.
   ```

### User edits (or doesn't) and sends

1. User optionally tweaks the text.
2. Submits the form. `$data['message']` contains whatever is in the textarea (resolved default, or edited version).
3. Passed unchanged to `DocumentMail::customMessage`.
4. Blade renders `{{ $customMessage }}`. No more `@if/@else` default branch.
5. Email sends.

### Edge case: admin clears a template

1. Admin leaves `email_default_message_quotation` empty/null.
2. Resolver receives null → returns `''`.
3. Textarea opens blank (matches pre-feature behavior).
4. User can type freely or leave it blank.
5. If blank, Blade `@if($customMessage)` is false → no custom paragraph is rendered. Email still has greeting, document name, signature.

## Edge cases and decisions

| Case | Decision |
|---|---|
| Template has `{reference}` but record has no reference yet | Substitute with `''`, collapse double spaces. Don't leak `{reference}` literal to customer. |
| Admin typos `{recepient_name}` | Stays literal in resolved text. Admin sees the typo on first modal open and fixes settings. No save-time validation in v1. |
| `SendDocumentByEmailAction` called from a callsite that forgot to pass `settingsKey` | PHP type error at call site (required parameter). Fails loudly at deploy, not at runtime for the end user. |
| Fair Inquiry context differs from document context | Resolver is context-agnostic (just an array). Callsite decides what keys to pass. No special-casing in the resolver. |
| RFQ PDF and RFQ Excel both use same template | Deliberate — same document, same wording, only the attachment format differs. Confirmed with user. |
| Email template migration runs on a system with existing customized templates | N/A — this is a new feature, no existing customizations to preserve. |
| User-edited textarea contains HTML or Markdown | Markdown template (`DocumentMail::content()` uses `markdown: 'emails.document'`) will render it as-is. Same behavior as today. No new XSS surface: customMessage already passed through the same path. |

## Initial template values (settings migration)

All in English, placeholder-aware:

- **Quotation:** `Dear {recipient_name}, please find attached our quotation {reference}. We remain at your disposal for any clarifications or adjustments.`
- **Purchase Order:** `Dear {recipient_name}, please find attached our Purchase Order {reference}. Kindly confirm receipt and expected delivery date.`
- **Proforma Invoice:** `Dear {recipient_name}, please find attached proforma invoice {reference} for your review. Please let us know if any adjustments are needed before we proceed.`
- **RFQ:** `Dear {recipient_name}, please find attached our Request for Quotation {reference}. We kindly ask you to review the items and send us your best proposal at your earliest convenience.`
- **Packing List:** `Dear {recipient_name}, please find attached the packing list for shipment {reference}.`
- **Commercial Invoice:** `Dear {recipient_name}, please find attached the commercial invoice for shipment {reference}. Please use this document for customs clearance purposes.`
- **Fair Inquiry:** `Dear {recipient_name}, we visited your booth at {trade_fair_name} and are interested in the following products: {product_names}. Could you please send us more details and a quotation at your earliest convenience?`

## Testing strategy

**Unit tests** — `EmailMessagePlaceholderResolver`:
- Substitutes a single placeholder.
- Substitutes multiple placeholders in the same template.
- Leaves unknown placeholders literal.
- Returns `''` when template is null.
- Returns `''` when template is empty string.
- Collapses double spaces created by empty-value substitution.
- Handles a context value that is itself null.
- Handles an integer or Stringable context value (casts to string).

**Feature tests** — one per send point, or one consolidated test per action class that parametrizes document type:
- Modal opens with a textarea whose default matches the resolved settings template.
- When the settings template is null, the textarea default is empty.
- Submitting the form with the default unchanged delivers the resolved text to `DocumentMail::customMessage`.
- Submitting with an edited message delivers the edited text.

**Regression** — existing `DocumentMail` tests should still pass after the Blade simplification. The only behavioral change is that the `@else` branch no longer fires.

## Files touched (estimate)

**New:**
- `app/Domain/Infrastructure/Services/EmailMessagePlaceholderResolver.php`
- `database/settings/YYYY_MM_DD_HHMMSS_add_email_default_messages_to_company_settings.php`
- `tests/Unit/Domain/Infrastructure/Services/EmailMessagePlaceholderResolverTest.php`
- `tests/Feature/Filament/Actions/SendDocumentByEmailActionDefaultMessageTest.php` (or similar)

**Modified:**
- `app/Domain/Settings/DataTransferObjects/CompanySettings.php` (+7 properties)
- `app/Filament/Pages/ManageCompanySettings.php` (new form Section)
- `app/Filament/Actions/SendDocumentByEmailAction.php` (new parameter, new private methods, Textarea default)
- `app/Filament/Resources/.../QuotationHeaderActions.php` (pass settingsKey)
- `app/Filament/Resources/.../PurchaseOrderHeaderActions.php` (pass settingsKey)
- `app/Filament/Resources/.../ProformaInvoiceHeaderActions.php` (pass settingsKey)
- `app/Filament/Resources/.../SupplierQuotationHeaderActions.php` (pass settingsKey, two Action instances sharing one key)
- `app/Filament/Resources/.../ShipmentHeaderActions.php` (pass settingsKey, two Action instances)
- `app/Filament/Fair/Pages/RegisterAtFair.php` (compute `email_message` default with Fair-specific context)
- `resources/views/emails/document.blade.php` (remove `@else` fallback)

Roughly: **4 new files, 9 modified files**.

## Open questions

None remaining — all decisions confirmed with user during brainstorming.
