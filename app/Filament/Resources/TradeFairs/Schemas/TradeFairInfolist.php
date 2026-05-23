<?php

namespace App\Filament\Resources\TradeFairs\Schemas;

use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\CRM\Models\Company;
use App\Domain\TradeFairs\Models\TradeFair;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;

class TradeFairInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Dados da feira')
                    ->schema([
                        TextEntry::make('name')
                            ->label('Nome')
                            ->weight(FontWeight::Bold)
                            ->size(TextSize::Large),
                        TextEntry::make('location')
                            ->label('Local')
                            ->badge()
                            ->color('gray')
                            ->placeholder('—'),
                        TextEntry::make('start_date')
                            ->label('Início')
                            ->date('d/m/Y')
                            ->placeholder('—'),
                        TextEntry::make('end_date')
                            ->label('Término')
                            ->date('d/m/Y')
                            ->placeholder('—'),
                        TextEntry::make('createdBy.name')
                            ->label('Criado por')
                            ->placeholder('—'),
                        TextEntry::make('notes')
                            ->label('Notas')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpan(['lg' => 2]),

                Section::make('Captura')
                    ->schema([
                        TextEntry::make('suppliers_total')
                            ->label('Fornecedores capturados')
                            ->state(fn (TradeFair $record) => Company::where('trade_fair_id', $record->id)->count())
                            ->badge()
                            ->color('primary'),
                        TextEntry::make('suppliers_with_card')
                            ->label('Com cartão de visita')
                            ->state(function (TradeFair $record) {
                                $total = Company::where('trade_fair_id', $record->id)->count();
                                if ($total === 0) {
                                    return '0';
                                }

                                $withCard = Company::where('trade_fair_id', $record->id)
                                    ->whereNotNull('business_card_path')
                                    ->count();

                                return $withCard.' / '.$total;
                            }),
                        TextEntry::make('products_total')
                            ->label('Produtos capturados')
                            ->state(function (TradeFair $record) {
                                return CompanyProduct::query()
                                    ->join('companies', 'companies.id', '=', 'company_product.company_id')
                                    ->where('companies.trade_fair_id', $record->id)
                                    ->distinct('company_product.product_id')
                                    ->count('company_product.product_id');
                            })
                            ->badge()
                            ->color('info'),
                    ])
                    ->columnSpan(['lg' => 1]),
            ])
            ->columns(3);
    }
}
