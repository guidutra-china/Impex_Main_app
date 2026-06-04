<?php

namespace App\Filament\Resources\CRM\Companies\Concerns;

use App\Domain\CRM\Models\Company;

trait ManagesCompanyGallery
{
    /**
     * Seed the gallery FileUpload state from the company_photos relation.
     */
    protected function fillCompanyGallery(array $data): array
    {
        $record = $this->getRecord();

        $data['company_photos'] = $record->photos()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('path')
            ->all();

        return $data;
    }

    /**
     * Persist the gallery FileUpload state into company_photos. The first image
     * is flagged primary (used as the company cover / OCR source).
     */
    protected function syncCompanyGallery(Company $company): void
    {
        $paths = array_values(array_filter(
            (array) ($this->data['company_photos'] ?? []),
            fn ($path) => filled($path),
        ));

        foreach ($paths as $index => $path) {
            $company->photos()->updateOrCreate(
                ['path' => $path],
                ['sort_order' => $index, 'disk' => 'public', 'is_primary' => $index === 0],
            );
        }

        // Remove rows no longer present — fetch models so the deleting hook
        // fires per-row file cleanup (mass delete would bypass it).
        $company->photos()
            ->when($paths !== [], fn ($query) => $query->whereNotIn('path', $paths))
            ->get()
            ->each
            ->delete();
    }
}
