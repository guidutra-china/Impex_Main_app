<?php

namespace App\Domain\Infrastructure\Pdf;

class DocumentLabels
{
    protected static array $labels = [
        'en' => [
            // Document titles
            'quotation' => 'Quotation',
            'proforma_invoice' => 'Proforma Invoice',
            'purchase_order' => 'Purchase Order',
            'commercial_invoice' => 'Commercial Invoice',
            'packing_list' => 'Packing List',

            // Header
            'date' => 'Date',
            'reference' => 'Reference',
            'valid_until' => 'Valid Until',
            'currency' => 'Currency',
            'version' => 'Version',
            'page' => 'Page',
            'of' => 'of',

            // Parties
            'from' => 'From',
            'to' => 'To',
            'client' => 'Client',
            'supplier' => 'Supplier',
            'attention' => 'Attn',
            'phone' => 'Phone',
            'email' => 'Email',
            'tax_id' => 'Tax ID',

            // Items table
            'item' => '#',
            'description' => 'Description',
            'product_code' => 'Product Code',
            'quantity' => 'Qty',
            'unit' => 'Unit',
            'unit_price' => 'Unit Price',
            'line_total' => 'Total',
            'incoterm' => 'Incoterm',

            // Totals
            'subtotal' => 'Subtotal',
            'commission' => 'Service Fee',
            'grand_total' => 'Grand Total',

            // Terms
            'payment_terms' => 'Payment Terms',
            'notes' => 'Notes',
            'terms_and_conditions' => 'Terms & Conditions',
            'bank_details' => 'Bank Details',

            // Payment term stages
            'stage' => 'Stage',
            'percentage' => 'Percentage',
            'days' => 'Days',
            'calculation_base' => 'Based On',
        ],
        'pt' => [
            'quotation' => 'Cotação',
            'proforma_invoice' => 'Fatura Proforma',
            'purchase_order' => 'Ordem de Compra',
            'commercial_invoice' => 'Fatura Comercial',
            'packing_list' => 'Romaneio',

            'date' => 'Data',
            'reference' => 'Referência',
            'valid_until' => 'Válido Até',
            'currency' => 'Moeda',
            'version' => 'Versão',
            'page' => 'Página',
            'of' => 'de',

            'from' => 'De',
            'to' => 'Para',
            'client' => 'Cliente',
            'supplier' => 'Fornecedor',
            'attention' => 'A/C',
            'phone' => 'Telefone',
            'email' => 'E-mail',
            'tax_id' => 'CNPJ/CPF',

            'item' => '#',
            'description' => 'Descrição',
            'product_code' => 'Código',
            'quantity' => 'Qtd',
            'unit' => 'Unidade',
            'unit_price' => 'Preço Unit.',
            'line_total' => 'Total',
            'incoterm' => 'Incoterm',

            'subtotal' => 'Subtotal',
            'commission' => 'Taxa de Serviço',
            'grand_total' => 'Total Geral',

            'payment_terms' => 'Condições de Pagamento',
            'notes' => 'Observações',
            'terms_and_conditions' => 'Termos e Condições',
            'bank_details' => 'Dados Bancários',

            'stage' => 'Etapa',
            'percentage' => 'Percentual',
            'days' => 'Dias',
            'calculation_base' => 'Base de Cálculo',
        ],
        'zh' => [
            'quotation' => '报价单',
            'proforma_invoice' => '形式发票',
            'purchase_order' => '采购订单',
            'commercial_invoice' => '商业发票',
            'packing_list' => '装箱单',

            'date' => '日期',
            'reference' => '编号',
            'valid_until' => '有效期至',
            'currency' => '货币',
            'version' => '版本',
            'page' => '第',
            'of' => '页/共',

            'from' => '发件方',
            'to' => '收件方',
            'client' => '客户',
            'supplier' => '供应商',
            'attention' => '联系人',
            'phone' => '电话',
            'email' => '邮箱',
            'tax_id' => '税号',

            'item' => '#',
            'description' => '描述',
            'product_code' => '产品编码',
            'quantity' => '数量',
            'unit' => '单位',
            'unit_price' => '单价',
            'line_total' => '合计',
            'incoterm' => '贸易术语',

            'subtotal' => '小计',
            'commission' => '服务费',
            'grand_total' => '总计',

            'payment_terms' => '付款条件',
            'notes' => '备注',
            'terms_and_conditions' => '条款与条件',
            'bank_details' => '银行信息',

            'stage' => '阶段',
            'percentage' => '百分比',
            'days' => '天数',
            'calculation_base' => '计算基准',
        ],
    ];

    public static function get(string $key, string $locale = 'en'): string
    {
        return static::$labels[$locale][$key]
            ?? static::$labels['en'][$key]
            ?? $key;
    }

    public static function all(string $locale = 'en'): array
    {
        return array_merge(
            static::$labels['en'] ?? [],
            static::$labels[$locale] ?? [],
        );
    }

    public static function supportedLocales(): array
    {
        return array_keys(static::$labels);
    }
}
