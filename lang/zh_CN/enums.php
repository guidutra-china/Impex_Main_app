<?php

return array (
    'company_role' => 
    array (
    'client' => '客户',
    'supplier' => '供应商',
    'forwarder' => '货运代理',
    'agent' => '代理',
    'manufacturer' => '制造商',
    ),
    'company_status' => 
    array (
    'active' => '活跃',
    'inactive' => '非活跃',
    'prospect' => '潜在客户',
    'blocked' => '已屏蔽',
    ),
    'contact_function' => 
    array (
    'management' => '管理层',
    'sales' => '销售',
    'purchasing' => '采购',
    'logistics' => '物流',
    'finance' => '财务',
    'quality' => '质量',
    'engineering' => '工程',
    'operations' => '运营',
    'other' => '其他',
    ),
    'document_category' => 
    array (
    'certificate' => '证书',
    'photo' => '照片',
    'contract' => '合同',
    'license' => '许可证',
    'report' => '报告',
    'price_list' => '价目表',
    'catalog' => '目录',
    'other' => '其他',
    ),
    'attribute_type' => 
    array (
    'text' => '文本',
    'number' => '数字',
    'select' => '选择（选项）',
    'boolean' => '是 / 否',
    ),
    'product_status' => 
    array (
    'draft' => '草稿',
    'active' => '活跃',
    'discontinued' => '停产',
    'out_of_stock' => '缺货',
    ),
    'additional_cost_status' => 
    array (
    'pending' => '待处理',
    'invoiced' => '已开票',
    'paid' => '已支付',
    'waived' => '已免除',
    ),
    'additional_cost_type' => 
    array (
    'testing' => '实验室测试',
    'inspection' => '检验',
    'samples' => '样品',
    'sample_shipping' => '样品运输',
    'freight' => '运费',
    'customs' => '海关 / 关税',
    'insurance' => '保险',
    'packaging' => '包装',
    'certification' => '认证',
    'travel' => '差旅费',
    'commission' => '佣金',
    'other' => '其他',
    ),
    'billable_to' => 
    array (
    'client' => '客户（转账）',
    'supplier' => '供应商（转账）',
    'company' => '公司（内部）',
    ),
    'payment_direction' => 
    array (
    'inbound' => '入账（来自客户）',
    'outbound' => '出账（支付供应商）',
    ),
    'payment_schedule_status' => 
    array (
    'pending' => '待处理',
    'due' => '到期',
    'paid' => '已支付',
    'overdue' => '逾期',
    'waived' => '已免除',
    ),
    'payment_status' => 
    array (
    'pending_approval' => '待审批',
    'approved' => '已批准',
    'rejected' => '已拒绝',
    'cancelled' => '已取消',
    ),
    'document_source_type' => 
    array (
    'generated' => '生成',
    'uploaded' => '上传',
    ),
    'document_type' => 
    array (
    'INQ' => '询价',
    'SQ' => '供应商报价',
    'QT' => '报价单',
    'PI' => '形式发票',
    'PO' => '采购订单',
    'SH' => '装运',
    'CI' => '客户发票',
    'PAY' => '付款',
    ),
    'inquiry_source' => 
    array (
    'email' => '电子邮件',
    'whatsapp' => 'WhatsApp',
    'phone' => '电话',
    'rfq' => '正式RFQ',
    'website' => '网站',
    'other' => '其他',
    ),
    'inquiry_status' => 
    array (
    'received' => '已接收',
    'quoting' => '报价中',
    'quoted' => '已报价',
    'won' => '已中标',
    'lost' => '未中标',
    'cancelled' => '已取消',
    ),
    'container_type' => 
    array (
    '20ft' => '20\' 整箱',
    '40ft' => '40\' 整箱',
    '40hc' => '40\' 高箱',
    '45hc' => '45\' 高箱',
    'lcl' => '拼箱',
    ),
    'packaging_type' => 
    array (
    'carton' => '纸箱',
    'bag' => '袋装',
    'drum' => '桶装',
    'wood_box' => '木箱',
    'bulk' => '散装',
    ),
    'shipment_status' => 
    array (
    'draft' => '草稿',
    'booked' => '已预订',
    'customs' => '报关中',
    'in_transit' => '运输中',
    'arrived' => '已到达',
    'cancelled' => '已取消',
    ),
    'transport_mode' => 
    array (
    'sea' => '海运',
    'air' => '空运',
    'land' => '陆运',
    'rail' => '铁路运输',
    'multimodal' => '多式联运',
    ),
    'confirmation_method' => 
    array (
    'email' => '电子邮件',
    'message' => '消息（WhatsApp，微信等）',
    'phone' => '电话',
    'in_person' => '当面',
    'signed_document' => '签署文件',
    'other' => '其他',
    ),
    'proforma_invoice_status' => 
    array (
    'draft' => '草稿',
    'sent' => '已发送',
    'confirmed' => '已确认',
    'finalized' => '已定稿',
    'reopened' => '重新开启',
    'cancelled' => '已取消',
    ),
    'purchase_order_status' => 
    array (
    'draft' => '草稿',
    'sent' => '已发送',
    'confirmed' => '已确认',
    'in_production' => '生产中',
    'shipped' => '已发货',
    'completed' => '已完成',
    'cancelled' => '已取消',
    ),
    'commission_type' => 
    array (
    'embedded' => '包含在价格中',
    'separate' => '单独列出',
    ),
    'incoterm' => 
    array (
    'EXW' => 'EXW - 工厂交货',
    'FCA' => 'FCA - 货交承运人',
    'FAS' => 'FAS - 船边交货',
    'FOB' => 'FOB - 装运港船上交货',
    'CFR' => 'CFR - 成本加运费',
    'CIF' => 'CIF - 成本、保险费加运费',
    'CPT' => 'CPT - 运费付至',
    'CIP' => 'CIP - 运费和保险费付至',
    'DAP' => 'DAP - 交货至指定地点',
    'DPU' => 'DPU - 交货至指定地点并卸货',
    'DDP' => 'DDP - 完税后交货',
    ),
    'quotation_status' => 
    array (
    'draft' => '草稿',
    'sent' => '已发送',
    'negotiating' => '谈判中',
    'approved' => '已批准',
    'rejected' => '已拒绝',
    'expired' => '已过期',
    'cancelled' => '已取消',
    ),
    'bank_account_status' => 
    array (
    'active' => '活跃',
    'inactive' => '非活跃',
    'closed' => '已关闭',
    ),
    'bank_account_type' => 
    array (
    'checking' => '支票账户',
    'savings' => '储蓄账户',
    'business' => '企业账户',
    'escrow' => '托管账户',
    'foreign_currency' => '外币账户',
    ),
    'calculation_base' => 
    array (
    'order_date' => '订单日期',
    'invoice_date' => '发票日期',
    'shipment_date' => '装运日期',
    'delivery_date' => '交货日期',
    'bl_date' => '提单日期',
    'po_date' => '采购订单日期',
    'before_shipment' => '装运前',
    'before_production' => '生产前',
    'after_production' => '生产后',
    ),
    'exchange_rate_source' => 
    array (
    'manual' => '手动',
    'api' => 'API',
    'bank' => '银行',
    ),
    'exchange_rate_status' => 
    array (
    'pending' => '待处理',
    'approved' => '已批准',
    'rejected' => '已拒绝',
    ),
    'fee_type' => 
    array (
    'none' => '无费用',
    'fixed' => '固定费用',
    'percentage' => '百分比费用',
    'fixed_plus_percentage' => '固定 + 百分比',
    ),
    'payment_method_type' => 
    array (
    'bank_transfer' => '银行转账',
    'wire_transfer' => '电汇',
    'paypal' => 'PayPal',
    'credit_card' => '信用卡',
    'debit_card' => '借记卡',
    'check' => '支票',
    'cash' => '现金',
    'wise' => 'Wise (TransferWise)',
    'cryptocurrency' => '加密货币',
    'other' => '其他',
    ),
    'processing_time' => 
    array (
    'immediate' => '即时',
    'same_day' => '当天',
    '1_3_days' => '1-3天',
    '3_5_days' => '3-5天',
    '5_7_days' => '5-7天',
    ),
    'audit_document_type' => 
    array (
    'photo' => '照片',
    'certificate' => '证书',
    'report' => '报告',
    'contract' => '合同',
    'other' => '其他',
    ),
    'audit_result' => 
    array (
    'approved' => '通过',
    'conditional' => '有条件通过',
    'rejected' => '未通过',
    ),
    'audit_status' => 
    array (
    'scheduled' => '已安排',
    'in_progress' => '进行中',
    'completed' => '已完成',
    'reviewed' => '已审核',
    ),
    'audit_type' => 
    array (
    'initial' => '初次资格审核',
    'periodic' => '定期审核',
    'requalification' => '重新资格审核',
    'for_cause' => '因故审核',
    ),
    'criterion_type' => 
    array (
    'scored' => '评分（1-5）',
    'pass_fail' => '通过 / 不通过',
    ),
    'supplier_quotation_status' => 
    array (
    'requested' => '已请求',
    'received' => '已接收',
    'under_analysis' => '分析中',
    'selected' => '已选定',
    'rejected' => '已拒绝',
    'expired' => '已过期',
    ),
    'user_type' => 
    array (
    'internal' => '内部',
    'client' => '客户',
    'supplier' => '供应商',
    ),
    'expense_category' => 
    array (
    'rent' => '房租',
    'salary' => '工资与薪酬',
    'software' => '软件与订阅',
    'utilities' => '水电费',
    'office_supplies' => '办公用品',
    'marketing' => '市场营销',
    'legal' => '法律服务',
    'accounting' => '会计服务',
    'telecom' => '通讯与网络',
    'travel' => '差旅',
    'meals' => '餐饮与招待',
    'insurance' => '保险',
    'taxes_fees' => '税费',
    'maintenance' => '维修与保养',
    'bank_fees' => '银行手续费',
    'other' => '其他',
    ),
    'import_modality' => 
    array (
    'direct' => '直接进口',
    'conta_e_ordem' => '代理进口（Conta e Ordem）',
    ),
);
