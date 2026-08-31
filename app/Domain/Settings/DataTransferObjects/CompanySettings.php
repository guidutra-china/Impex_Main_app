<?php

namespace App\Domain\Settings\DataTransferObjects;

use Spatie\LaravelSettings\Settings;

class CompanySettings extends Settings
{
    public string $company_name;

    public ?string $logo_path;

    public ?string $address;

    public ?string $city;

    public ?string $state;

    public ?string $zip_code;

    public ?string $country;

    public ?string $phone;

    public ?string $email;

    public ?string $website;

    public ?string $tax_id;

    public ?string $registration_number;

    public ?string $footer_text;

    public string $invoice_prefix;

    public string $quote_prefix;

    public string $po_prefix;

    public ?string $rfq_default_instructions;

    public ?string $po_terms;

    public string $packing_list_prefix;

    public string $commercial_invoice_prefix;

    public ?string $bank_details_for_documents;

    public ?string $email_default_message_quotation;

    public ?string $email_default_message_purchase_order;

    public ?string $email_default_message_proforma_invoice;

    public ?string $email_default_message_rfq;

    public ?string $email_default_message_packing_list;

    public ?string $email_default_message_commercial_invoice;

    public ?string $email_default_message_financial_statement;

    public ?string $email_default_message_fair_inquiry;

    public static function group(): string
    {
        return 'company';
    }
}
