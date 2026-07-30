<?php

namespace App\Filament\Resources\Inquiries\Pages;

use App\Filament\Concerns\HasQuickViewRecordAction;
use App\Filament\Resources\Inquiries\InquiryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInquiries extends ListRecords
{
    use HasQuickViewRecordAction;

    protected static string $resource = InquiryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
