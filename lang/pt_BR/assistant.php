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

    // Importação — zona de arrastar e soltar
    'dropzone_drag' => 'Arraste e solte',
    'dropzone_rest' => 'uma cotação de fornecedor aqui, ou clique para selecionar',
    'dropzone_formats' => 'xlsx, xls ou PDF',
    'processing' => 'Processando…',
    'processing_file' => 'Processando :file…',
    'import_button' => 'Importar',

    // Importação — pré-visualização
    'preview_title' => 'Pré-visualização da importação',
    'supplier' => 'Fornecedor',
    'status_new' => 'novo',
    'status_existing' => 'existente',
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
        'part_no' => 'Part No.',
        'description' => 'Descrição',
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
    'connection_failed' => 'Não foi possível conectar ao serviço de IA. Verifique a conexão/VPN e tente novamente.',
    'process_error' => 'Erro ao processar o arquivo. Tente novamente.',
    'imported_title' => 'Cotação importada: :reference',
    'imported_message' => 'Cotação :reference criada com :count itens.',
    'invalid_file' => 'Arquivo de importação inválido.',
    'import_failed' => 'Falha ao importar a cotação.',

    // Permissões
    'perm_supplier_quotations' => 'Sem permissão para criar cotações de fornecedor.',
    'perm_companies' => 'Sem permissão para criar empresas (fornecedor novo).',
    'perm_products' => 'Sem permissão para criar produtos.',

    // Loop agêntico
    'too_many_steps' => 'Não consegui concluir a solicitação (excesso de etapas). Tente reformular de forma mais simples.',
    'no_answer' => '(sem resposta)',
    'tool_unknown' => 'Ferramenta desconhecida.',
    'tool_denied' => 'Permissão negada: você não tem acesso a esses dados.',
    'tool_error' => 'Erro ao executar a ferramenta: :error',
];
