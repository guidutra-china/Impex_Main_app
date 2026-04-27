<?php

return [
    'title' => [
        'receivable' => 'Relatório de Contas a Receber',
        'payable' => 'Relatório de Contas a Pagar',
    ],

    'navigation' => [
        'receivable' => 'Relatório de Recebíveis',
        'payable' => 'Relatório de Pagáveis',
    ],

    'header_action' => 'Relatório',

    'empty_state' => 'Nenhum pagamento encontrado para os filtros selecionados.',
    'unallocated' => 'Não alocado',
    'credit' => 'crédito',

    'filters' => [
        'from' => 'De',
        'to' => 'Até',
        'companies' => 'Empresas',
        'currencies' => 'Moedas',
        'status' => 'Status',
        'status_approved_only' => 'Apenas aprovados',
        'status_include_pending' => 'Incluir pendentes',
        'reset' => 'Limpar filtros',
        'export' => 'Exportar Excel',
    ],

    'kpi' => [
        'total_payments' => 'Total de Pagamentos',
        'totals_by_currency' => 'Totais por Moeda',
        'period' => 'Período',
    ],

    'sections' => [
        'by_company' => 'Por Empresa',
        'by_month' => 'Por Mês',
        'allocations' => 'Detalhamento por Pagamento',
    ],

    'columns' => [
        'company' => 'Empresa',
        'payment_count' => 'Nº Pagamentos',
        'totals_by_currency' => 'Totais por Moeda',
        'last_payment' => 'Último Pagamento',
        'month' => 'Mês',
        'date' => 'Data',
        'reference' => 'Referência',
        'status' => 'Status',
        'currency' => 'Moeda',
        'amount' => 'Valor',
        'allocated_to' => 'Alocado em',
        'allocation_label' => 'Parcela / Estágio',
        'allocation_amount' => 'Valor Alocado',
    ],
];
