<?php

return [
    // Financial Stats Overview
    'financial_stats' => [
        'receivables' => '应收款',
        'receivables_from_clients' => '应收款（来自客户）',
        'from_clients' => '来自客户',
        'payables' => '应付款',
        'payables_to_suppliers' => '应付款（给供应商）',
        'to_suppliers' => '给供应商',
        'payment_schedule' => '付款计划',
        'net_positive' => '净正值',
        'net_negative' => '净负值',
        'net_to_receive' => '净应收',
        'net_to_pay' => '净应付',
    ],

    // Cash Flow Projection
    'cash_flow' => [
        'heading' => '现金流预测',
        'description' => '基于付款计划到期日的预计流入和流出',
        'period' => '期间',
        'inflow_pi' => '流入（PI）',
        'outflow_po' => '流出（PO）',
        'inflow' => '流入',
        'outflow' => '流出',
        'net' => '净额',
        'overdue' => '逾期',
        'before_today' => '今日之前',
        'this_week' => '本周',
        'next_week' => '下周',
        'no_due_date_warning' => '这些项目未设置到期日，未包含在上述预测中。',
    ],

    // Document Financial Summary (PI Stats / PO Stats)
    'document_summary' => [
        'financial_summary' => '财务摘要',
        'invoice_total' => '发票总额',
        'cost_margin' => '成本 / 利润',
        'margin' => '利润',
        'paid' => '已付款',
        'remaining' => '剩余',
        'credits' => '贷项',
        'total_due' => '应付总额',
        'net_due' => '净应付',
        'overdue' => '逾期',
        'next' => '下一个',
        'outstanding' => '未结清',
        'fully_paid' => '已全额付款',
        'from_client_available' => '来自该客户 — 可分配',
        'to_supplier_available' => '给该供应商 — 可分配',
    ],

    // Company Financial Statement
    'financial_statement' => [
        'client_receivables' => '客户报表 — 应收款',
        'supplier_payables' => '供应商报表 — 应付款',
        'overdue' => '逾期',
    ],

    // Landed Cost Calculator
    'landed_cost' => [
        'heading' => '到岸成本计算器',
        'description' => '本次货运的完整成本分析',
        'freight' => '运费',
        'insurance' => '保险',
        'customs_duties' => '海关 / 关税',
        'inspection_testing' => '检验 / 测试',
        'packaging' => '包装',
        'other_costs' => '其他费用',
        'gross_profit' => '毛利润',
    ],

    // Product Summary
    'product_summary' => [
        'suppliers' => '供应商',
        'clients' => '客户',
        'variants' => '变体',
        'variant' => '变体',
        'base_price' => '基础价格',
        'manufacturing_cost' => '制造成本',
        'selling_price' => '销售价格',
        'markup' => '加价',
        'preferred_supplier' => '首选供应商',
        'preferred_client' => '首选客户',
        'weight' => '重量',
        'dimensions' => '尺寸',
        'material' => '材质',
        'color' => '颜色',
        'pcs_per_carton' => '每箱件数',
        'lead_time' => '交货期',
    ],

    // Pipeline Counts
    'pipeline' => [
        'operations_pipeline' => '运营管道',
    ],

    // Operational Alerts
    'alerts' => [
        'action_required' => '需要操作',
        'all_clear' => '一切正常 — 无待处理事项。',
        'overdue_payments_desc' => '付款计划项目逾期且未全额付款',
        'view_payments' => '查看付款',
        'pending_approval_desc' => '已提交付款，等待经理审核',
        'review_payments' => '审核付款',
        'finalized_pi_desc' => '形式发票已确认，但尚未创建采购订单',
        'view_pis' => '查看PI',
        'stalled_po_desc' => '生产中的采购订单超过15天无活动',
        'view_pos' => '查看PO',
        'open_inquiries_desc' => '可能需要跟进的客户询价',
        'view_inquiries' => '查看询价',
        'due_this_week_desc' => '未来7天内到期的计划项目',
        'view_schedule' => '查看计划',
    ],

    // Supplier Audit Stats
    'audit_stats' => [
        'scheduled_audits' => '计划审核',
        'in_progress' => '进行中',
        'pending_audits' => '待审核',
        'completed_this_month' => '本月完成',
        'average_score' => '平均得分',
        'overdue' => '逾期',
        'rejected_ytd' => '年初至今拒绝',
    ],

    // Order Pipeline Kanban
    'kanban' => [
        'value' => '价值',
        'paid' => '已付款',
        'overdue_payment' => '逾期付款',
        'no_update_for' => '无更新',
        'no_items' => '无项目',
    ],
];