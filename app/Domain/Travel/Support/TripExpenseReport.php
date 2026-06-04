<?php

namespace App\Domain\Travel\Support;

use App\Domain\Infrastructure\Pdf\PdfRenderer;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Settings\DataTransferObjects\CompanySettings;
use App\Domain\Travel\DataTransferObjects\TripBillingData;
use App\Domain\Travel\Models\Trip;
use App\Domain\Travel\Models\TripExpensePhoto;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Builds the travel-expense report PDF for sending to a client/supplier: each
 * expense shown in its original currency and converted into a chosen second
 * currency at the (editable) indicative rate, with rates and totals displayed.
 */
class TripExpenseReport
{
    public static function stream(Trip $trip, TripBillingData $billing, ?string $locale = null): StreamedResponse
    {
        $previous = App::getLocale();

        if ($locale !== null) {
            App::setLocale($locale);
        }

        try {
            // Build + render under the chosen locale so the category labels and
            // the report's static text come out in that language.
            $data = self::build($trip, $billing);
            $pdf = app(PdfRenderer::class)->render('pdf.trip-expense-report', $data);
        } finally {
            App::setLocale($previous);
        }

        return response()->streamDownload(
            fn () => print ($pdf),
            'relatorio-viagem-'.$trip->id.'.pdf',
            ['Content-Type' => 'application/pdf'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function build(Trip $trip, TripBillingData $billing): array
    {
        $trip->loadMissing(['company', 'traveler', 'expenses.photos']);

        $rows = [];
        $totalsBySource = [];
        $grandTotalTarget = 0;

        foreach ($trip->expenses->sortBy('expense_date') as $expense) {
            $rate = $billing->rateFor($expense->currency_code);
            $convertedMinor = TripFx::convertMinor($expense->amount, $rate);

            $totalsBySource[$expense->currency_code] = ($totalsBySource[$expense->currency_code] ?? 0) + $expense->amount;
            $grandTotalTarget += $convertedMinor;

            // Receipt images embedded inline with their expense.
            $receipts = [];
            foreach ($expense->photos as $photo) {
                $uri = self::photoDataUri($photo);
                if ($uri !== null) {
                    $receipts[] = $uri;
                }
            }

            $rows[] = [
                'date' => $expense->expense_date?->format('d/m/Y H:i'),
                'category' => $expense->category->getLabel(),
                'description' => $expense->description,
                'source_currency' => $expense->currency_code,
                'source_amount' => Money::format($expense->amount),
                'target_amount' => Money::format($convertedMinor),
                'receipts' => $receipts,
            ];
        }

        $sourceTotals = [];
        foreach ($totalsBySource as $currency => $amount) {
            $sourceTotals[] = ['currency' => $currency, 'amount' => Money::format($amount)];
        }

        // Exchange rates shown in the header (one line per converted currency).
        $fxRates = [];
        foreach (TripFx::distinctCurrencies($trip) as $code) {
            if ($code === $billing->billingCurrency) {
                continue;
            }
            $rateValue = rtrim(rtrim(number_format($billing->rateFor($code), 6, '.', ''), '0'), '.');
            $fxRates[] = $code.' → '.$billing->billingCurrency.' @ '.$rateValue;
        }

        return [
            'company' => self::companyHeader(),
            'trip' => [
                'title' => $trip->title,
                'company' => $trip->company_label,
                'traveler' => $trip->traveler?->name,
                'destination' => trim(implode(', ', array_filter([$trip->destination_city, $trip->destination_country]))),
                'start_date' => $trip->start_date?->format('d/m/Y'),
                'end_date' => $trip->end_date?->format('d/m/Y'),
                'reference' => 'TRIP-'.$trip->id,
            ],
            'target_currency' => $billing->billingCurrency,
            'fx_rates' => $fxRates,
            'rows' => $rows,
            'source_totals' => $sourceTotals,
            'grand_total_target' => Money::format($grandTotalTarget),
            'generated_at' => now()->format('d/m/Y H:i'),
        ];
    }

    /**
     * Company name + logo (as a data URI) from the company settings, matching
     * the header of the other PDF documents. Falls back to the app name when
     * the settings are not configured (e.g. tests).
     *
     * @return array{name: string, logo: ?string}
     */
    private static function companyHeader(): array
    {
        $name = (string) config('app.name');
        $logo = null;

        try {
            $settings = app(CompanySettings::class);
            $name = $settings->company_name ?: $name;
            $logo = self::logoDataUri($settings->logo_path);
        } catch (\Throwable) {
            // Settings not configured — keep the app-name fallback.
        }

        return ['name' => $name, 'logo' => $logo];
    }

    /**
     * Resolve the configured logo path to a base64 data URI (same candidate
     * resolution used by the shared PDF templates).
     */
    private static function logoDataUri(?string $path): ?string
    {
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

    /**
     * Read a receipt photo off its disk and return it as a base64 data URI so
     * dompdf can embed it without fetching from a URL. Returns null if missing.
     */
    private static function photoDataUri(TripExpensePhoto $photo): ?string
    {
        try {
            $disk = Storage::disk($photo->disk ?? 'public');

            if (! $photo->path || ! $disk->exists($photo->path)) {
                return null;
            }

            $mime = match (strtolower(pathinfo($photo->path, PATHINFO_EXTENSION))) {
                'png' => 'image/png',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                default => 'image/jpeg',
            };

            return 'data:'.$mime.';base64,'.base64_encode($disk->get($photo->path));
        } catch (\Throwable) {
            return null;
        }
    }
}
