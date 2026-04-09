<?php

namespace App\Filament\Resources\Shipments\Pages;

use App\Filament\Resources\Shipments\ShipmentResource;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;

class ManagePackingList extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ShipmentResource::class;

    protected string $view = 'filament.resources.shipments.pages.manage-packing-list';

    protected static ?string $title = 'Packing List';

    protected static ?string $navigationLabel = 'Packing List';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-archive-box';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return 'Packing List · '.$this->getRecord()->reference;
    }

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToShipment')
                ->label('Back to Shipment')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(fn () => ShipmentResource::getUrl('view', ['record' => $this->getRecord()])),
            Action::make('editShipment')
                ->label('Edit Shipment')
                ->icon('heroicon-o-pencil-square')
                ->url(fn () => ShipmentResource::getUrl('edit', ['record' => $this->getRecord()])),
        ];
    }
}
