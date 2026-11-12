<?php

return [
    'navigation' => 'Modelos de Importação',
    'title' => 'Modelos de Importação',
    'intro' => 'Tudo o que entra no sistema por planilha, com um Excel de exemplo de cada import. Os exemplos usam produtos e empresas fictícios (códigos EX-, CLI-, SUP-): sirvam-se do formato, não importem os arquivos como estão.',
    'rules' => [
        'Preços em valor normal (12.50), nunca em centavos. Pesos em kg, medidas em cm.',
        'O sistema lê só a primeira aba da planilha.',
        'Nos imports de produto, só as 15 primeiras colunas (A–O) podem ser mapeadas.',
        'Fotos coladas na linha do produto entram junto com ele.',
        'Tamanho máximo do arquivo: 50 MB.',
    ],
    'where' => 'Onde importar',
    'download_example' => 'Baixar exemplo',
    'download_blank' => 'Modelo em branco',

    'items' => [
        'catalog' => [
            'title' => '1. Cadastro de produtos no catálogo',
            'why' => 'Cadastrar dezenas de produtos de uma vez, com HS code, peso, caixa e CBM. Esses dados alimentam o packing list e o cálculo de volume do embarque.',
            'where' => 'Catálogo → Produtos → Import from Spreadsheet',
            'how' => [
                'Suba o arquivo, escolha a moeda e, se quiser, já vincule a um cliente e/ou fornecedor.',
                'Informe a linha do cabeçalho e diga, coluna a coluna, qual campo ela é (o mapeamento é manual).',
                'Crie os blocos: cada bloco é uma categoria com linha inicial e final. No exemplo, as linhas 2–4 são de fitness e as 5–7 de iluminação.',
                'Confira a prévia e confirme.',
            ],
        ],
        'supplier_products' => [
            'title' => '2. Produtos de um fornecedor',
            'why' => 'Vincular produtos a um fornecedor com o código e o preço de compra dele. A PO e os documentos do fornecedor usam esses dados.',
            'where' => 'Empresas → abrir o fornecedor → aba de produtos → Quick Import',
            'how' => [
                'Mantenha os nomes de cabeçalho do modelo: o sistema reconhece as colunas pelo nome.',
                'Reference Code é o nosso código; Supplier Code é o código do fornecedor; Purchase Price é o preço de compra.',
                'A moeda é escolhida na tela. Defina os blocos por categoria e confirme.',
                'O modelo "com colunas do cliente" vincula os mesmos produtos a um cliente ao mesmo tempo.',
            ],
            'download_blank_cross' => 'Modelo em branco + colunas do cliente',
        ],
        'client_products' => [
            'title' => '3. Produtos de um cliente',
            'why' => 'Vincular produtos a um cliente com o código, o nome e a descrição de invoice dele e o preço de venda. PI, CI e packing list do cliente usam esses dados.',
            'where' => 'Empresas → abrir o cliente → aba de produtos → Quick Import',
            'how' => [
                'Mantenha os nomes de cabeçalho do modelo: o sistema reconhece as colunas pelo nome.',
                'Selling Price é o preço de venda. CI Price é o preço que vale só na Commercial Invoice (deixe vazio se não usar).',
                'Não chame nenhuma coluna de "Custom Price": use "CI Price".',
                'O modelo "com colunas do fornecedor" vincula os mesmos produtos a um fornecedor ao mesmo tempo.',
            ],
            'download_blank_cross' => 'Modelo em branco + colunas do fornecedor',
        ],
        'client_report' => [
            'title' => '4. Atualizar em massa os produtos de um cliente',
            'why' => 'Trocar preços, códigos, descrições de invoice ou NCM de um cliente sem editar produto por produto.',
            'where' => 'Empresas → abrir o cliente → aba de produtos → Exportar Excel → editar → Importar Relatório',
            'how' => [
                'Sempre comece pelo "Exportar Excel" do próprio cliente; o exemplo só mostra o formato.',
                'O produto é achado pelo SKU (coluna B). SKU desconhecido ou não vinculado ao cliente é ignorado: este import não cria produtos.',
                'Célula vazia apaga o campo, e preço de venda vazio vira 0. A exceção é o NCM: vazio não apaga.',
                'Mudar nome ou descrição original do produto nesta planilha não altera o cadastro.',
                'Formate a coluna de NCM como texto para não perder zeros à esquerda.',
            ],
        ],
        'inquiry' => [
            'title' => '5. Pedido de cotação do cliente (Inquiry)',
            'why' => 'O pedido do cliente chega no formato dele, em Excel ou PDF. A IA lê o documento como veio e monta a Inquiry para você revisar.',
            'where' => 'Assistente → enviar o arquivo (.xlsx, .xls ou .pdf) → "Inquiry (pedido de cliente)"',
            'how' => [
                'Não precisa ajustar colunas. O exemplo mostra como costuma vir um pedido real.',
                'Revise o rascunho antes de gravar, principalmente os produtos novos e os vínculos sugeridos.',
                'Ajuda a IA: uma linha por item, quantidade e preço em colunas separadas, código do cliente em coluna própria.',
            ],
        ],
        'supplier_quotation' => [
            'title' => '6. Cotação recebida de um fornecedor',
            'why' => 'A cotação do fornecedor chega no formato dele. A IA lê o documento e cria a Supplier Quotation com preços, MOQ e embalagem.',
            'where' => 'Assistente → enviar o arquivo (.xlsx, .xls ou .pdf) → "Cotação de fornecedor"',
            'how' => [
                'Moeda e incoterm escritos no cabeçalho (ex.: "FOB Ningbo / USD") ajudam a IA a acertar.',
                'Revise o rascunho antes de gravar.',
            ],
        ],
        'supplier_response' => [
            'title' => '7. Resposta do fornecedor a uma RFQ enviada',
            'why' => 'Quando o fornecedor responde a uma Supplier Quotation que já enviamos, a IA casa cada linha da resposta com a linha pedida e preenche os preços.',
            'where' => 'Supplier Quotation → Itens → Importar resposta do fornecedor (IA)',
            'how' => [
                'Linhas sem preço na resposta ficam sem cotação.',
                'Confira o casamento das linhas antes de gravar.',
            ],
        ],
    ],

    'not_importable' => [
        'title' => 'O que não se importa por planilha',
        'items' => [
            'Clientes e fornecedores: cadastro manual em Empresas.',
            'Proforma Invoice, Purchase Order, Commercial Invoice e Packing List: são gerados pelo sistema (Quotation → PI → PO; CI e PL saem do embarque). O caminho é importar a Inquiry e as cotações e seguir o fluxo.',
            'Pagamentos e recebimentos: lançamento manual.',
            'Loading list do embarque: importada por comando técnico, não pela tela.',
        ],
    ],
];
