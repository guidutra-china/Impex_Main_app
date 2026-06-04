<?php

namespace App\Domain\Settings\Support;

use App\Domain\Settings\DataTransferObjects\CompanySettings;

/**
 * Company name + logo (as a base64 data URI) from the company settings, for use
 * in headers/PDFs. Falls back to the app name when settings are unavailable.
 */
class CompanyBranding
{
    /**
     * @return array{companyName: string, companyLogo: ?string}
     */
    public static function forView(): array
    {
        return [
            'companyName' => self::name(),
            'companyLogo' => self::logoDataUri(),
        ];
    }

    public static function name(): string
    {
        try {
            return app(CompanySettings::class)->company_name ?: (string) config('app.name');
        } catch (\Throwable) {
            return (string) config('app.name');
        }
    }

    public static function logoDataUri(): ?string
    {
        try {
            $path = app(CompanySettings::class)->logo_path;
        } catch (\Throwable) {
            return null;
        }

        if (empty($path)) {
            return null;
        }

        $candidates = [
            storage_path('app/public/'.$path),
            storage_path('app/'.$path),
            public_path('storage/'.$path),
            public_path($path),
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $mime = mime_content_type($candidate) ?: 'image/png';

                return 'data:'.$mime.';base64,'.base64_encode(file_get_contents($candidate));
            }
        }

        return null;
    }
}
