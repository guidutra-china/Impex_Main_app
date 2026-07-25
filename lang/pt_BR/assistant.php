<?php

return [
    // Chat UI
    'empty_hint' => 'Faça uma pergunta — ex: "Buscar a empresa DeepFitness" ou "Qual o status do cliente Gencrea?"',
    'thinking' => 'Pensando…',
    'composer_placeholder' => 'Pergunte algo ao assistente…',
    'send' => 'Enviar',
    'clear_conversation' => 'Limpar conversa',
    'chat_error' => 'Ocorreu um erro ao falar com o assistente. Tente novamente em instantes.',
    'edit_done' => 'Pronto.',
    'edit_hint' => 'Algo errado? Peça aqui no chat para a IA corrigir (ex.: "coloque pcs em todos").',
    'review_hint' => 'Revise e ajuste cada item e a foto, depois confirme.',
    'photo_none' => 'Sem foto',
    'currency' => 'Moeda',
    'notes' => 'Observações',

    // Importação — zona de arrastar e soltar
    'dropzone_drag' => 'Arraste e solte',
    'dropzone_rest' => 'um documento aqui (cotação de fornecedor, pedido de cliente…), ou clique para selecionar',
    'dropzone_formats' => 'xlsx, xls ou PDF',
    'processing' => 'Processando…',
    'processing_file' => 'Processando :file…',
    'import_button' => 'Importar',

    // Importação — pré-visualização
    'preview_title' => 'Pré-visualização da importação',
    'supplier' => 'Fornecedor',
    'status_new' => 'novo',
    'status_existing' => 'existente',
    'status_ai_suggested' => 'sugerido por IA',
    'status_ai_suggested_hint' => 'Correspondência sugerida pela IA — confira o produto antes de importar',
    'summary_counts' => ':total itens — :existing produtos existentes, :new novos.',
    'items_total' => 'Total dos itens',
    'document_total' => 'Total do documento',
    'extras_note' => 'Taxas/descontos (vão para as notas, não viram itens):',
    'divergence_warning' => '⚠️ A soma dos itens + taxas/descontos não bate com o total do documento. Confira os valores antes de confirmar.',
    'uncategorized_warning' => '⚠️ :count item(ns) sem categoria correspondente (marcados abaixo). Serão criados sem categoria — classifique depois, se necessário.',
    'no_category' => 'sem categoria',
    'confirm_import' => 'Confirmar importação',
    'cancel' => 'Cancelar',
    'col' => [
        'part_no' => 'Modelo / Part Nº',
        'description' => 'Descrição (nome do produto)',
        'qty' => 'Qtd',
        'unit' => 'Unid.',
        'unit_price' => 'Preço unit.',
        'total' => 'Total',
        'category' => 'Categoria',
        'status' => 'Situação',
        'photo' => 'Foto',
        'product' => 'Produto',
    ],

    // Mensagens de chat / fluxo
    'extracted' => 'Extraí a cotação do arquivo. Revise o resumo abaixo e confirme para importar.',
    'process_failed' => 'Não consegui processar o arquivo: :error',
    'duplicate_file_warning' => '⚠️ Este arquivo já foi importado antes (:references). Confirme apenas se quiser importar de novo.',
    'duplicate_file_banner' => 'Este arquivo já foi importado antes: :references. Confirmar criará um registro duplicado.',
    'connection_failed' => 'Não foi possível conectar ao serviço de IA. Verifique a conexão/VPN e tente novamente.',
    'process_error' => 'Erro ao processar o arquivo. Tente novamente.',
    'imported_title' => 'Cotação importada: :reference',
    'imported_message' => 'Cotação :reference criada com :count itens.',
    'invalid_file' => 'Arquivo de importação inválido.',
    'import_failed' => 'Falha ao importar a cotação.',

    // Destinos de importação
    'target_supplier_quotation' => 'Cotação de fornecedor',
    'target_inquiry' => 'Inquiry (pedido de cliente)',

    // Permissões
    'perm_supplier_quotations' => 'Sem permissão para criar cotações de fornecedor.',
    'perm_companies' => 'Sem permissão para criar empresas (fornecedor novo).',
    'perm_products' => 'Sem permissão para criar produtos.',

    // Import universal — destino inquiry
    'perm_inquiries_create' => 'Você não tem permissão para criar inquiries.',
    'perm_inquiries_edit' => 'Você não tem permissão para editar inquiries.',
    'inquiry_not_open' => 'Esta inquiry não está aberta (recebida/cotando) — não é possível adicionar itens.',
    'client_required' => 'Informe o cliente para criar uma inquiry nova.',

    // Loop agêntico
    'too_many_steps' => 'Não consegui concluir a solicitação (excesso de etapas). Tente reformular de forma mais simples.',
    'no_answer' => '(sem resposta)',
    'tool_unknown' => 'Ferramenta desconhecida.',
    'tool_denied' => 'Permissão negada: você não tem acesso a esses dados.',
    'tool_error' => 'Erro ao executar a ferramenta: :error',

    // Import universal — escolha do destino
    'suggest_target' => 'Este documento parece ser: :label — :reason',
    'suggest_target_short' => 'Este documento parece ser: :label',
    'suggest_unknown' => 'Não consegui identificar o tipo do documento automaticamente.',
    'choose_target' => 'Escolha o destino da importação:',
    'import_as' => 'Importar como :label',
    'extracted_generic' => 'Dados extraídos. Revise abaixo e confirme a importação.',
    'imported_generic' => ':reference importada com :count item(ns).',
    'switch_target' => 'Importar como outro tipo…',
    'search_inquiry' => 'Buscar por referência ou cliente…',
    'record_not_found' => 'O registro referenciado não existe mais. Revise a seleção e tente de novo.',

    // Import universal — revisão de inquiry
    'mode_new_inquiry' => 'Criar inquiry nova',
    'mode_existing_inquiry' => 'Adicionar a inquiry existente',
    'client' => 'Cliente',
    'deadline' => 'Prazo',
    'inquiry_label' => 'Inquiry',
    'select_inquiry' => 'Selecione a inquiry…',
    'import_locked_inquiry' => 'Envie um arquivo para importar vinculado à inquiry :reference — após a análise você escolhe o destino (itens da inquiry ou cotação de fornecedor).',
    'sq_will_link_inquiry' => 'Esta cotação de fornecedor será vinculada à inquiry :inquiry.',
    'summary_inquiry_counts' => ':total itens — :matched casados com produtos, :unmatched sem match (importados só com a descrição).',
    'col_target_price' => 'Preço-alvo',
    'import_with_ai' => 'Importar com IA',

    // Fluxo combinado Inquiry + SQ
    'create_linked_inquiry' => 'Criar Inquiry vinculada para um cliente',
    'create_linked_inquiry_hint' => 'Além da cotação, cria uma Inquiry para o cliente escolhido com os mesmos itens e produtos — pronta para gerar Quotation/PI.',
    'search_client' => 'Buscar cliente…',
    'select_client' => 'Selecione o cliente…',
    'inquiry_client_required' => 'Selecione o cliente da Inquiry vinculada.',

    // Revisão — mapeamento explícito item → produto
    'desc_inferred_badge' => 'inferida da foto — revisar',
    'new_product_hint' => 'Será criado um produto draft: a descrição vira o Nome e o Modelo/Part Nº vira o Model Number. Edite os campos para corrigir antes de importar.',
    'existing_product_hint' => 'Item vinculado a um produto existente — editar a descrição altera só este item, não o produto.',
    'unlink_product' => 'Desvincular',
    'link_product' => 'Vincular…',
    'link_product_title' => 'Vincular a um produto existente do catálogo',
    'search_product' => 'Buscar produto por nome, SKU ou modelo…',
    'search_all_catalog' => 'Buscar em todo o catálogo',
    'scope_supplier_hint' => 'Mostrando apenas produtos do fornecedor :supplier.',
    'scope_all_hint' => 'Mostrando produtos de todo o catálogo — confira o fabricante antes de vincular.',
    'target_unavailable_permission' => 'Importar como :label requer permissão que você não possui. Peça ao administrador.',
    'photo_upload' => 'Enviar foto',
    'photo_drop_hint' => 'Arraste uma imagem aqui, clique para escolher do documento, ou Ctrl+V com a galeria aberta.',
    'photo_flip' => 'Inverter foto (cabeça para baixo)',
    'import_as_sq_with_inquiry' => 'Importar como Cotação + Inquiry vinculada',
    'api_billing' => 'A conta da API Anthropic está sem créditos. Recarregue em console.anthropic.com (Plans & Billing) e tente novamente.',
];
