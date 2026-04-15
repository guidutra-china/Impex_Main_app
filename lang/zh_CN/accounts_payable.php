<?php

return [
    'title' => '应付账款',
    'subtitle' => '按期间的待付款项',
    'filters' => [
        'period' => '期间',
        'preset_7_days' => '未来 7 天',
        'preset_30_days' => '未来 30 天',
        'preset_90_days' => '未来 90 天',
        'preset_this_month' => '本月',
        'preset_next_month' => '下月',
        'preset_custom' => '自定义',
        'date_from' => '从',
        'date_to' => '至',
        'include_overdue' => '包含逾期',
        'include_paid' => '包含已付',
        'include_freight' => '包含运费',
        'include_commission' => '包含佣金',
    ],
    'kpis' => [
        'overdue' => '逾期',
        'period' => '本期',
        'total' => '应付总额',
    ],
    'groups' => [
        'overdue' => '逾期',
        'no_due_date' => '无到期日',
        'items_count' => ':count 项',
    ],
    'columns' => [
        'due_date' => '到期日',
        'reference' => '参考',
        'description' => '说明',
        'client_reference' => '客户参考',
        'currency' => '货币',
        'amount' => '金额',
        'paid' => '已付',
        'remaining' => '余额',
        'status' => '状态',
    ],
    'empty_state' => '所选期间内无应付款项。',
];
