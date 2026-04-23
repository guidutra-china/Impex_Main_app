<?php

return [
    'title' => 'Accounts Payable (Client)',
    'generated_at' => 'Generated',
    'period' => 'Period',
    'scope' => 'Scope',
    'scope_open' => 'Open only',
    'scope_all' => 'All (including paid)',
    'company' => 'Client',
    'no_records' => 'No installments for the selected filters.',
    'totals_by_currency' => 'Totals by currency',
    'days_overdue' => 'days overdue',

    'columns' => [
        'document' => 'Document',
        'client_reference' => 'Client Ref.',
        'installment' => 'Installment',
        'due_date' => 'Due Date',
        'amount' => 'Amount',
        'paid' => 'Paid',
        'balance' => 'Balance',
        'currency' => 'Currency',
        'status' => 'Status',
    ],

    'filters' => [
        'from' => 'Due from',
        'to' => 'Due to',
        'open_only' => 'Show only open installments',
        'language' => 'Language',
        'refresh' => 'Refresh',
    ],

    'actions' => [
        'back' => 'Back',
        'download_pdf' => 'Download PDF',
        'save_and_send' => 'Save & Send by Email',
    ],

    'notifications' => [
        'sent' => 'Email sent',
        'send_failed' => 'Email failed',
        'no_recipients' => 'Please provide at least one valid recipient.',
    ],
];
