<?php

return [
    // 聊天界面
    'empty_hint' => '提个问题 — 例如"查找公司 DeepFitness"或"客户 Gencrea 的状态如何？"',
    'thinking' => '思考中…',
    'composer_placeholder' => '向助手提问…',
    'send' => '发送',
    'clear_conversation' => '清空对话',
    'chat_error' => '与助手通信时出错，请稍后重试。',
    'edit_done' => '完成。',
    'edit_hint' => '有问题？在下方聊天中让我修改（例如"把所有单位设为 pcs"）。',
    'review_hint' => '请逐项核对并调整字段与照片，然后确认。',
    'photo_none' => '无照片',
    'currency' => '货币',

    // 导入 — 拖放区
    'dropzone_drag' => '拖放',
    'dropzone_rest' => '供应商报价文件到此处，或点击选择',
    'dropzone_formats' => 'xlsx、xls 或 PDF',
    'processing' => '处理中…',
    'processing_file' => '正在处理 :file…',
    'import_button' => '导入',

    // 导入 — 预览
    'preview_title' => '导入预览',
    'supplier' => '供应商',
    'status_new' => '新建',
    'status_existing' => '已有',
    'summary_counts' => '共 :total 项 — :existing 个已有产品，:new 个新产品。',
    'items_total' => '明细合计',
    'document_total' => '单据总额',
    'extras_note' => '费用/折扣（计入备注，不作为产品明细导入）：',
    'divergence_warning' => '⚠️ 明细 + 费用/折扣之和与单据总额不一致。请在确认前核对金额。',
    'uncategorized_warning' => '⚠️ 有 :count 个项目没有匹配的分类（已在下方标注）。将不带分类创建 — 如有需要请稍后归类。',
    'no_category' => '无分类',
    'confirm_import' => '确认导入',
    'cancel' => '取消',
    'col' => [
        'part_no' => '货号',
        'description' => '描述',
        'qty' => '数量',
        'unit' => '单位',
        'unit_price' => '单价',
        'total' => '金额',
        'category' => '分类',
        'status' => '状态',
        'photo' => '照片',
        'product' => '产品',
    ],

    // 聊天 / 流程消息
    'extracted' => '我已从文件中提取了报价。请核对下方摘要并确认导入。',
    'process_failed' => '无法处理该文件：:error',
    'connection_failed' => '无法连接到 AI 服务。请检查网络/VPN 后重试。',
    'process_error' => '处理文件时出错，请重试。',
    'imported_title' => '报价已导入：:reference',
    'imported_message' => '报价 :reference 已创建，共 :count 项。',
    'invalid_file' => '无效的导入文件。',
    'import_failed' => '导入报价失败。',

    // 权限
    'perm_supplier_quotations' => '您没有创建供应商报价的权限。',
    'perm_companies' => '您没有创建公司（新供应商）的权限。',
    'perm_products' => '您没有创建产品的权限。',

    // 智能体循环
    'too_many_steps' => '无法完成请求（步骤过多）。请尝试更简单地重新表述。',
    'no_answer' => '（无回复）',
    'tool_unknown' => '未知工具。',
    'tool_denied' => '权限被拒绝：您无权访问这些数据。',
    'tool_error' => '执行工具时出错：:error',
];
