<?php

namespace App\Http\Controllers\Api\Trips;

use App\Domain\Infrastructure\Support\Money;
use App\Domain\Travel\Actions\StoreTripAction;
use App\Domain\Travel\DataTransferObjects\TripData;
use App\Domain\Travel\DataTransferObjects\TripExpenseData;
use App\Domain\Travel\Enums\TripStatus;
use App\Domain\Travel\Models\Trip;
use App\Http\Requests\Api\Trips\StoreTripRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TripController
{
    /**
     * Recent trips for the authenticated traveler — used by the mobile app to
     * reconcile its offline queue against what the server already has.
     */
    public function index(Request $request): JsonResponse
    {
        $trips = Trip::query()
            ->with(['expenses', 'company'])
            ->where('user_id', $request->user()->id)
            ->latest('start_date')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => $trips->map(fn (Trip $trip) => $this->summarize($trip))->all(),
        ]);
    }

    /**
     * Full detail of the traveler's DRAFT trips so the mobile app can recover
     * (re-download) them onto a new device / fresh PWA install and keep editing.
     * Only mobile-originated trips (with a client_uuid) are recoverable.
     */
    public function recoverable(Request $request): JsonResponse
    {
        $trips = Trip::query()
            ->with(['expenses.photos', 'company'])
            ->where('user_id', $request->user()->id)
            ->where('status', TripStatus::DRAFT)
            ->whereNotNull('client_uuid')
            ->latest('start_date')
            ->limit(50)
            ->get();

        $data = $trips->map(function (Trip $trip) {
            $expenses = $trip->expenses->map(function ($expense) {
                // Backfill a client_uuid for any expense added in the admin so it
                // re-syncs without duplicating.
                if (empty($expense->client_uuid)) {
                    $expense->update(['client_uuid' => (string) Str::uuid()]);
                }

                return [
                    'client_uuid' => $expense->client_uuid,
                    'category' => $expense->category->value,
                    'description' => $expense->description,
                    'amount' => Money::toMajor($expense->amount),
                    'currency_code' => $expense->currency_code,
                    'expense_date' => $expense->expense_date?->format('Y-m-d\TH:i'),
                    'photos' => $expense->photos->map(fn ($photo) => $photo->url)->filter()->values(),
                ];
            })->values();

            return [
                'client_uuid' => $trip->client_uuid,
                'server_id' => $trip->id,
                'title' => $trip->title,
                'is_internal' => $trip->is_internal,
                'company_id' => $trip->company_id,
                'company_name' => $trip->is_internal ? null : $trip->company?->name,
                'destination_city' => $trip->destination_city,
                'destination_country' => $trip->destination_country,
                'start_date' => $trip->start_date?->toDateString(),
                'end_date' => $trip->end_date?->toDateString(),
                'notes' => $trip->notes,
                'expenses' => $expenses,
            ];
        })->values();

        return response()->json(['data' => $data]);
    }

    public function store(StoreTripRequest $request, StoreTripAction $action): JsonResponse
    {
        $validated = $request->validated();

        $expenses = [];
        foreach ($validated['expenses'] ?? [] as $idx => $expense) {
            $photos = $request->file("expenses.$idx.photos") ?? [];

            $expenses[] = new TripExpenseData(
                category: $expense['category'],
                description: $expense['description'] ?? null,
                amount: Money::toMinor((float) $expense['amount']),
                currencyCode: $expense['currency_code'],
                expenseDate: $expense['expense_date'],
                photos: array_values($photos),
                clientUuid: $expense['client_uuid'] ?? null,
            );
        }

        $isInternal = (bool) ($validated['is_internal'] ?? false);

        $data = new TripData(
            title: $validated['title'],
            startDate: $validated['start_date'],
            companyId: $isInternal ? null : ($validated['company_id'] ?? null),
            isInternal: $isInternal,
            userId: $request->user()->id,
            destinationCity: $validated['destination_city'] ?? null,
            destinationCountry: $validated['destination_country'] ?? null,
            endDate: $validated['end_date'] ?? null,
            notes: $validated['notes'] ?? null,
            clientUuid: $validated['client_uuid'] ?? null,
            expenses: $expenses,
            finalize: (bool) ($validated['finalize'] ?? false),
            deletedExpenseUuids: array_values($validated['deleted_expense_uuids'] ?? []),
        );

        $trip = $action->execute($data);

        return response()->json([
            'trip' => $this->summarize($trip->load(['expenses', 'company'])),
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function summarize(Trip $trip): array
    {
        return [
            'id' => $trip->id,
            'client_uuid' => $trip->client_uuid,
            'title' => $trip->title,
            'status' => $trip->status->value,
            'is_internal' => $trip->is_internal,
            'company_id' => $trip->company_id,
            'company_label' => $trip->company_label,
            'destination_city' => $trip->destination_city,
            'destination_country' => $trip->destination_country,
            'start_date' => $trip->start_date?->toDateString(),
            'end_date' => $trip->end_date?->toDateString(),
            'expense_count' => $trip->expenses->count(),
            'totals_by_currency' => collect($trip->totals_by_currency)
                ->map(fn (int $amount, string $code) => [
                    'currency' => $code,
                    'amount' => Money::toMajor($amount),
                ])
                ->values()
                ->all(),
        ];
    }
}
