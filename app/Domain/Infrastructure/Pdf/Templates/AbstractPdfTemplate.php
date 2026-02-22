<?php

namespace App\Domain\Infrastructure\Pdf\Templates;

use App\Domain\Infrastructure\Pdf\DocumentLabels;
use App\Domain\Settings\DataTransferObjects\CompanySettings;
use Illuminate\Database\Eloquent\Model;

abstract class AbstractPdfTemplate
{
    protected Model $model;
    protected string $locale;
    protected CompanySettings $companySettings;

    public function __construct(Model $model, string $locale = 'en')
    {
        $this->model = $model;
        $this->locale = $locale;
        $this->companySettings = app(CompanySettings::class);
    }

    public function getModel(): Model
    {
        return $this->model;
    }

    abstract public function getView(): string;

    abstract public function getDocumentTitle(): string;

    abstract protected function getDocumentData(): array;

    public function getData(): array
    {
        return array_merge(
            [
                'labels' => DocumentLabels::all($this->locale),
                'company' => $this->getCompanyData(),
                'title' => $this->getDocumentTitle(),
                'locale' => $this->locale,
            ],
            $this->getDocumentData(),
        );
    }

    public function getFilename(): string
    {
        $reference = $this->model->reference ?? $this->model->getKey();
        $version = $this->model->documents()
            ->where('type', $this->getDocumentType())
            ->max('version') ?? 0;

        $versionSuffix = $version > 0 ? '-v' . ($version + 1) : '-v1';

        return $reference . $versionSuffix . '.pdf';
    }

    abstract public function getDocumentType(): string;

    public function getPaper(): string
    {
        return 'a4';
    }

    public function getOrientation(): string
    {
        return 'portrait';
    }

    protected function getCompanyData(): array
    {
        $settings = $this->companySettings;

        return [
            'name' => $settings->company_name,
            'logo_path' => $this->resolveLogoPath($settings->logo_path),
            'address' => $settings->address,
            'city' => $settings->city,
            'state' => $settings->state,
            'zip_code' => $settings->zip_code,
            'country' => $settings->country,
            'phone' => $settings->phone,
            'email' => $settings->email,
            'website' => $settings->website,
            'tax_id' => $settings->tax_id,
            'registration_number' => $settings->registration_number,
            'footer_text' => $settings->footer_text,
            'bank_details' => $settings->bank_details_for_documents,
        ];
    }

    protected function resolveLogoPath(?string $logoPath): ?string
    {
        if (empty($logoPath)) {
            return null;
        }

        $candidates = [
            storage_path('app/public/' . $logoPath),
            storage_path('app/' . $logoPath),
            public_path('storage/' . $logoPath),
            public_path($logoPath),
        ];

        foreach ($candidates as $path) {
            if (file_exists($path)) {
                $mime = mime_content_type($path);
                $data = base64_encode(file_get_contents($path));

                return "data:{$mime};base64,{$data}";
            }
        }

        return null;
    }

    protected function formatMoney(int $amountInCents, string $currencyCode = 'USD'): string
    {
        $amount = $amountInCents / 100;

        return number_format($amount, 2, '.', ',');
    }

    protected function formatDate(mixed $date, string $format = 'd/m/Y'): string
    {
        if (empty($date)) {
            return '—';
        }

        if ($date instanceof \Carbon\Carbon || $date instanceof \Illuminate\Support\Carbon) {
            return $date->format($format);
        }

        return \Carbon\Carbon::parse($date)->format($format);
    }
}
