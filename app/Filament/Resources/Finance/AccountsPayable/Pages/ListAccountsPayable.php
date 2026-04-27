<?php

namespace App\Filament\Resources\Finance\AccountsPayable\Pages;

use App\Filament\Resources\Finance\AccountsPayable\AccountsPayableResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAccountsPayable extends ListRecords
{
    protected static string $resource = AccountsPayableResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('report')
                ->label(__('payments_summary_report.header_action'))
                ->icon('heroicon-o-chart-bar-square')
                ->color('gray')
                ->url(static::$resource::getUrl('report')),
            CreateAction::make(),
        ];
    }
}
