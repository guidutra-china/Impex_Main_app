<?php

namespace App\Filament\Fair\Pages;

use App\Domain\CRM\Models\Company;
use App\Domain\TradeFairs\Models\TradeFair;
use BackedEnum;
use Filament\Pages\Page;
use UnitEnum;

class FairDashboard extends Page
{
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-home';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?int $navigationSort = -2;

    protected string $view = 'filament.fair.pages.fair-dashboard';

    public function getTitle(): string
    {
        return 'Fair Registration';
    }

    public function getActiveFair(): ?TradeFair
    {
        $fairId = session('active_trade_fair_id');

        if (! $fairId) {
            return null;
        }

        return TradeFair::find($fairId);
    }

    public function getRecentSuppliers(): \Illuminate\Database\Eloquent\Collection
    {
        $fairId = session('active_trade_fair_id');

        if (! $fairId) {
            return Company::query()
                ->whereNotNull('trade_fair_id')
                ->with(['contacts', 'categories'])
                ->orderByDesc('created_at')
                ->limit(10)
                ->get();
        }

        return Company::query()
            ->where('trade_fair_id', $fairId)
            ->with(['contacts', 'categories'])
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();
    }

    public function getTotalSuppliersToday(): int
    {
        $fairId = session('active_trade_fair_id');

        $query = Company::query()->whereDate('created_at', today());

        if ($fairId) {
            $query->where('trade_fair_id', $fairId);
        } else {
            $query->whereNotNull('trade_fair_id');
        }

        return $query->count();
    }

    public function getTotalProductsToday(): int
    {
        $fairId = session('active_trade_fair_id');

        $query = \App\Domain\Catalog\Models\Product::query()
            ->whereDate('created_at', today());

        if ($fairId) {
            $query->whereHas('companies', function ($q) use ($fairId) {
                $q->where('trade_fair_id', $fairId);
            });
        }

        return $query->count();
    }

    public function clearActiveFair(): void
    {
        session()->forget('active_trade_fair_id');
        $this->redirect(static::getUrl());
    }
}
