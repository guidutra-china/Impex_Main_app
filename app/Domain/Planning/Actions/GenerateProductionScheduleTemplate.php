<?php

namespace App\Domain\Planning\Actions;

use App\Domain\Catalog\Services\ProductIdentityResolver;
use App\Domain\Planning\Models\ProductionSchedule;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

class GenerateProductionScheduleTemplate
{
    public function execute(ProductionSchedule $schedule): string
    {
        $pi = $schedule->proformaInvoice;
        $piItems = $pi->items()->with('product.companies')->orderBy('sort_order')->get();

        // Planilha enviada ao fornecedor: nomeia o produto como ELE o conhece.
        // matchPiItem() casa a planilha de volta por esse nome/código.
        $identity = ProductIdentityResolver::forSupplier($schedule->supplier_company_id);
        $identity->warm($piItems->map(fn ($item) => $item->product));

        $filename = 'production_schedule_template_'.str($pi->reference)->slug().'_v'.$schedule->version.'.xlsx';
        $path = storage_path('app/temp/'.$filename);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        $writer = new Writer;
        $writer->openToFile($path);

        $headerStyle = (new Style)
            ->setFontBold()
            ->setFontSize(11)
            ->setBackgroundColor('4472C4')
            ->setFontColor(Color::WHITE);

        $subHeaderStyle = (new Style)
            ->setFontBold()
            ->setFontSize(10)
            ->setBackgroundColor('D9E2F3')
            ->setFontColor('1F3864');

        $hintStyle = (new Style)
            ->setFontItalic()
            ->setFontSize(9)
            ->setFontColor('808080');

        $dataStyle = (new Style)
            ->setFontSize(10);

        $lockedStyle = (new Style)
            ->setFontSize(10)
            ->setBackgroundColor('F2F2F2');

        // Row 1: Title
        $writer->addRow(new Row(
            [
                Cell::fromValue('PRODUCTION SCHEDULE — '.$pi->reference),
                Cell::fromValue(''),
                // O rótulo diz "Supplier", então o nome tem de ser o do
                // fornecedor do cronograma — antes imprimia o do cliente da PI.
                Cell::fromValue('Supplier: '.($schedule->supplierCompany?->name ?? '—')),
            ],
            $subHeaderStyle
        ));

        // Row 2: Headers
        $headers = ['Product', 'PI Qty', 'Date (dd/mm/yyyy)', 'Quantity Produced'];
        $writer->addRow(new Row(
            array_map(fn ($v) => Cell::fromValue($v), $headers),
            $headerStyle
        ));

        // Row 3: Hints
        $hints = [
            'Do not modify this column',
            'Total ordered quantity (reference only)',
            'Enter the production date for this batch',
            'Enter the quantity produced on this date',
        ];
        $writer->addRow(new Row(
            array_map(fn ($v) => Cell::fromValue($v), $hints),
            $hintStyle
        ));

        // Data rows: one row per PI item as starting point
        foreach ($piItems as $item) {
            $productName = $identity->resolve($item->product, lineName: $item->description)->name;
            $writer->addRow(new Row(
                [
                    Cell::fromValue($productName),
                    Cell::fromValue($item->quantity),
                    Cell::fromValue(''),
                    Cell::fromValue(''),
                ],
                $dataStyle
            ));
        }

        // Add extra blank rows for the supplier to add more dates per product
        for ($i = 0; $i < 20; $i++) {
            $writer->addRow(new Row(
                [
                    Cell::fromValue(''),
                    Cell::fromValue(''),
                    Cell::fromValue(''),
                    Cell::fromValue(''),
                ],
                $dataStyle
            ));
        }

        $writer->close();

        return $path;
    }
}
