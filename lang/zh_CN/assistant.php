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
    'notes' => '备注',

    // 导入 — 拖放区
    'dropzone_drag' => '拖放',
    'dropzone_rest' => '将文档拖到此处（供应商报价、客户询价…），或点击选择',
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
        'part_no' => '型号 / 货号',
        'description' => '描述（产品名称）',
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

    // 导入目标
    'target_supplier_quotation' => '供应商报价',
    'target_inquiry' => '询价单（客户需求）',

    // 权限
    'perm_supplier_quotations' => '您没有创建供应商报价的权限。',
    'perm_companies' => '您没有创建公司（新供应商）的权限。',
    'perm_products' => '您没有创建产品的权限。',

    // 通用导入 — 询价单目标
    'perm_inquiries_create' => '您没有创建询价单的权限。',
    'perm_inquiries_edit' => '您没有编辑询价单的权限。',
    'inquiry_not_open' => '该询价单未处于打开状态（已接收/报价中），无法添加项目。',
    'client_required' => '请填写客户以创建新询价单。',

    // 智能体循环
    'too_many_steps' => '无法完成请求（步骤过多）。请尝试更简单地重新表述。',
    'no_answer' => '（无回复）',
    'tool_unknown' => '未知工具。',
    'tool_denied' => '权限被拒绝：您无权访问这些数据。',
    'tool_error' => '执行工具时出错：:error',

    // 通用导入 — 目标选择
    'suggest_target' => '该文档看起来是：:label — :reason',
    'suggest_target_short' => '该文档看起来是：:label',
    'suggest_unknown' => '无法自动识别文档类型。',
    'choose_target' => '请选择导入目标：',
    'import_as' => '导入为 :label',
    'extracted_generic' => '数据已提取。请在下方检查并确认导入。',
    'imported_generic' => ':reference 已导入 :count 个项目。',
    'switch_target' => '以其他类型导入…',
    'search_inquiry' => '按编号或客户搜索…',
    'record_not_found' => '所引用的记录已不存在。请检查选择后重试。',

    // 通用导入 — 询价单审核
    'mode_new_inquiry' => '创建新询价单',
    'mode_existing_inquiry' => '添加到现有询价单',
    'client' => '客户',
    'deadline' => '截止日期',
    'inquiry_label' => '询价单',
    'select_inquiry' => '选择询价单…',
    'import_locked_inquiry' => '上传文件，导入将关联到询价单 :reference —— 分析后您可选择目标（询价单项目或供应商报价）。',
    'sq_will_link_inquiry' => '此供应商报价将关联到询价单 :inquiry。',
    'summary_inquiry_counts' => '共 :total 项 — :matched 项已匹配产品，:unmatched 项未匹配（仅按描述导入）。',
    'col_target_price' => '目标价',
    'import_with_ai' => 'AI 导入',

    // 询价单 + 供应商报价组合流程
    'create_linked_inquiry' => '为客户创建关联询价单',
    'create_linked_inquiry_hint' => '除报价单外，还会为所选客户创建包含相同产品明细的询价单——可直接生成报价/形式发票。',
    'search_client' => '搜索客户…',
    'select_client' => '选择客户…',
    'inquiry_client_required' => '请选择关联询价单的客户。',

    // 审核 — 明确的行 → 产品映射
    'desc_inferred_badge' => '由照片推断 — 请核对',
    'new_product_hint' => '将创建草稿产品：描述成为产品名称，型号/货号成为 Model Number。导入前可编辑修正。',
    'existing_product_hint' => '此行已关联现有产品——编辑描述只影响本行，不会修改产品。',
    'unlink_product' => '取消关联',
    'link_product' => '关联…',
    'link_product_title' => '关联到目录中的现有产品',
    'search_product' => '按名称、SKU 或型号搜索产品…',
    'target_unavailable_permission' => '以 :label 导入需要您没有的权限，请联系管理员。',
    'photo_upload' => '上传照片',
    'photo_drop_hint' => '拖拽图片到此处，点击从文档中选择，或打开图库后 Ctrl+V 粘贴。',
    'photo_flip' => '翻转照片（上下颠倒）',
    'import_as_sq_with_inquiry' => '导入为供应商报价 + 关联询价单',
    'api_billing' => 'Anthropic API 账户余额不足。请前往 console.anthropic.com（Plans & Billing）充值后重试。',
];
