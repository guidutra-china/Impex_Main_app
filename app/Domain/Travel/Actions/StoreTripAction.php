<?php

namespace App\Domain\Travel\Actions;

use App\Domain\Travel\DataTransferObjects\TripData;
use App\Domain\Travel\DataTransferObjects\TripExpenseData;
use App\Domain\Travel\Enums\TripStatus;
use App\Domain\Travel\Models\Trip;
use App\Domain\Travel\Models\TripExpense;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class StoreTripAction
{
    /**
     * Idempotent upsert used by the offline mobile sync. A trip is created once
     * (as DRAFT) and identified by its client_uuid; subsequent syncs only add
     * the expenses the server does not have yet (matched by expense client_uuid)
     * and optionally finalize the trip (DRAFT → SUBMITTED) at the end of travel.
     */
    public function execute(TripData $data): Trip
    {
        return DB::transaction(function () use ($data) {
            $trip = $this->resolveTrip($data);

            foreach ($data->expenses as $expense) {
                $this->upsertExpense($trip, $expense);
            }

            // Expenses removed on the device (mistakes happen) are deleted here.
            foreach ($data->deletedExpenseUuids as $uuid) {
                $trip->expenses()->where('client_uuid', $uuid)->get()->each->delete();
            }

            if ($data->finalize && $trip->status === TripStatus::DRAFT) {
                $trip->update(['status' => TripStatus::SUBMITTED]);
            }

            return $trip;
        });
    }

    private function resolveTrip(TripData $data): Trip
    {
        if ($data->clientUuid !== null) {
            $existing = Trip::where('client_uuid', $data->clientUuid)->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        return Trip::create([
            'client_uuid' => $data->clientUuid,
            'user_id' => $data->userId,
            'company_id' => $data->isInternal ? null : $data->companyId,
            'is_internal' => $data->isInternal,
            'title' => $data->title,
            'destination_city' => $data->destinationCity,
            'destination_country' => $data->destinationCountry,
            'start_date' => $data->startDate,
            'end_date' => $data->endDate,
            'status' => TripStatus::DRAFT,
            'notes' => $data->notes,
        ]);
    }

    private function upsertExpense(Trip $trip, TripExpenseData $data): void
    {
        $attributes = [
            'category' => $data->category,
            'description' => $data->description,
            'amount' => $data->amount,
            'currency_code' => $data->currencyCode,
            'expense_date' => $data->expenseDate,
        ];

        // An expense the server already has (matched by client_uuid) is an edit:
        // update its fields and replace its receipts with the ones sent. New
        // expenses are simply created. The device only re-sends expenses that
        // changed, so untouched ones keep their photos.
        $existing = $data->clientUuid !== null
            ? TripExpense::where('client_uuid', $data->clientUuid)->first()
            : null;

        if ($existing !== null) {
            $existing->update($attributes);
            $existing->photos()->get()->each->delete();
            $this->storeExpensePhotos($existing, $data->photos);

            return;
        }

        $expense = $trip->expenses()->create(array_merge(
            ['client_uuid' => $data->clientUuid],
            $attributes,
        ));

        $this->storeExpensePhotos($expense, $data->photos);
    }

    /**
     * Persist receipt photos as trip_expense_photos rows. The photo at
     * sort_order 0 is flagged primary (receipt cover).
     *
     * @param  array<int, UploadedFile|string>  $photos
     */
    private function storeExpensePhotos(TripExpense $expense, array $photos): void
    {
        $sortOrder = 0;

        foreach ($photos as $photo) {
            $path = $this->storeFile($photo, 'trip-receipts');
            if ($path === null) {
                continue;
            }

            $expense->photos()->create([
                'disk' => 'public',
                'path' => $path,
                'sort_order' => $sortOrder,
                'is_primary' => $sortOrder === 0,
            ]);

            $sortOrder++;
        }
    }

    /**
     * Persist an uploaded file to the public disk under the given prefix,
     * or pass through a pre-existing storage path string unchanged.
     */
    private function storeFile(UploadedFile|string|null $file, string $prefix): ?string
    {
        if ($file === null) {
            return null;
        }

        if (is_string($file)) {
            return $file !== '' ? $file : null;
        }

        $extension = $file->getClientOriginalExtension() ?: 'jpg';
        $filename = $prefix.'/'.Str::uuid().'.'.$extension;

        Storage::disk('public')->put(
            $filename,
            file_get_contents($file->getRealPath()),
        );

        return $filename;
    }
}
