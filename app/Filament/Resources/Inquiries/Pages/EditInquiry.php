<?php

namespace App\Filament\Resources\Inquiries\Pages;

use App\Filament\Concerns\HasOperationsHeaderActions;
use App\Filament\Pages\Concerns\HasSaveAndReturnFormActions;
use App\Filament\Resources\Inquiries\Concerns\InquiryHeaderActions;
use App\Filament\Resources\Inquiries\InquiryResource;
use Filament\Resources\Pages\EditRecord;

class EditInquiry extends EditRecord
{
    use HasOperationsHeaderActions;
    use HasSaveAndReturnFormActions;
    use InquiryHeaderActions;

    protected static string $resource = InquiryResource::class;

    protected function getHeaderActions(): array
    {
        return $this->buildOperationsHeader();
    }
}
