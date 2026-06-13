<?php

return [
    'sections' => [
        'general' => '通用报表',
        'by_company' => '按公司报表',
    ],
    'fields' => [
        'company' => '公司',
        'company_placeholder' => '请选择公司...',
        'company_hint' => '选择一家公司以查看对账单和自定义报表。',
    ],
    'cards' => [
        'receivables_summary' => [
            'title' => '收款汇总',
            'description' => '客户付款记录,包含总额、分配明细及 PDF/Excel 导出。',
        ],
        'payables_summary' => [
            'title' => '付款汇总',
            'description' => '支付给供应商和货代的款项,包含总额及导出。',
        ],
        'deal_breakdown' => [
            'title' => '交易利润分析',
            'description' => '按形式发票分析盈利能力:收入、成本、运费及利润率。',
        ],
        'cash_flow' => [
            'title' => '现金流预测',
            'description' => '即将到期和逾期的款项,含收支预测。',
        ],
        'expenses' => [
            'title' => '费用分类',
            'description' => '公司费用,按类别和周期性费用的月度汇总。',
        ],
        'statement' => [
            'title' => '公司对账单',
            'description' => '所选公司的单据和付款合并对账单。',
        ],
        'financial_report' => [
            'title' => '财务报表',
            'description' => '完整报表:发票、订单、发货、付款及费用。',
        ],
        'custom_report' => [
            'title' => '自定义报表',
            'description' => '自由选择报表板块、期间和明细行。',
        ],
    ],
];
