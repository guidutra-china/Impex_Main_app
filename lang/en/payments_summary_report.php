<?php

return [
    'title' => [
        'receivable' => 'Accounts Receivable Report',
        'payable' => 'Accounts Payable Report',
    ],

    'navigation' => [
        'receivable' => 'Receivables Report',
        'payable' => 'Payables Report',
    ],

    'header_action' => 'Report',

    'empty_state' => 'No payments found for the selected filters.',
    'unallocated' => 'Unallocated',
    'credit' => 'credit',

    'filters' => [
        'from' => 'From',
        'to' => 'To',
        'companies' => 'Companies',
        'currencies' => 'Currencies',
        'status' => 'Status',
        'status_approved_only' => 'Approved only',
        'status_include_pending' => 'Include pending',
        'reset' => 'Reset filters',
        'export' => 'Export Excel',
    ],

    'kpi' => [
        'total_payments' => 'Total Payments',
        'totals_by_currency' => 'Totals by Currency',
        'period' => 'Period',
    ],

    'sections' => [
        'by_company' => 'By Company',
        'by_month' => 'By Month',
        'allocations' => 'Payment Detail',
    ],

    'columns' => [
        'company' => 'Company',
        'payment_count' => 'Payments',
        'totals_by_currency' => 'Totals by Currency',
        'last_payment' => 'Last Payment',
        'month' => 'Month',
        'date' => 'Date',
        'reference' => 'Reference',
        'status' => 'Status',
        'currency' => 'Currency',
        'amount' => 'Amount',
        'allocated_to' => 'Allocated to',
        'allocation_label' => 'Installment / Stage',
        'allocation_amount' => 'Allocated Amount',
    ],

    'client_ref' => 'Client Ref.',
];
