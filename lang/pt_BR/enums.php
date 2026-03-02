<?php

return array (
    'company_role' => 
    array (
    'client' => 'Cliente',
    'supplier' => 'Fornecedor',
    'forwarder' => 'Transportadora',
    'agent' => 'Agente',
    'manufacturer' => 'Fabricante',
    ),
    'company_status' => 
    array (
    'active' => 'Ativo',
    'inactive' => 'Inativo',
    'prospect' => 'Potencial',
    'blocked' => 'Bloqueado',
    ),
    'contact_function' => 
    array (
    'management' => 'Gestão',
    'sales' => 'Vendas',
    'purchasing' => 'Compras',
    'logistics' => 'Logística',
    'finance' => 'Financeiro',
    'quality' => 'Qualidade',
    'engineering' => 'Engenharia',
    'operations' => 'Operações',
    'other' => 'Outro',
    ),
    'document_category' => 
    array (
    'certificate' => 'Certificado',
    'photo' => 'Foto',
    'contract' => 'Contrato',
    'license' => 'Licença',
    'report' => 'Relatório',
    'price_list' => 'Lista de Preços',
    'catalog' => 'Catálogo',
    'other' => 'Outro',
    ),
    'attribute_type' => 
    array (
    'text' => 'Texto',
    'number' => 'Número',
    'select' => 'Selecionar (Opções)',
    'boolean' => 'Sim / Não',
    ),
    'product_status' => 
    array (
    'draft' => 'Rascunho',
    'active' => 'Ativo',
    'discontinued' => 'Descontinuado',
    'out_of_stock' => 'Fora de Estoque',
    ),
    'additional_cost_status' => 
    array (
    'pending' => 'Pendente',
    'invoiced' => 'Faturado',
    'paid' => 'Pago',
    'waived' => 'Isento',
    ),
    'additional_cost_type' => 
    array (
    'testing' => 'Teste Laboratorial',
    'inspection' => 'Inspeção',
    'samples' => 'Amostras',
    'sample_shipping' => 'Envio de Amostras',
    'freight' => 'Frete',
    'customs' => 'Alfândega / Impostos',
    'insurance' => 'Seguro',
    'packaging' => 'Embalagem',
    'certification' => 'Certificação',
    'travel' => 'Despesas de Viagem',
    'commission' => 'Comissão',
    'other' => 'Outro',
    ),
    'billable_to' => 
    array (
    'client' => 'Cliente (Repasse)',
    'supplier' => 'Fornecedor (Repasse)',
    'company' => 'Empresa (Interno)',
    ),
    'payment_direction' => 
    array (
    'inbound' => 'Entrada (do Cliente)',
    'outbound' => 'Saída (para Fornecedor)',
    ),
    'payment_schedule_status' => 
    array (
    'pending' => 'Pendente',
    'due' => 'Vencido',
    'paid' => 'Pago',
    'overdue' => 'Atrasado',
    'waived' => 'Isento',
    ),
    'payment_status' => 
    array (
    'pending_approval' => 'Aguardando Aprovação',
    'approved' => 'Aprovado',
    'rejected' => 'Rejeitado',
    'cancelled' => 'Cancelado',
    ),
    'document_source_type' => 
    array (
    'generated' => 'Gerado',
    'uploaded' => 'Carregado',
    ),
    'document_type' => 
    array (
    'INQ' => 'Consulta',
    'SQ' => 'Cotação do Fornecedor',
    'QT' => 'Cotação',
    'PI' => 'Fatura Proforma',
    'PO' => 'Ordem de Compra',
    'SH' => 'Remessa',
    'CI' => 'Fatura do Cliente',
    'PAY' => 'Pagamento',
    ),
    'inquiry_source' => 
    array (
    'email' => 'Email',
    'whatsapp' => 'WhatsApp',
    'phone' => 'Telefone',
    'rfq' => 'RFQ Formal',
    'website' => 'Website',
    'other' => 'Outro',
    ),
    'inquiry_status' => 
    array (
    'received' => 'Recebido',
    'quoting' => 'Cotando',
    'quoted' => 'Cotado',
    'won' => 'Ganho',
    'lost' => 'Perdido',
    'cancelled' => 'Cancelado',
    ),
    'container_type' => 
    array (
    '20ft' => '20\' FCL',
    '40ft' => '40\' FCL',
    '40hc' => '40\' HC',
    '45hc' => '45\' HC',
    'lcl' => 'LCL',
    ),
    'packaging_type' => 
    array (
    'carton' => 'Caixa de Papelão',
    'bag' => 'Saco',
    'drum' => 'Tambor',
    'wood_box' => 'Caixa de Madeira',
    'bulk' => 'Granel',
    ),
    'shipment_status' => 
    array (
    'draft' => 'Rascunho',
    'booked' => 'Reservado',
    'customs' => 'Alfândega',
    'in_transit' => 'Em Trânsito',
    'arrived' => 'Chegado',
    'cancelled' => 'Cancelado',
    ),
    'transport_mode' => 
    array (
    'sea' => 'Frete Marítimo',
    'air' => 'Frete Aéreo',
    'land' => 'Frete Terrestre',
    'rail' => 'Frete Ferroviário',
    'multimodal' => 'Multimodal',
    ),
    'confirmation_method' => 
    array (
    'email' => 'Email',
    'message' => 'Mensagem (WhatsApp, WeChat, etc.)',
    'phone' => 'Chamada Telefônica',
    'in_person' => 'Pessoalmente',
    'signed_document' => 'Documento Assinado',
    'other' => 'Outro',
    ),
    'proforma_invoice_status' => 
    array (
    'draft' => 'Rascunho',
    'sent' => 'Enviado',
    'confirmed' => 'Confirmado',
    'finalized' => 'Finalizado',
    'reopened' => 'Reaberto',
    'cancelled' => 'Cancelado',
    ),
    'purchase_order_status' => 
    array (
    'draft' => 'Rascunho',
    'sent' => 'Enviado',
    'confirmed' => 'Confirmado',
    'in_production' => 'Em Produção',
    'shipped' => 'Enviado',
    'completed' => 'Concluído',
    'cancelled' => 'Cancelado',
    ),
    'commission_type' => 
    array (
    'embedded' => 'Incorporada no Preço',
    'separate' => 'Linha Separada',
    ),
    'incoterm' => 
    array (
    'EXW' => 'EXW - Ex Works',
    'FCA' => 'FCA - Free Carrier',
    'FAS' => 'FAS - Free Alongside Ship',
    'FOB' => 'FOB - Free on Board',
    'CFR' => 'CFR - Cost and Freight',
    'CIF' => 'CIF - Cost, Insurance & Freight',
    'CPT' => 'CPT - Carriage Paid To',
    'CIP' => 'CIP - Carriage & Insurance Paid To',
    'DAP' => 'DAP - Delivered at Place',
    'DPU' => 'DPU - Delivered at Place Unloaded',
    'DDP' => 'DDP - Delivered Duty Paid',
    ),
    'quotation_status' => 
    array (
    'draft' => 'Rascunho',
    'sent' => 'Enviado',
    'negotiating' => 'Negociando',
    'approved' => 'Aprovado',
    'rejected' => 'Rejeitado',
    'expired' => 'Expirado',
    'cancelled' => 'Cancelado',
    ),
    'bank_account_status' => 
    array (
    'active' => 'Ativo',
    'inactive' => 'Inativo',
    'closed' => 'Fechado',
    ),
    'bank_account_type' => 
    array (
    'checking' => 'Conta Corrente',
    'savings' => 'Poupança',
    'business' => 'Conta Empresarial',
    'escrow' => 'Escrow',
    'foreign_currency' => 'Moeda Estrangeira',
    ),
    'calculation_base' => 
    array (
    'order_date' => 'Data do Pedido',
    'invoice_date' => 'Data da Fatura',
    'shipment_date' => 'Data da Remessa',
    'delivery_date' => 'Data de Entrega',
    'bl_date' => 'Data do Conhecimento de Embarque',
    'po_date' => 'Data da Ordem de Compra',
    'before_shipment' => 'Antes da Remessa',
    'before_production' => 'Antes da Produção',
    'after_production' => 'Após a Produção',
    ),
    'exchange_rate_source' => 
    array (
    'manual' => 'Manual',
    'api' => 'API',
    'bank' => 'Banco',
    ),
    'exchange_rate_status' => 
    array (
    'pending' => 'Pendente',
    'approved' => 'Aprovado',
    'rejected' => 'Rejeitado',
    ),
    'fee_type' => 
    array (
    'none' => 'Sem Taxa',
    'fixed' => 'Taxa Fixa',
    'percentage' => 'Taxa Percentual',
    'fixed_plus_percentage' => 'Fixa + Percentual',
    ),
    'payment_method_type' => 
    array (
    'bank_transfer' => 'Transferência Bancária',
    'wire_transfer' => 'Transferência Eletrônica',
    'paypal' => 'PayPal',
    'credit_card' => 'Cartão de Crédito',
    'debit_card' => 'Cartão de Débito',
    'check' => 'Cheque',
    'cash' => 'Dinheiro',
    'wise' => 'Wise (TransferWise)',
    'cryptocurrency' => 'Criptomoeda',
    'other' => 'Outro',
    ),
    'processing_time' => 
    array (
    'immediate' => 'Imediato',
    'same_day' => 'Mesmo Dia',
    '1_3_days' => '1-3 Dias',
    '3_5_days' => '3-5 Dias',
    '5_7_days' => '5-7 Dias',
    ),
    'audit_document_type' => 
    array (
    'photo' => 'Foto',
    'certificate' => 'Certificado',
    'report' => 'Relatório',
    'contract' => 'Contrato',
    'other' => 'Outro',
    ),
    'audit_result' => 
    array (
    'approved' => 'Aprovado',
    'conditional' => 'Condicional',
    'rejected' => 'Rejeitado',
    ),
    'audit_status' => 
    array (
    'scheduled' => 'Agendado',
    'in_progress' => 'Em Progresso',
    'completed' => 'Concluído',
    'reviewed' => 'Revisado',
    ),
    'audit_type' => 
    array (
    'initial' => 'Qualificação Inicial',
    'periodic' => 'Revisão Periódica',
    'requalification' => 'Requalificação',
    'for_cause' => 'Por Justa Causa',
    ),
    'criterion_type' => 
    array (
    'scored' => 'Pontuado (1-5)',
    'pass_fail' => 'Aprovado / Reprovado',
    ),
    'supplier_quotation_status' => 
    array (
    'requested' => 'Solicitado',
    'received' => 'Recebido',
    'under_analysis' => 'Em Análise',
    'selected' => 'Selecionado',
    'rejected' => 'Rejeitado',
    'expired' => 'Expirado',
    ),
    'user_type' => 
    array (
    'internal' => 'Interno',
    'client' => 'Cliente',
    'supplier' => 'Fornecedor',
    ),
    'expense_category' => 
    array (
    'rent' => 'Aluguel',
    'salary' => 'Salários e Encargos',
    'software' => 'Software e Assinaturas',
    'utilities' => 'Utilidades (Água, Luz, Gás)',
    'office_supplies' => 'Material de Escritório',
    'marketing' => 'Marketing e Publicidade',
    'legal' => 'Serviços Jurídicos',
    'accounting' => 'Serviços Contábeis',
    'telecom' => 'Telecom e Internet',
    'travel' => 'Viagens',
    'meals' => 'Refeições e Entretenimento',
    'insurance' => 'Seguros',
    'taxes_fees' => 'Impostos e Taxas',
    'maintenance' => 'Manutenção e Reparos',
    'bank_fees' => 'Tarifas Bancárias',
    'other' => 'Outros',
    ),
    'import_modality' => 
    array (
    'direct' => 'Importação Direta',
    'conta_e_ordem' => 'Importação por Conta e Ordem',
    ),
    'project_team_role' =>
    array (
    'project_lead' => 'Líder do Projeto',
    'sales' => 'Vendas',
    'sourcing' => 'Compras',
    'logistics' => 'Logística',
    'financial' => 'Financeiro',
    'quality' => 'Qualidade',
    ),
);
