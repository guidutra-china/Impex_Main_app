<?php

return [
    'title' => 'Contas a Pagar',
    'subtitle' => 'Pagamentos pendentes por período',
    'filters' => [
        'period' => 'Período',
        'preset_7_days' => 'Próx. 7 dias',
        'preset_30_days' => 'Próx. 30 dias',
        'preset_90_days' => 'Próx. 90 dias',
        'preset_this_month' => 'Este mês',
        'preset_next_month' => 'Próximo mês',
        'preset_custom' => 'Customizado',
        'date_from' => 'De',
        'date_to' => 'Até',
        'include_overdue' => 'Incluir vencidas',
        'include_paid' => 'Incluir pagas',
    ],
    'kpis' => [
        'overdue' => 'Vencido',
        'period' => 'No Período',
        'total' => 'Total a Pagar',
    ],
    'groups' => [
        'overdue' => 'Vencidas',
        'items_count' => ':count itens',
    ],
    'columns' => [
        'due_date' => 'Vencimento',
        'reference' => 'Referência',
        'description' => 'Descrição',
        'currency' => 'Moeda',
        'amount' => 'Valor',
        'paid' => 'Pago',
        'remaining' => 'Saldo',
        'status' => 'Status',
    ],
    'empty_state' => 'Nenhuma conta a pagar no período selecionado.',
];
