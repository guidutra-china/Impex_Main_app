<?php

return [
    'title' => 'Custom Financial Report',
    'group_label' => 'Reports',
    'selection_title' => 'Row selection',
    'selection_help' => 'Uncheck any row to exclude it from the PDF. Changes apply immediately.',
    'items' => 'items',
    'exclusion_summary' => ':count item(s) excluded from the report.',
    'previous_versions' => 'Previously sent versions',
    'show_details' => 'Show details',
    'show_details_help' => '(installments, allocations, schedule items)',

    'actions' => [
        'clear_exclusions' => 'Clear all exclusions',
        'save_and_send' => 'Save & Send by Email',
    ],

    'placeholders' => [
        'message' => 'Add a custom message to the email (optional).',
    ],

    'notifications' => [
        'sent' => 'Email sent',
        'send_failed' => 'Email failed',
        'no_recipients' => 'Please provide at least one valid recipient.',
    ],
];
