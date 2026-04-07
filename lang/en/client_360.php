<?php

return [
    'title' => 'Client 360',
    'select_client' => 'Select client',
    'search_placeholder' => 'Type to search...',
    'no_results' => 'No clients match your search.',
    'no_client_selected' => 'Select a client to see their consolidated view.',
    'consolidated_with_branches' => 'matrix + :count branch(es) consolidated',

    'kpis' => [
        'active_inquiries' => 'Active inquiries',
        'open_invoices' => 'Open invoices',
        'pos_in_progress' => 'POs in progress',
        'shipments_in_transit' => 'Shipments in transit',
        'pending_receivables' => 'Pending receivables',
        'alerts' => 'Alerts',
        'no_alerts' => 'No alerts',
    ],

    'sections' => [
        'timeline' => 'Recent timeline',
        'inquiries' => 'Inquiries',
        'invoices' => 'Invoices / Proformas',
        'purchase_orders' => 'Purchase orders (suppliers)',
        'shipments' => 'Shipping / Logistics',
        'financial' => 'Financial',
    ],

    'columns' => [
        'reference' => 'Ref',
        'date' => 'Date',
        'issue_date' => 'Issue date',
        'valid_until' => 'Valid until',
        'value' => 'Value',
        'status' => 'Status',
        'product' => 'Product',
        'supplier' => 'Supplier',
        'linked_to' => 'Linked to',
        'mode' => 'Mode',
        'booking_bl' => 'Booking / BL',
        'origin_destination' => 'Origin → Destination',
        'etd_eta' => 'ETD / ETA',
        'po_value' => 'PO value',
        'invoice_value' => 'Invoice value',
        'freight' => 'Freight',
        'client_ref' => 'Client ref',
        'actions' => 'Actions',
    ],

    'financial' => [
        'receivables' => 'Receivables (client → Impex)',
        'payables' => 'Payables (Impex → suppliers)',
        'overdue' => 'overdue',
        'pending_items' => ':count pending item(s)',
        'recent_transactions' => 'Recent transactions',
        'inbound' => 'Received',
        'outbound' => 'Sent',
        'no_pending' => 'No pending items',
    ],

    'timeline' => [
        'inquiry_created' => 'Inquiry :ref received',
        'invoice_issued' => 'Proforma :ref issued',
        'invoice_confirmed' => 'Proforma :ref confirmed by client',
        'po_issued' => 'PO :ref issued to supplier',
        'shipment_departed' => 'Shipment :ref departed',
        'shipment_arrived' => 'Shipment :ref arrived',
        'payment_received' => 'Payment received :amount',
        'payment_sent' => 'Payment sent :amount',
        'no_events' => 'No recent events for this client.',
    ],

    'actions' => [
        'view_details' => 'View details',
    ],

    'empty' => [
        'inquiries' => 'No inquiries.',
        'invoices' => 'No invoices.',
        'purchase_orders' => 'No purchase orders.',
        'shipments' => 'No shipments.',
    ],
];
