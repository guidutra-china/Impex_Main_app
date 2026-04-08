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
