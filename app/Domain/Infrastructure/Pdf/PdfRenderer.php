<?php

namespace App\Domain\Infrastructure\Pdf;

use Barryvdh\DomPDF\Facade\Pdf;

class PdfRenderer
{
    public function render(
        string $view,
        array $data = [],
        string $paper = 'a4',
        string $orientation = 'portrait',
    ): string {
        $pdf = Pdf::loadView($view, $data)
            ->setPaper($paper, $orientation)
            ->setOption('isRemoteEnabled', true)
            ->setOption('isHtml5ParserEnabled', true)
            ->setOption('defaultFont', 'DejaVu Sans');

        return $pdf->output();
    }
}
