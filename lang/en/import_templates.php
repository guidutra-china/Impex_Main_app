<?php

return [
    'navigation' => 'Import Templates',
    'title' => 'Import Templates',
    'intro' => 'Everything that can be loaded into the system from a spreadsheet, with a sample Excel file for each import. Samples use fictitious products and companies (codes EX-, CLI-, SUP-): use them for the layout, do not import them as they are.',
    'rules' => [
        'Prices as plain decimals (12.50), never in cents. Weights in kg, dimensions in cm.',
        'Only the first sheet of the workbook is read.',
        'In product imports, only the first 15 columns (A–O) can be mapped.',
        'Pictures pasted on a product row are imported with it.',
        'Maximum file size: 50 MB.',
    ],
    'where' => 'Where to import',
    'download_example' => 'Download sample',
    'download_blank' => 'Blank template',

    'items' => [
        'catalog' => [
            'title' => '1. Catalog products',
            'why' => 'Create dozens of products at once, with HS code, weight, carton and CBM. These feed the packing list and the shipment volume.',
            'where' => 'Catalog → Products → Import from Spreadsheet',
            'how' => [
                'Upload the file, pick the currency and optionally a client and/or supplier to link.',
                'Set the header row and map each column to a field (manual mapping).',
                'Create blocks: each block is a category with a start and end row. In the sample, rows 2–4 are fitness and 5–7 lighting.',
                'Review the preview and confirm.',
            ],
        ],
        'supplier_products' => [
            'title' => '2. Supplier products',
            'why' => 'Link products to a supplier with its code and purchase price. The PO and supplier documents use this data.',
            'where' => 'Companies → open the supplier → products tab → Quick Import',
            'how' => [
                'Keep the template header names: columns are recognised by name.',
                'Reference Code is our code; Supplier Code is the supplier\'s code; Purchase Price is the purchase price.',
                'The currency is picked on screen. Define the category blocks and confirm.',
                'The template "with client columns" also links the same products to a client.',
            ],
            'download_blank_cross' => 'Blank template + client columns',
        ],
        'client_products' => [
            'title' => '3. Client products',
            'why' => 'Link products to a client with its code, name, invoice description and selling price. The client\'s PI, CI and packing list use this data.',
            'where' => 'Companies → open the client → products tab → Quick Import',
            'how' => [
                'Keep the template header names: columns are recognised by name.',
                'Selling Price is the selling price. CI Price applies only on the Commercial Invoice (leave blank if unused).',
                'Do not name any column "Custom Price": use "CI Price".',
                'The template "with supplier columns" also links the same products to a supplier.',
            ],
            'download_blank_cross' => 'Blank template + supplier columns',
        ],
        'client_report' => [
            'title' => '4. Bulk update of a client\'s products',
            'why' => 'Change a client\'s prices, codes, invoice descriptions or NCM without editing products one by one.',
            'where' => 'Companies → open the client → products tab → Exportar Excel → edit → Importar Relatório',
            'how' => [
                'Always start from the client\'s own "Exportar Excel"; the sample only shows the layout.',
                'Products are found by SKU (column B). Unknown or unlinked SKUs are skipped: this import never creates products.',
                'An empty cell clears the field and an empty selling price becomes 0. NCM is the exception: empty does not clear it.',
                'Changing the original product name or description here does not change the product.',
                'Format the NCM column as text so leading zeros are kept.',
            ],
        ],
        'inquiry' => [
            'title' => '5. Client request for quotation (Inquiry)',
            'why' => 'Client requests come in their own format, Excel or PDF. The AI reads the document as it is and drafts the Inquiry for review.',
            'where' => 'Assistant → upload the file (.xlsx, .xls or .pdf) → "Inquiry"',
            'how' => [
                'No column adjustment needed. The sample shows what a real request usually looks like.',
                'Review the draft before saving, especially new products and suggested links.',
                'Helps the AI: one row per item, quantity and price in separate columns, client code in its own column.',
            ],
        ],
        'supplier_quotation' => [
            'title' => '6. Quotation received from a supplier',
            'why' => 'Supplier quotations come in their own format. The AI reads the document and creates the Supplier Quotation with prices, MOQ and packing.',
            'where' => 'Assistant → upload the file (.xlsx, .xls or .pdf) → "Supplier quotation"',
            'how' => [
                'Currency and incoterm written in the header (e.g. "FOB Ningbo / USD") help the AI.',
                'Review the draft before saving.',
            ],
        ],
        'supplier_response' => [
            'title' => '7. Supplier reply to an RFQ we sent',
            'why' => 'When a supplier answers a Supplier Quotation we already sent, the AI matches each reply line to the requested line and fills in the prices.',
            'where' => 'Supplier Quotation → Items → Import supplier response (AI)',
            'how' => [
                'Lines without a price in the reply stay unquoted.',
                'Check the line matching before saving.',
            ],
        ],
    ],

    'not_importable' => [
        'title' => 'What is not imported from spreadsheets',
        'items' => [
            'Clients and suppliers: created manually under Companies.',
            'Proforma Invoice, Purchase Order, Commercial Invoice and Packing List: generated by the system (Quotation → PI → PO; CI and PL come from the shipment). Import the Inquiry and quotations and follow the flow.',
            'Payments and receipts: entered manually.',
            'Shipment loading list: imported by a technical command, not from the screen.',
        ],
    ],
];
