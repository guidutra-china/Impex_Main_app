# Default Email Message in Send Modal — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Spec:** `docs/superpowers/specs/2026-04-08-email-default-message-in-modal-design.md`

**Goal:** Pre-populate the "message" textarea in every user-initiated "Send by Email" modal with a document-type-specific default text, configurable in Company Settings and resolved against the record via placeholder substitution at modal-open time.

**Architecture:** Add 7 nullable string fields to `CompanySettings` (one per document type), a stateless `EmailMessagePlaceholderResolver` service that substitutes `{variable}` tokens against a context array, extend `SendDocumentByEmailAction::make()` with a required `settingsKey` parameter that drives `Textarea->default()`, update the 7 callsites to pass their key, and do the same for the Fair Inquiry wizard's `email_message` textarea.

**Tech Stack:** Laravel 11, Filament 4, spatie/laravel-settings 3.7, PHPUnit, PHP 8.3+.

---

## File Structure

**New files (4):**

- `app/Domain/Infrastructure/Services/EmailMessagePlaceholderResolver.php` — stateless placeholder substitution service
- `database/settings/2026_04_08_000001_add_email_default_messages_to_company_settings.php` — settings migration adding 7 fields
- `tests/Unit/Domain/Infrastructure/Services/EmailMessagePlaceholderResolverTest.php` — unit tests for the resolver
- `tests/Feature/Filament/Actions/SendDocumentByEmailActionDefaultMessageTest.php` — feature test verifying modal default

**Modified files (10):**

- `app/Domain/Settings/DataTransferObjects/CompanySettings.php` — add 7 properties
- `app/Filament/Pages/ManageCompanySettings.php` — add "Email Templates" Section under Document Settings tab
- `app/Filament/Actions/SendDocumentByEmailAction.php` — new `$settingsKey` parameter and default-resolution logic
- `app/Filament/Resources/Quotations/Concerns/QuotationHeaderActions.php` — pass settingsKey
- `app/Filament/Resources/PurchaseOrders/Concerns/PurchaseOrderHeaderActions.php` — pass settingsKey
- `app/Filament/Resources/ProformaInvoices/Concerns/ProformaInvoiceHeaderActions.php` — pass settingsKey
- `app/Filament/Resources/SupplierQuotations/Concerns/SupplierQuotationHeaderActions.php` — pass settingsKey on both Actions (PDF + Excel share one key)
- `app/Filament/Resources/Shipments/Concerns/ShipmentHeaderActions.php` — pass settingsKey on both Actions (Packing List + Commercial Invoice use different keys)
- `app/Filament/Fair/Pages/RegisterAtFair.php` — compute `email_message` default with Fair-specific context
- `resources/views/emails/document.blade.php` — remove `@else` fallback
- `resources/views/emails/fair-inquiry.blade.php` — remove `@else` fallback

---

## Task 1: Placeholder Resolver Service

**Files:**
- Create: `app/Domain/Infrastructure/Services/EmailMessagePlaceholderResolver.php`
- Test: `tests/Unit/Domain/Infrastructure/Services/EmailMessagePlaceholderResolverTest.php`

- [ ] **Step 1.1: Create the failing unit test**

Create `tests/Unit/Domain/Infrastructure/Services/EmailMessagePlaceholderResolverTest.php`:

```php
<?php

namespace Tests\Unit\Domain\Infrastructure\Services;

use App\Domain\Infrastructure\Services\EmailMessagePlaceholderResolver;
use PHPUnit\Framework\TestCase;

class EmailMessagePlaceholderResolverTest extends TestCase
{
    private EmailMessagePlaceholderResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new EmailMessagePlaceholderResolver();
    }

    public function test_returns_empty_string_when_template_is_null(): void
    {
        $this->assertSame('', $this->resolver->resolve(null, ['recipient_name' => 'John']));
    }

    public function test_returns_empty_string_when_template_is_empty(): void
    {
        $this->assertSame('', $this->resolver->resolve('', ['recipient_name' => 'John']));
    }

    public function test_substitutes_single_placeholder(): void
    {
        $result = $this->resolver->resolve(
            'Dear {recipient_name}, hello.',
            ['recipient_name' => 'John Silva']
        );

        $this->assertSame('Dear John Silva, hello.', $result);
    }

    public function test_substitutes_multiple_placeholders(): void
    {
        $result = $this->resolver->resolve(
            'Dear {recipient_name}, please find attached {reference} for {company_name}.',
            [
                'recipient_name' => 'John',
                'reference'      => 'PO-2026-0042',
                'company_name'   => 'Acme Corp',
            ]
        );

        $this->assertSame('Dear John, please find attached PO-2026-0042 for Acme Corp.', $result);
    }

    public function test_leaves_unknown_placeholder_literal(): void
    {
        $result = $this->resolver->resolve(
            'Hello {recipient_name}, your order {order_id} is ready.',
            ['recipient_name' => 'John']
        );

        $this->assertSame('Hello John, your order {order_id} is ready.', $result);
    }

    public function test_substitutes_null_context_value_as_empty_string(): void
    {
        $result = $this->resolver->resolve(
            'Ref: {reference}',
            ['reference' => null]
        );

        $this->assertSame('Ref:', $result);
    }

    public function test_collapses_double_spaces_from_empty_substitution(): void
    {
        $result = $this->resolver->resolve(
            'Please find {reference} from {company_name}.',
            ['reference' => '', 'company_name' => 'Acme']
        );

        $this->assertSame('Please find from Acme.', $result);
    }

    public function test_casts_non_string_context_values(): void
    {
        $result = $this->resolver->resolve(
            'Order #{order_id}',
            ['order_id' => 42]
        );

        $this->assertSame('Order #42', $result);
    }

    public function test_trims_leading_and_trailing_whitespace(): void
    {
        $result = $this->resolver->resolve(
            '  {recipient_name}  ',
            ['recipient_name' => 'John']
        );

        $this->assertSame('John', $result);
    }
}
```

- [ ] **Step 1.2: Run the test and verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/Domain/Infrastructure/Services/EmailMessagePlaceholderResolverTest.php`

Expected: FAIL with `Class "App\Domain\Infrastructure\Services\EmailMessagePlaceholderResolver" not found`.

- [ ] **Step 1.3: Create the resolver service**

Create `app/Domain/Infrastructure/Services/EmailMessagePlaceholderResolver.php`:

```php
<?php

namespace App\Domain\Infrastructure\Services;

class EmailMessagePlaceholderResolver
{
    /**
     * Substitute {variable} tokens in a template string against a context array.
     *
     * - Null or empty templates return ''.
     * - Unknown placeholders (keys not present in $context) are left literal.
     * - Null or empty context values are substituted as ''.
     * - Double spaces left by empty substitutions are collapsed to a single space.
     * - Leading/trailing whitespace is trimmed.
     *
     * @param array<string, scalar|\Stringable|null> $context
     */
    public function resolve(?string $template, array $context): string
    {
        if ($template === null || $template === '') {
            return '';
        }

        $resolved = $template;
        foreach ($context as $key => $value) {
            $resolved = str_replace(
                '{' . $key . '}',
                (string) ($value ?? ''),
                $resolved
            );
        }

        return trim(preg_replace('/ {2,}/', ' ', $resolved));
    }
}
```

- [ ] **Step 1.4: Run the test and verify it passes**

Run: `./vendor/bin/phpunit tests/Unit/Domain/Infrastructure/Services/EmailMessagePlaceholderResolverTest.php`

Expected: `OK (9 tests, 9 assertions)` or equivalent green result.

- [ ] **Step 1.5: Commit**

```bash
git add app/Domain/Infrastructure/Services/EmailMessagePlaceholderResolver.php \
        tests/Unit/Domain/Infrastructure/Services/EmailMessagePlaceholderResolverTest.php
git commit -m "feat(emails): add EmailMessagePlaceholderResolver service

Stateless service that substitutes {variable} tokens in email message
templates against a context array. Unknown placeholders stay literal;
empty substitutions collapse double spaces. Covered by 9 unit tests."
```

---

## Task 2: CompanySettings Properties and Settings Migration

**Files:**
- Modify: `app/Domain/Settings/DataTransferObjects/CompanySettings.php`
- Create: `database/settings/2026_04_08_000001_add_email_default_messages_to_company_settings.php`

- [ ] **Step 2.1: Add properties to `CompanySettings`**

Open `app/Domain/Settings/DataTransferObjects/CompanySettings.php`. Insert the 7 new properties directly after the existing `public ?string $bank_details_for_documents;` line (line 29) and before the `public static function group()` declaration:

```php
    public ?string $email_default_message_quotation;
    public ?string $email_default_message_purchase_order;
    public ?string $email_default_message_proforma_invoice;
    public ?string $email_default_message_rfq;
    public ?string $email_default_message_packing_list;
    public ?string $email_default_message_commercial_invoice;
    public ?string $email_default_message_fair_inquiry;
```

- [ ] **Step 2.2: Create the settings migration**

Create `database/settings/2026_04_08_000001_add_email_default_messages_to_company_settings.php`:

```php
<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add(
            'company.email_default_message_quotation',
            'Dear {recipient_name}, please find attached our quotation {reference}. We remain at your disposal for any clarifications or adjustments.'
        );

        $this->migrator->add(
            'company.email_default_message_purchase_order',
            'Dear {recipient_name}, please find attached our Purchase Order {reference}. Kindly confirm receipt and expected delivery date.'
        );

        $this->migrator->add(
            'company.email_default_message_proforma_invoice',
            'Dear {recipient_name}, please find attached proforma invoice {reference} for your review. Please let us know if any adjustments are needed before we proceed.'
        );

        $this->migrator->add(
            'company.email_default_message_rfq',
            'Dear {recipient_name}, please find attached our Request for Quotation {reference}. We kindly ask you to review the items and send us your best proposal at your earliest convenience.'
        );

        $this->migrator->add(
            'company.email_default_message_packing_list',
            'Dear {recipient_name}, please find attached the packing list for shipment {reference}.'
        );

        $this->migrator->add(
            'company.email_default_message_commercial_invoice',
            'Dear {recipient_name}, please find attached the commercial invoice for shipment {reference}. Please use this document for customs clearance purposes.'
        );

        $this->migrator->add(
            'company.email_default_message_fair_inquiry',
            'Dear {recipient_name}, we visited your booth at {trade_fair_name} and are interested in the following products: {product_names}. Could you please send us more details and a quotation at your earliest convenience?'
        );
    }
};
```

- [ ] **Step 2.3: Run the migration**

Run: `php artisan migrate`

Expected: the new settings migration runs and outputs `DONE` for `2026_04_08_000001_add_email_default_messages_to_company_settings`.

- [ ] **Step 2.4: Verify the settings are readable**

Run: `php artisan tinker --execute="echo app(\App\Domain\Settings\DataTransferObjects\CompanySettings::class)->email_default_message_purchase_order;"`

Expected output:
```
Dear {recipient_name}, please find attached our Purchase Order {reference}. Kindly confirm receipt and expected delivery date.
```

If instead you see `PHP Error: Typed property ... must not be accessed before initialization`, it means the DTO properties were added but `php artisan config:clear` / `php artisan optimize:clear` is needed. Run `php artisan optimize:clear` and retry.

- [ ] **Step 2.5: Commit**

```bash
git add app/Domain/Settings/DataTransferObjects/CompanySettings.php \
        database/settings/2026_04_08_000001_add_email_default_messages_to_company_settings.php
git commit -m "feat(settings): add email default message fields to CompanySettings

Seven nullable string fields, one per document type (quotation, PO, PI,
RFQ, packing list, commercial invoice, fair inquiry). Initial values are
placeholder-aware English templates that can be edited by admins."
```

---

## Task 3: Company Settings UI — Email Templates Section

**Files:**
- Modify: `app/Filament/Pages/ManageCompanySettings.php`

- [ ] **Step 3.1: Add the Email Templates Section to the Document Settings tab**

Open `app/Filament/Pages/ManageCompanySettings.php`. Inside the `Tabs\Tab::make(__('forms.tabs.document_settings'))` schema, after the closing `])` of the existing `Section::make(__('forms.sections.default_texts'))` block (around line 154), add a new Section. The final schema for that tab should look like:

```php
Tabs\Tab::make(__('forms.tabs.document_settings'))
    ->icon('heroicon-o-document-text')
    ->schema([
        Section::make(__('forms.sections.document_prefixes'))
            // ... existing unchanged ...
            ->columns(3),
        Section::make(__('forms.sections.default_texts'))
            // ... existing unchanged ...
            ,
        Section::make('Email Templates')
            ->description('Default message body for each "Send by Email" action. Supports placeholders listed under each field. Leave blank to open the email modal with an empty message textarea.')
            ->schema([
                Textarea::make('email_default_message_quotation')
                    ->label('Quotation email message')
                    ->rows(4)
                    ->helperText('Available placeholders: {recipient_name}, {company_name}, {reference}, {document_name}')
                    ->columnSpanFull(),
                Textarea::make('email_default_message_purchase_order')
                    ->label('Purchase Order email message')
                    ->rows(4)
                    ->helperText('Available placeholders: {recipient_name}, {company_name}, {reference}, {document_name}')
                    ->columnSpanFull(),
                Textarea::make('email_default_message_proforma_invoice')
                    ->label('Proforma Invoice email message')
                    ->rows(4)
                    ->helperText('Available placeholders: {recipient_name}, {company_name}, {reference}, {document_name}')
                    ->columnSpanFull(),
                Textarea::make('email_default_message_rfq')
                    ->label('RFQ email message (PDF and Excel)')
                    ->rows(4)
                    ->helperText('Available placeholders: {recipient_name}, {company_name}, {reference}, {document_name}')
                    ->columnSpanFull(),
                Textarea::make('email_default_message_packing_list')
                    ->label('Packing List email message')
                    ->rows(4)
                    ->helperText('Available placeholders: {recipient_name}, {company_name}, {reference}, {document_name}')
                    ->columnSpanFull(),
                Textarea::make('email_default_message_commercial_invoice')
                    ->label('Commercial Invoice email message')
                    ->rows(4)
                    ->helperText('Available placeholders: {recipient_name}, {company_name}, {reference}, {document_name}')
                    ->columnSpanFull(),
                Textarea::make('email_default_message_fair_inquiry')
                    ->label('Fair Inquiry email message')
                    ->rows(4)
                    ->helperText('Available placeholders: {recipient_name}, {company_name}, {trade_fair_name}, {product_names}')
                    ->columnSpanFull(),
            ]),
    ]),
```

Use the Edit tool to insert the new `Section::make('Email Templates')` block. Make sure a comma separates it from the previous section and the closing `])` of the tab schema is intact.

- [ ] **Step 3.2: Verify the page renders without errors**

Run: `php artisan route:clear && php artisan view:clear`

Then in the browser (or via a quick smoke test), open `/admin/company-settings`. Expected: the "Document Settings" tab now shows a third Section "Email Templates" with 7 Textareas, each pre-filled with the values from the settings migration and each showing its placeholder helper text.

If you can't open the browser, run the existing Filament page test smoke (if any) or at minimum:

```bash
php artisan about | grep -i "Laravel"  # sanity check the app boots
```

And make sure `php artisan optimize:clear` exits 0.

- [ ] **Step 3.3: Commit**

```bash
git add app/Filament/Pages/ManageCompanySettings.php
git commit -m "feat(settings): add Email Templates section to Company Settings page

Seven Textarea fields under Document Settings tab, one per document type.
Each shows the placeholder variables supported by its template."
```

---

## Task 4: Extend `SendDocumentByEmailAction` with settingsKey and Default Resolution

**Files:**
- Modify: `app/Filament/Actions/SendDocumentByEmailAction.php`

- [ ] **Step 4.1: Update the `make()` signature and inject settingsKey**

Open `app/Filament/Actions/SendDocumentByEmailAction.php`. Replace the current `make()` method (lines 17-87) so that:

1. The signature accepts `string $settingsKey` as the second required parameter.
2. `buildForm($record)` is called with both `$record` and `$settingsKey`.

Replace the whole `make()` method with:

```php
    public static function make(
        string $documentType,
        string $settingsKey,
        string $label = 'Send by Email',
        string $icon = 'heroicon-o-envelope',
        ?string $name = null,
    ): Action {
        return Action::make($name ?? 'sendByEmail_' . str_replace(['.', '-'], '_', $documentType))
            ->label($label)
            ->icon($icon)
            ->color('warning')
            ->visible(fn ($record) => $record->getLatestDocument($documentType) !== null
                && auth()->user()?->can('send-documents-by-email'))
            ->form(fn ($record): array => static::buildForm($record, $settingsKey, $documentType))
            ->action(function (array $data, $record) use ($documentType) {
                $document = $record->getLatestDocument($documentType);

                if (! $document || ! $document->exists()) {
                    Notification::make()
                        ->title('Document Not Found')
                        ->body(__('messages.no_pdf_generated'))
                        ->warning()
                        ->send();

                    return;
                }

                try {
                    $toAddresses = collect($data['to'])
                        ->map(fn ($email) => trim($email))
                        ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
                        ->unique()
                        ->values()
                        ->all();

                    $mail = Mail::to($toAddresses);

                    $ccAddresses = collect($data['cc'] ?? [])
                        ->map(fn ($email) => trim($email))
                        ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
                        ->unique()
                        ->values()
                        ->all();

                    if (! empty($ccAddresses)) {
                        $mail->cc($ccAddresses);
                    }

                    $mail->send(new DocumentMail(
                        document: $document,
                        recipientName: $data['recipient_name'],
                        customMessage: $data['message'] ?? '',
                    ));

                    $allRecipients = collect($toAddresses)->merge($ccAddresses)->join(', ');

                    Notification::make()
                        ->title('Email Sent')
                        ->body("Document sent to {$allRecipients}")
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    report($e);

                    Notification::make()
                        ->title('Email Failed')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
```

- [ ] **Step 4.2: Update `buildForm()` signature and pre-populate the message textarea**

Still in the same file, replace the current `buildForm($record)` method (lines 89-132) with the new signature and Textarea default:

```php
    private static function buildForm($record, string $settingsKey, string $documentType): array
    {
        $contact = $record->contact ?? null;
        $company = static::resolveCompany($record);
        $emailOptions = static::buildEmailOptions($company, $contact);
        $emailSuggestions = static::buildEmailSuggestions($company, $contact);
        $defaultTo = static::resolveDefaultTo($contact, $emailOptions);
        $defaultMessage = static::resolveDefaultMessage($settingsKey, $record, $contact, $company, $documentType);

        return [
            Select::make('to')
                ->label(__('forms.labels.to'))
                ->options($emailOptions)
                ->default($defaultTo ? [$defaultTo] : [])
                ->multiple()
                ->searchable()
                ->allowHtml()
                ->required()
                ->createOptionForm([
                    TextInput::make('email')
                        ->label(__('forms.labels.email_address'))
                        ->email()
                        ->required(),
                ])
                ->createOptionUsing(fn (array $data) => $data['email'])
                ->helperText(__('forms.helpers.select_a_registered_contact_or_add_a_new_email')),

            TextInput::make('recipient_name')
                ->label(__('forms.labels.recipient_name'))
                ->required()
                ->default($contact?->name ?? $company?->name),

            TagsInput::make('cc')
                ->label(__('forms.labels.cc'))
                ->suggestions($emailSuggestions)
                ->splitKeys(['Tab', ','])
                ->placeholder(__('forms.placeholders.select_or_type_email_addresses'))
                ->helperText(__('forms.helpers.pick_from_suggestions_or_type_a_new_email_and_press_tabenter')),

            Textarea::make('message')
                ->label(__('forms.labels.message_optional'))
                ->default($defaultMessage)
                ->placeholder(__('forms.placeholders.add_a_custom_message_to_the_email'))
                ->helperText('Available placeholders (already resolved above): {recipient_name}, {company_name}, {reference}, {document_name}')
                ->rows(6),
        ];
    }
```

- [ ] **Step 4.3: Add the `resolveDefaultMessage()` private helper**

Still in `SendDocumentByEmailAction.php`, insert this new private static method directly above `resolveCompany()` (around line 208):

```php
    private static function resolveDefaultMessage(
        string $settingsKey,
        $record,
        $contact,
        $company,
        string $documentType,
    ): string {
        $settings = app(\App\Domain\Settings\DataTransferObjects\CompanySettings::class);

        // Dynamic property access — must be one of the known email_default_message_* keys.
        $template = property_exists($settings, $settingsKey) ? $settings->{$settingsKey} : null;

        if ($template === null || $template === '') {
            return '';
        }

        $document = method_exists($record, 'getLatestDocument')
            ? $record->getLatestDocument($documentType)
            : null;

        $context = [
            'recipient_name' => $contact?->name ?? $company?->name ?? '',
            'company_name'   => $company?->name ?? '',
            'reference'      => $record->reference ?? '',
            'document_name'  => $document?->name ?? '',
        ];

        return app(\App\Domain\Infrastructure\Services\EmailMessagePlaceholderResolver::class)
            ->resolve($template, $context);
    }
```

- [ ] **Step 4.4: Verify the file has no syntax errors**

Run: `php -l app/Filament/Actions/SendDocumentByEmailAction.php`

Expected: `No syntax errors detected in app/Filament/Actions/SendDocumentByEmailAction.php`

- [ ] **Step 4.5: Commit**

```bash
git add app/Filament/Actions/SendDocumentByEmailAction.php
git commit -m "feat(emails): pre-populate message textarea via settings template

SendDocumentByEmailAction now takes a required settingsKey parameter.
On modal open, it reads the matching CompanySettings template and
resolves {recipient_name}, {company_name}, {reference}, {document_name}
placeholders against the current record. Callsite updates come next."
```

---

## Task 5: Update All Group A Callsites

**Files:**
- Modify: `app/Filament/Resources/Quotations/Concerns/QuotationHeaderActions.php:49`
- Modify: `app/Filament/Resources/PurchaseOrders/Concerns/PurchaseOrderHeaderActions.php:50`
- Modify: `app/Filament/Resources/ProformaInvoices/Concerns/ProformaInvoiceHeaderActions.php:71`
- Modify: `app/Filament/Resources/SupplierQuotations/Concerns/SupplierQuotationHeaderActions.php:70,74`
- Modify: `app/Filament/Resources/Shipments/Concerns/ShipmentHeaderActions.php:54,74`

- [ ] **Step 5.1: Quotation**

Replace lines 49-52 of `app/Filament/Resources/Quotations/Concerns/QuotationHeaderActions.php`:

```php
            SendDocumentByEmailAction::make(
                documentType: 'quotation_pdf',
                settingsKey: 'email_default_message_quotation',
                label: 'Send by Email',
            ),
```

- [ ] **Step 5.2: Purchase Order**

Replace lines 50-53 of `app/Filament/Resources/PurchaseOrders/Concerns/PurchaseOrderHeaderActions.php`:

```php
            SendDocumentByEmailAction::make(
                documentType: 'purchase_order_pdf',
                settingsKey: 'email_default_message_purchase_order',
                label: 'Send by Email',
            ),
```

- [ ] **Step 5.3: Proforma Invoice**

Replace lines 71-74 of `app/Filament/Resources/ProformaInvoices/Concerns/ProformaInvoiceHeaderActions.php`:

```php
            SendDocumentByEmailAction::make(
                documentType: 'proforma_invoice_pdf',
                settingsKey: 'email_default_message_proforma_invoice',
                label: 'Send by Email',
            ),
```

- [ ] **Step 5.4: Supplier Quotation (RFQ PDF + RFQ Excel — both share one settings key)**

Replace lines 70-78 of `app/Filament/Resources/SupplierQuotations/Concerns/SupplierQuotationHeaderActions.php`:

```php
            SendDocumentByEmailAction::make(
                documentType: 'rfq_pdf',
                settingsKey: 'email_default_message_rfq',
                label: 'Send RFQ PDF',
            ),
            SendDocumentByEmailAction::make(
                documentType: 'rfq_excel',
                settingsKey: 'email_default_message_rfq',
                label: 'Send RFQ Excel',
                icon: 'heroicon-o-envelope',
            ),
```

- [ ] **Step 5.5: Shipment (Packing List + Commercial Invoice — different keys)**

Replace lines 54-57 of `app/Filament/Resources/Shipments/Concerns/ShipmentHeaderActions.php`:

```php
                SendDocumentByEmailAction::make(
                    documentType: 'packing_list_pdf',
                    settingsKey: 'email_default_message_packing_list',
                    label: 'Send by Email',
                )->name('sendPackingListByEmail'),
```

Then replace lines 74-77 (the Commercial Invoice Action):

```php
                SendDocumentByEmailAction::make(
                    documentType: 'commercial_invoice_pdf',
                    settingsKey: 'email_default_message_commercial_invoice',
                    label: 'Send by Email',
                )->name('sendCommercialInvoiceByEmail'),
```

- [ ] **Step 5.6: Lint all five files**

Run:

```bash
php -l app/Filament/Resources/Quotations/Concerns/QuotationHeaderActions.php
php -l app/Filament/Resources/PurchaseOrders/Concerns/PurchaseOrderHeaderActions.php
php -l app/Filament/Resources/ProformaInvoices/Concerns/ProformaInvoiceHeaderActions.php
php -l app/Filament/Resources/SupplierQuotations/Concerns/SupplierQuotationHeaderActions.php
php -l app/Filament/Resources/Shipments/Concerns/ShipmentHeaderActions.php
```

Expected: all five print `No syntax errors detected`.

- [ ] **Step 5.7: Commit**

```bash
git add app/Filament/Resources/Quotations/Concerns/QuotationHeaderActions.php \
        app/Filament/Resources/PurchaseOrders/Concerns/PurchaseOrderHeaderActions.php \
        app/Filament/Resources/ProformaInvoices/Concerns/ProformaInvoiceHeaderActions.php \
        app/Filament/Resources/SupplierQuotations/Concerns/SupplierQuotationHeaderActions.php \
        app/Filament/Resources/Shipments/Concerns/ShipmentHeaderActions.php
git commit -m "feat(emails): pass settingsKey to all SendDocumentByEmailAction callsites

Seven callsites across Quotations, Purchase Orders, Proforma Invoices,
Supplier Quotations (RFQ PDF + Excel, shared key), and Shipments
(Packing List + Commercial Invoice). Each now routes to its matching
CompanySettings template for the pre-populated message default."
```

---

## Task 6: Document Blade Template Cleanup

**Files:**
- Modify: `resources/views/emails/document.blade.php`

- [ ] **Step 6.1: Remove the `@else` fallback**

Open `resources/views/emails/document.blade.php`. Current content:

```blade
<x-mail::message>
Dear {{ $recipientName }},

@if($customMessage)
{{ $customMessage }}
@else
Please find the attached document for your reference.
@endif

**Document:** {{ $document->name }}

If you have any questions, please don't hesitate to contact us.

Best regards,<br>
{{ config('app.name') }}
</x-mail::message>
```

Replace with:

```blade
<x-mail::message>
Dear {{ $recipientName }},

@if($customMessage)
{{ $customMessage }}
@endif

**Document:** {{ $document->name }}

If you have any questions, please don't hesitate to contact us.

Best regards,<br>
{{ config('app.name') }}
</x-mail::message>
```

Rationale: `$customMessage` now always arrives pre-populated from the modal. The `@if` safety guard stays so that if an admin deliberately clears a template and a user sends without typing anything, the email still renders cleanly (no empty paragraph, just greeting + document name + signature).

- [ ] **Step 6.2: Commit**

```bash
git add resources/views/emails/document.blade.php
git commit -m "refactor(emails): remove hardcoded fallback from document template

The message textarea is now always pre-populated from CompanySettings,
so the @else branch is dead code. If the admin clears a template and
the user sends blank, the email still renders via the outer @if guard."
```

---

## Task 7: Fair Inquiry — Default Resolution and Blade Cleanup

**Files:**
- Modify: `app/Filament/Fair/Pages/RegisterAtFair.php:899-903` (the `email_message` Textarea)
- Modify: `resources/views/emails/fair-inquiry.blade.php`

- [ ] **Step 7.1: Pre-populate `email_message` with Fair-specific context**

Open `app/Filament/Fair/Pages/RegisterAtFair.php`. Locate the `emailStep()` method (around line 864) and the `Textarea::make('email_message')` block (around line 899-903).

Replace the current Textarea block with:

```php
                        Textarea::make('email_message')
                            ->label('Custom Message')
                            ->default(function () {
                                $settings = app(\App\Domain\Settings\DataTransferObjects\CompanySettings::class);
                                $template = $settings->email_default_message_fair_inquiry;

                                if ($template === null || $template === '') {
                                    return '';
                                }

                                $tradeFair = ! empty($this->data['trade_fair_id'])
                                    ? TradeFair::find($this->data['trade_fair_id'])
                                    : null;

                                $productNames = collect($this->data['products'] ?? [])
                                    ->pluck('name')
                                    ->filter()
                                    ->implode(', ');

                                $context = [
                                    'recipient_name'  => $this->data['contact_name'] ?? '',
                                    'company_name'    => $this->data['company_name'] ?? '',
                                    'trade_fair_name' => $tradeFair?->name ?? '',
                                    'product_names'   => $productNames,
                                ];

                                return app(\App\Domain\Infrastructure\Services\EmailMessagePlaceholderResolver::class)
                                    ->resolve($template, $context);
                            })
                            ->placeholder('Add any additional message to the supplier...')
                            ->rows(6)
                            ->helperText('Available placeholders (already resolved above): {recipient_name}, {company_name}, {trade_fair_name}, {product_names}'),
```

The `TradeFair` model is already imported at `RegisterAtFair.php:15` (`use App\Domain\TradeFairs\Models\TradeFair;`), so the short name used in the closure resolves correctly with no additional `use` statement needed.

- [ ] **Step 7.2: Remove the `@else` fallback from `fair-inquiry.blade.php`**

Open `resources/views/emails/fair-inquiry.blade.php`. Current content has:

```blade
@if($customMessage)
{{ $customMessage }}
@else
We are interested in your products and would like to request more information, including pricing, specifications, and minimum order quantities for the following items:
@endif
```

Replace with:

```blade
@if($customMessage)
{{ $customMessage }}
@endif
```

Leave the rest of the file (greeting, product list, numbered request items, signature) unchanged.

- [ ] **Step 7.3: Lint the PHP file and clear views**

Run:

```bash
php -l app/Filament/Fair/Pages/RegisterAtFair.php
php artisan view:clear
```

Expected: `No syntax errors detected` and a clean `view:clear`.

- [ ] **Step 7.4: Commit**

```bash
git add app/Filament/Fair/Pages/RegisterAtFair.php \
        resources/views/emails/fair-inquiry.blade.php
git commit -m "feat(fair-inquiry): pre-populate email_message from settings template

Fair Inquiry wizard now resolves {recipient_name}, {company_name},
{trade_fair_name}, {product_names} from the CompanySettings template
when the email step opens. Blade fallback removed, matching the pattern
used for DocumentMail."
```

---

## Task 8: Feature Test for SendDocumentByEmailAction Default

**Files:**
- Create: `tests/Feature/Filament/Actions/SendDocumentByEmailActionDefaultMessageTest.php`

This is a lightweight feature test that exercises `resolveDefaultMessage()` indirectly by checking the public behavior through a unit-style test that doesn't require the full Filament modal. It bypasses Filament rendering and verifies the resolver + settings integration.

- [ ] **Step 8.1: Inspect how the project tests Filament actions**

Run: `grep -rn "SendDocumentByEmailAction" tests/ || echo "no existing tests"`

Expected: likely "no existing tests". That's fine — we'll add a narrow behavioral test that exercises the same settings + resolver code path the production Action uses.

Also check for an existing feature test base class:

```bash
cat tests/TestCase.php
```

Expected: a `Tests\TestCase` class extending `Illuminate\Foundation\Testing\TestCase`. Use it as the base.

- [ ] **Step 8.2: Write the feature test**

Create `tests/Feature/Filament/Actions/SendDocumentByEmailActionDefaultMessageTest.php`:

```php
<?php

namespace Tests\Feature\Filament\Actions;

use App\Domain\Infrastructure\Services\EmailMessagePlaceholderResolver;
use App\Domain\Settings\DataTransferObjects\CompanySettings;
use Tests\TestCase;

class SendDocumentByEmailActionDefaultMessageTest extends TestCase
{
    public function test_settings_template_is_loadable_for_all_seven_keys(): void
    {
        $settings = app(CompanySettings::class);

        $this->assertNotNull($settings->email_default_message_quotation);
        $this->assertNotNull($settings->email_default_message_purchase_order);
        $this->assertNotNull($settings->email_default_message_proforma_invoice);
        $this->assertNotNull($settings->email_default_message_rfq);
        $this->assertNotNull($settings->email_default_message_packing_list);
        $this->assertNotNull($settings->email_default_message_commercial_invoice);
        $this->assertNotNull($settings->email_default_message_fair_inquiry);
    }

    public function test_purchase_order_template_resolves_placeholders_against_a_real_context(): void
    {
        $settings = app(CompanySettings::class);
        $resolver = app(EmailMessagePlaceholderResolver::class);

        $resolved = $resolver->resolve(
            $settings->email_default_message_purchase_order,
            [
                'recipient_name' => 'John Silva',
                'company_name'   => 'Acme Corp',
                'reference'      => 'PO-2026-0042',
                'document_name'  => 'PO-2026-0042.pdf',
            ]
        );

        $this->assertStringContainsString('John Silva', $resolved);
        $this->assertStringContainsString('PO-2026-0042', $resolved);
        $this->assertStringNotContainsString('{recipient_name}', $resolved);
        $this->assertStringNotContainsString('{reference}', $resolved);
    }

    public function test_fair_inquiry_template_resolves_fair_specific_placeholders(): void
    {
        $settings = app(CompanySettings::class);
        $resolver = app(EmailMessagePlaceholderResolver::class);

        $resolved = $resolver->resolve(
            $settings->email_default_message_fair_inquiry,
            [
                'recipient_name'  => 'Ms. Wang',
                'company_name'    => 'Foshan Lighting',
                'trade_fair_name' => 'Canton Fair Phase 2',
                'product_names'   => 'LED Bulbs, Pendant Lamps',
            ]
        );

        $this->assertStringContainsString('Ms. Wang', $resolved);
        $this->assertStringContainsString('Canton Fair Phase 2', $resolved);
        $this->assertStringContainsString('LED Bulbs, Pendant Lamps', $resolved);
        $this->assertStringNotContainsString('{trade_fair_name}', $resolved);
        $this->assertStringNotContainsString('{product_names}', $resolved);
    }

    public function test_null_template_returns_empty_string_without_error(): void
    {
        $resolver = app(EmailMessagePlaceholderResolver::class);

        $this->assertSame('', $resolver->resolve(null, ['recipient_name' => 'John']));
    }
}
```

- [ ] **Step 8.3: Run the feature test**

Run: `./vendor/bin/phpunit tests/Feature/Filament/Actions/SendDocumentByEmailActionDefaultMessageTest.php`

Expected: `OK (4 tests, 11 assertions)` or equivalent.

If a test fails with `Target class [Tests\TestCase] does not exist` or similar, check the namespace in `tests/TestCase.php` and adjust the `use Tests\TestCase;` import accordingly (might be `use Tests\Feature\TestCase` in some project structures).

- [ ] **Step 8.4: Commit**

```bash
git add tests/Feature/Filament/Actions/SendDocumentByEmailActionDefaultMessageTest.php
git commit -m "test(emails): verify CompanySettings email templates resolve correctly

Four assertions: all seven keys load non-null, PO template resolves
document placeholders, Fair Inquiry template resolves fair-specific
placeholders, null template returns empty string gracefully."
```

---

## Task 9: Final Regression and Smoke Tests

- [ ] **Step 9.1: Run the full unit and feature suite**

Run: `./vendor/bin/phpunit`

Expected: all tests pass, or at minimum no new failures are introduced compared to the baseline before this plan started. If pre-existing tests are failing and unrelated to this work, note them but do NOT attempt to fix them as part of this plan — that's out of scope.

If a test specifically around `DocumentMail` or Blade rendering fails because of the `@else` removal, investigate that file. The only behavioral change is: when `$customMessage` is empty, no paragraph is rendered between the greeting and the document name line. If a test was asserting the hardcoded English fallback sentence, update that test to reflect the new behavior.

- [ ] **Step 9.2: Manual smoke test checklist**

Using a local dev environment (Herd, Sail, or `php artisan serve`), log in as an admin and verify the following modals each open with a pre-populated message that reflects the CompanySettings template:

1. `/admin/quotations/{id}` → "Send by Email" → textarea contains resolved quotation template
2. `/admin/purchase-orders/{id}` → "Send by Email" → textarea contains resolved PO template
3. `/admin/proforma-invoices/{id}` → "Send by Email" → textarea contains resolved PI template
4. `/admin/supplier-quotations/{id}` → "Send RFQ PDF" → textarea contains resolved RFQ template
5. `/admin/supplier-quotations/{id}` → "Send RFQ Excel" → textarea contains the **same** resolved RFQ template
6. `/admin/shipments/{id}` → "Send by Email" (Packing List) → textarea contains resolved packing list template
7. `/admin/shipments/{id}` → "Send by Email" (Commercial Invoice) → textarea contains resolved commercial invoice template
8. `/fair/register` wizard → Send Email step → `Custom Message` textarea contains resolved fair inquiry template with real trade fair name and product names filled in

For each, confirm:
- Placeholders are substituted (no literal `{recipient_name}` visible)
- Editing the textarea works
- Clearing the textarea and sending still delivers a valid email (empty paragraph, no error)

Note any issues found but treat fixes as follow-up tickets, not plan scope, unless the issue is a bug introduced by this plan.

- [ ] **Step 9.3: Final commit (if any small fixes were made during smoke testing)**

If smoke testing found a small bug introduced by this plan, fix it and commit:

```bash
git add <files>
git commit -m "fix(emails): <short description of fix>"
```

Otherwise, skip this step.

---

## Notes

- **No backwards compatibility shims needed.** The `$settingsKey` parameter is required, so any forgotten callsite fails loudly at deploy with a PHP `ArgumentCountError`. All known callsites are updated in Task 5.
- **Translation of labels and helper text.** The new Section title "Email Templates" and the per-field labels and helper texts are in English hardcoded strings, matching the existing `po_terms` / `rfq_default_instructions` fields which are also partially hardcoded. If the project later adds these to the `forms.*` translation files, it's a follow-up, not this plan's responsibility.
- **TradeFair import in `RegisterAtFair.php`.** Already present at line 15 (`use App\Domain\TradeFairs\Models\TradeFair;`). No new import needed.
- **`php artisan optimize:clear` after Task 2.** The spatie/laravel-settings DTO caches its property list. If Task 2 tests fail with an initialization error, run `php artisan optimize:clear` and retry before investigating further.
