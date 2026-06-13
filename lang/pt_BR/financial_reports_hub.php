<?php

return [
    'sections' => [
        'general' => 'Relatórios Gerais',
        'by_company' => 'Relatórios por Empresa',
    ],
    'fields' => [
        'company' => 'Empresa',
        'company_placeholder' => 'Selecione uma empresa...',
        'company_hint' => 'Selecione uma empresa para acessar os relatórios de extrato e personalizado.',
    ],
    'cards' => [
        'receivables_summary' => [
            'title' => 'Resumo de Recebimentos',
            'description' => 'Pagamentos recebidos de clientes com totais, alocações e exportação PDF/Excel.',
        ],
        'payables_summary' => [
            'title' => 'Resumo de Pagamentos',
            'description' => 'Pagamentos efetuados a fornecedores e forwarders com totais e exportação.',
        ],
        'deal_breakdown' => [
            'title' => 'Lucro por Negócio',
            'description' => 'Análise de rentabilidade por Fatura Proforma: receita, custos, frete e margem.',
        ],
        'cash_flow' => [
            'title' => 'Fluxo de Caixa Projetado',
            'description' => 'Parcelas a vencer e vencidas com projeção de entradas e saídas.',
        ],
        'expenses' => [
            'title' => 'Despesas por Categoria',
            'description' => 'Despesas da empresa com resumo mensal por categoria e recorrências.',
        ],
        'statement' => [
            'title' => 'Extrato da Empresa',
            'description' => 'Extrato consolidado de documentos e pagamentos da empresa selecionada.',
        ],
        'financial_report' => [
            'title' => 'Relatório Financeiro',
            'description' => 'Relatório completo: faturas, pedidos, embarques, pagamentos e custos.',
        ],
        'custom_report' => [
            'title' => 'Relatório Personalizado',
            'description' => 'Monte um relatório escolhendo seções, período e linhas a incluir.',
        ],
    ],
];
