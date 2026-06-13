<?php

return [
    'sections' => [
        'general' => 'General Reports',
        'by_company' => 'Reports by Company',
    ],
    'fields' => [
        'company' => 'Company',
        'company_placeholder' => 'Select a company...',
        'company_hint' => 'Select a company to access the statement and custom reports.',
    ],
    'cards' => [
        'receivables_summary' => [
            'title' => 'Incoming Payments Summary',
            'description' => 'Payments received from clients with totals, allocations and PDF/Excel export.',
        ],
        'payables_summary' => [
            'title' => 'Outgoing Payments Summary',
            'description' => 'Payments made to suppliers and forwarders with totals and export.',
        ],
        'deal_breakdown' => [
            'title' => 'Deal Profitability',
            'description' => 'Profitability analysis per Proforma Invoice: revenue, costs, freight and margin.',
        ],
        'cash_flow' => [
            'title' => 'Projected Cash Flow',
            'description' => 'Upcoming and overdue installments with inflow/outflow projection.',
        ],
        'expenses' => [
            'title' => 'Expenses by Category',
            'description' => 'Company expenses with monthly summary by category and recurrences.',
        ],
        'statement' => [
            'title' => 'Company Statement',
            'description' => 'Consolidated statement of documents and payments for the selected company.',
        ],
        'financial_report' => [
            'title' => 'Financial Report',
            'description' => 'Full report: invoices, orders, shipments, payments and costs.',
        ],
        'custom_report' => [
            'title' => 'Custom Report',
            'description' => 'Build a report choosing sections, period and rows to include.',
        ],
    ],
];
