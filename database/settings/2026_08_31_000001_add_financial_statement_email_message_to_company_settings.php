<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add(
            'company.email_default_message_financial_statement',
            'Dear {recipient_name}, please find attached the financial statement for shipment {reference}, showing the amounts due, the payments already received and the outstanding balance.'
        );
    }
};
