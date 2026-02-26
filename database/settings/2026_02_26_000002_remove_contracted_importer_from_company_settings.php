<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

class RemoveContractedImporterFromCompanySettings extends SettingsMigration
{
    public function up(): void
    {
        if ($this->settingExists('company.contracted_importer_details')) {
            $this->migrator->delete('company.contracted_importer_details');
        }
    }

    private function settingExists(string $name): bool
    {
        return \Illuminate\Support\Facades\DB::table('settings')
            ->where('name', $name)
            ->exists();
    }
}
