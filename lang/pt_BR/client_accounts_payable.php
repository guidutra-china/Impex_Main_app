<?php

return [
    'title' => 'Contas a Pagar (Cliente)',
    'generated_at' => 'Gerado em',
    'period' => 'Período',
    'scope' => 'Escopo',
    'scope_open' => 'Apenas em aberto',
    'scope_all' => 'Todos (incluindo pagos)',
    'company' => 'Cliente',
    'no_records' => 'Nenhuma parcela para os filtros selecionados.',
    'totals_by_currency' => 'Totais por moeda',
    'days_overdue' => 'dias em atraso',

    'columns' => [
        'document' => 'Documento',
        'client_reference' => 'Ref. Cliente',
        'installment' => 'Parcela',
        'due_date' => 'Vencimento',
        'amount' => 'Valor',
        'paid' => 'Pago',
        'balance' => 'Saldo',
        'currency' => 'Moeda',
        'status' => 'Status',
    ],

    'filters' => [
        'from' => 'Vencimento de',
        'to' => 'Vencimento até',
        'open_only' => 'Mostrar apenas parcelas em aberto',
        'language' => 'Idioma',
        'refresh' => 'Atualizar',
    ],

    'actions' => [
        'back' => 'Voltar',
        'download_pdf' => 'Baixar PDF',
        'download_excel' => 'Baixar Excel',
        'save_and_send' => 'Salvar e Enviar por Email',
    ],

    'notifications' => [
        'sent' => 'Email enviado',
        'send_failed' => 'Falha no envio',
        'no_recipients' => 'Informe ao menos um destinatário válido.',
    ],
];
