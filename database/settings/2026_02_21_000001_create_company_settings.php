<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('company.company_name', 'Your Company Name');
        $this->migrator->add('company.logo_path', null);
        $this->migrator->add('company.address', null);
        $this->migrator->add('company.city', null);
        $this->migrator->add('company.state', null);
        $this->migrator->add('company.zip_code', null);
        $this->migrator->add('company.country', null);
        $this->migrator->add('company.phone', null);
        $this->migrator->add('company.email', null);
        $this->migrator->add('company.website', null);
        $this->migrator->add('company.tax_id', null);
        $this->migrator->add('company.registration_number', null);
        $this->migrator->add('company.footer_text', null);
        $this->migrator->add('company.invoice_prefix', 'INV');
        $this->migrator->add('company.quote_prefix', 'QT');
        $this->migrator->add('company.po_prefix', 'PO');
        $this->migrator->add('company.rfq_default_instructions', null);
        $this->migrator->add('company.po_terms', null);
        $this->migrator->add('company.packing_list_prefix', 'PL');
        $this->migrator->add('company.commercial_invoice_prefix', 'CI');
        $this->migrator->add('company.bank_details_for_documents', null);
    }
};
