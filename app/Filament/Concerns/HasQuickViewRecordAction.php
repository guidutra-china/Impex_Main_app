<?php

namespace App\Filament\Concerns;

use Filament\Tables\Table;

/**
 * Faz o clique na linha da listagem abrir o slide-over de visualização rápida
 * (QuickViewAction) em vez de navegar para a página View/Edit.
 *
 * Precisa sobrescrever makeTable() porque o ListRecords aplica seus próprios
 * recordAction()/recordUrl() depois da configuração da tabela do resource.
 */
trait HasQuickViewRecordAction
{
    protected function makeTable(): Table
    {
        return parent::makeTable()
            ->recordAction('quickView')
            ->recordUrl(null);
    }
}
