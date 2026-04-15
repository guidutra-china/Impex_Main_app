<?php

return [
    'title' => 'Accounts Receivable',
    'subtitle' => 'Expected receipts by period',
    'filters' => [
        'period' => 'Period',
        'preset_7_days' => 'Next 7 days',
        'preset_30_days' => 'Next 30 days',
        'preset_90_days' => 'Next 90 days',
        'preset_this_month' => 'This month',
        'preset_next_month' => 'Next month',
        'preset_custom' => 'Custom',
        'date_from' => 'From',
        'date_to' => 'To',
        'include_overdue' => 'Include overdue',
        'include_paid' => 'Include paid',
        'include_freight' => 'Include freight',
        'include_commission' => 'Include commission',
    ],
    'kpis' => [
        'overdue' => 'Overdue',
        'period' => 'In Period',
        'total' => 'Total Due',
    ],
    'groups' => [
        'overdue' => 'Overdue',
        'no_due_date' => 'No due date',
        'items_count' => ':count items',
    ],
    'columns' => [
        'due_date' => 'Due date',
        'reference' => 'Reference',
        'description' => 'Description',
        'supplier_invoice' => 'Supplier invoice',
        'currency' => 'Currency',
        'amount' => 'Amount',
        'paid' => 'Paid',
        'remaining' => 'Balance',
        'status' => 'Status',
    ],
    'empty_state' => 'No receivables in the selected period.',
];
