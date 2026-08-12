<?php

namespace App\Filament\Pages\Concerns;

use Filament\Actions\Action;

/**
 * Botões de salvar uniformes para páginas de edição:
 * - "Salvar e Retornar" (primário): salva e volta para a listagem.
 * - "Salvar": salva, sai do modo edição e abre a View do registro
 *   (oculto quando o resource não tem página View).
 *
 * Uma página pode redefinir getRedirectUrl()/getFormActions() localmente,
 * pois métodos da própria classe têm precedência sobre os do trait.
 */
trait HasSaveAndReturnFormActions
{
    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction()->label(__('forms.labels.save_and_return')),
            $this->getSaveAndViewFormAction(),
            $this->getCancelFormAction(),
        ];
    }

    protected function getSaveAndViewFormAction(): Action
    {
        return Action::make('saveAndView')
            ->label(__('forms.labels.save'))
            ->color('gray')
            ->visible(fn (): bool => static::getResource()::hasPage('view'))
            ->action(fn () => $this->saveAndView());
    }

    public function saveAndView(): void
    {
        $this->save(shouldRedirect: false);

        $this->redirect(static::getResource()::getUrl('view', ['record' => $this->getRecord()]));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
