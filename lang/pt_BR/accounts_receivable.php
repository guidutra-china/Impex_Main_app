<?php

return [
    'title' => 'Contas a Receber',
    'subtitle' => 'Recebimentos previstos por período',
    'filters' => [
        'period' => 'Período',
        'preset_7_days' => 'Próximos 7 dias',
        'preset_30_days' => 'Próximos 30 dias',
        'preset_90_days' => 'Próximos 90 dias',
        'preset_this_month' => 'Este mês',
        'preset_next_month' => 'Próximo mês',
        'preset_custom' => 'Personalizado',
        'date_from' => 'De',
        'date_to' => 'Até',
        'include_overdue' => 'Incluir vencidos',
        'include_paid' => 'Incluir pagos',
        'include_freight' => 'Incluir frete',
        'include_commission' => 'Incluir comissão',
    ],
    'kpis' => [
        'overdue' => 'Vencido',
        'period' => 'No período',
        'total' => 'Total a receber',
    ],
    'groups' => [
        'overdue' => 'Vencidos',
        'no_due_date' => 'Sem vencimento',
        'items_count' => ':count itens',
    ],
    'columns' => [
        'due_date' => 'Vencimento',
        'reference' => 'Referência',
        'description' => 'Descrição',
        'supplier_invoice' => 'Invoice do fornecedor',
        'currency' => 'Moeda',
        'amount' => 'Valor',
        'paid' => 'Pago',
        'remaining' => 'Saldo',
        'status' => 'Status',
    ],
    'empty_state' => 'Nenhum recebível no período selecionado.',
];
