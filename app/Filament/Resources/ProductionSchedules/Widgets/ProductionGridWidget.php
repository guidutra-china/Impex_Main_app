<?php

namespace App\Filament\Resources\ProductionSchedules\Widgets;

use App\Domain\Planning\Models\ProductionSchedule;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;

class ProductionGridWidget extends Widget
{
    protected string $view = 'filament.production-schedule.production-grid-widget';

    public ?Model $record = null;

    protected int | string | array $columnSpan = 'full';
}
