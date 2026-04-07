<?php

return [
    'title' => 'Cliente 360',
    'select_client' => 'Selecionar cliente',
    'no_client_selected' => 'Selecione um cliente para ver a visão consolidada.',
    'consolidated_with_branches' => 'matriz + :count filial(is) consolidadas',

    'kpis' => [
        'active_inquiries' => 'Inquiries ativos',
        'open_invoices' => 'Invoices abertas',
        'pos_in_progress' => 'POs em andamento',
        'shipments_in_transit' => 'Shipments em trânsito',
        'pending_receivables' => 'Recebíveis pendentes',
        'alerts' => 'Alertas',
        'no_alerts' => 'Sem alertas',
    ],

    'sections' => [
        'timeline' => 'Timeline de eventos',
        'inquiries' => 'Inquiries',
        'invoices' => 'Invoices / Proformas',
        'purchase_orders' => 'Pedidos de compra (fornecedores)',
        'shipments' => 'Logística / Embarques',
        'financial' => 'Financeiro',
    ],

    'columns' => [
        'reference' => 'Ref',
        'date' => 'Data',
        'issue_date' => 'Emissão',
        'valid_until' => 'Validade',
        'value' => 'Valor',
        'status' => 'Status',
        'product' => 'Produto',
        'supplier' => 'Fornecedor',
        'linked_to' => 'Vinculado a',
        'mode' => 'Modal',
        'booking_bl' => 'Booking / BL',
        'origin_destination' => 'Origem → Destino',
        'etd_eta' => 'ETD / ETA',
        'po_value' => 'Valor PO',
        'invoice_value' => 'Valor invoice',
        'freight' => 'Frete',
        'client_ref' => 'Ref. cliente',
        'actions' => 'Ações',
    ],

    'financial' => [
        'receivables' => 'A receber (cliente → Impex)',
        'payables' => 'A pagar (Impex → fornecedores)',
        'overdue' => 'vencido(s)',
        'pending_items' => ':count parcela(s) pendente(s)',
        'recent_transactions' => 'Transações recentes',
        'inbound' => 'Recebido',
        'outbound' => 'Enviado',
        'no_pending' => 'Sem parcelas pendentes',
    ],

    'timeline' => [
        'inquiry_created' => 'Inquiry :ref recebido',
        'invoice_issued' => 'Proforma :ref emitida',
        'invoice_confirmed' => 'Proforma :ref confirmada pelo cliente',
        'po_issued' => 'PO :ref emitida para fornecedor',
        'shipment_departed' => 'Shipment :ref embarcado',
        'shipment_arrived' => 'Shipment :ref chegou',
        'payment_received' => 'Pagamento recebido :amount',
        'payment_sent' => 'Pagamento enviado :amount',
        'no_events' => 'Sem eventos recentes para este cliente.',
    ],

    'actions' => [
        'view_details' => 'Ver detalhes',
    ],

    'empty' => [
        'inquiries' => 'Sem inquiries.',
        'invoices' => 'Sem invoices.',
        'purchase_orders' => 'Sem pedidos de compra.',
        'shipments' => 'Sem embarques.',
    ],
];
