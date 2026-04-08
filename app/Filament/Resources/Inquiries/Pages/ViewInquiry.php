<?php

namespace App\Filament\Resources\Inquiries\Pages;

use App\Filament\Concerns\HasOperationsHeaderActions;
use App\Filament\Resources\Inquiries\Concerns\InquiryHeaderActions;
use App\Filament\Resources\Inquiries\InquiryResource;
use Filament\Resources\Pages\ViewRecord;

class ViewInquiry extends ViewRecord
{
    use HasOperationsHeaderActions;
    use InquiryHeaderActions;

    protected static string $resource = InquiryResource::class;

    protected function getHeaderActions(): array
    {
        return $this->buildOperationsHeader();
    }
}
