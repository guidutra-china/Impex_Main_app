<?php

namespace App\Filament\Pages;

use App\Domain\Catalog\Actions\Import\GenerateProductImportTemplate;
use BackedEnum;
use Filament\Pages\Page;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Central de modelos de importação: para cada import do sistema, o que ele
 * faz, onde fica o botão e um Excel de exemplo (fictício) para download.
 * Os botões de importar continuam nas telas de origem.
 */
class ImportTemplates extends Page
{
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-document-arrow-down';

    protected static ?int $navigationSort = 59;

    protected static ?string $slug = 'catalog/import-templates';

    protected string $view = 'filament.pages.import-templates';

    /** Chave do item => arquivo em resources/import-templates. */
    public const EXAMPLES = [
        'catalog' => '01_Produtos_Catalogo.xlsx',
        'supplier_products' => '02_Produtos_Fornecedor.xlsx',
        'client_products' => '03_Produtos_Cliente.xlsx',
        'client_report' => '04_Atualizar_Produtos_Cliente.xlsx',
        'inquiry' => '05_Inquiry_Cliente_RFQ.xlsx',
        'supplier_quotation' => '06_Cotacao_Fornecedor.xlsx',
        'supplier_response' => '07_Resposta_Fornecedor_RFQ.xlsx',
    ];

    /** Itens com modelo em branco do Quick Import => papel. */
    public const BLANK_TEMPLATES = [
        'supplier_products' => 'supplier',
        'client_products' => 'client',
    ];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-products') ?? false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.catalog');
    }

    public static function getNavigationLabel(): string
    {
        return __('import_templates.navigation');
    }

    public function getTitle(): string
    {
        return __('import_templates.title');
    }

    public function downloadExample(string $key): BinaryFileResponse
    {
        abort_unless(array_key_exists($key, self::EXAMPLES), 404);

        return response()->download(resource_path('import-templates/'.self::EXAMPLES[$key]));
    }

    public function downloadBlank(string $key, bool $includeCrossRole = false): BinaryFileResponse
    {
        abort_unless(array_key_exists($key, self::BLANK_TEMPLATES), 404);

        $path = (new GenerateProductImportTemplate)->quickImport(self::BLANK_TEMPLATES[$key], $includeCrossRole);

        return response()->download($path)->deleteFileAfterSend();
    }
}
