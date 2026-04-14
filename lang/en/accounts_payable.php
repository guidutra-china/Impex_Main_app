<?php

return [
    'title' => 'Accounts Payable',
    'subtitle' => 'Pending payments by period',
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
        'client_reference' => 'Client ref.',
        'currency' => 'Currency',
        'amount' => 'Amount',
        'paid' => 'Paid',
        'remaining' => 'Balance',
        'status' => 'Status',
    ],
    'empty_state' => 'No payables in the selected period.',
];
