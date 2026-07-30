<?php

namespace App\Filament\Actions;

use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;

class QuickViewAction
{
    /**
     * Slide-over com o infolist do resource + relation managers em abas,
     * aberto pelo clique na linha da tabela, sem sair da listagem.
     * Usado nos resources de Operations.
     *
     * @param  class-string<\Filament\Resources\Resource>  $resource
     */
    public static function make(string $resource): ViewAction
    {
        return ViewAction::make('quickView')
            ->label(__('tables.quick_view'))
            ->url(null)
            ->slideOver()
            ->modalWidth(Width::SixExtraLarge)
            ->modalHeading(fn (Model $record): string => (string) $resource::getRecordTitle($record))
            ->infolist(fn (Schema $schema): Schema => $resource::infolist($schema))
            ->modalContentFooter(function (Model $record) use ($resource): ?View {
                $pageClass = self::viewPageClass($resource);

                if ($pageClass === null || $resource::getRelations() === []) {
                    return null;
                }

                return view('filament.actions.quick-view-relations', [
                    'record' => $record,
                    'relationManagers' => $resource::getRelations(),
                    'pageClass' => $pageClass,
                ]);
            })
            ->extraModalFooterActions(fn (Model $record): array => array_values(array_filter([
                Action::make('openViewPage')
                    ->label(__('tables.open_full_page'))
                    ->url($resource::getUrl('view', ['record' => $record]))
                    ->color('gray'),
                $resource::canEdit($record)
                    ? Action::make('openEditPage')
                        ->label(__('filament-actions::edit.single.label'))
                        ->url($resource::getUrl('edit', ['record' => $record]))
                    : null,
            ])));
    }

    /**
     * @param  class-string<\Filament\Resources\Resource>  $resource
     * @return class-string<\Filament\Resources\Pages\ViewRecord>|null
     */
    protected static function viewPageClass(string $resource): ?string
    {
        $pages = $resource::getPages();

        return isset($pages['view']) ? $pages['view']->getPage() : null;
    }
}
