<?php

namespace App\Domain\Infrastructure\Pdf;

use App\Domain\Infrastructure\Models\Document;
use App\Domain\Infrastructure\Pdf\Templates\AbstractPdfTemplate;
use App\Domain\Infrastructure\Services\DocumentService;

class PdfGeneratorService
{
    public function __construct(
        protected PdfRenderer $renderer,
        protected DocumentService $documentService,
    ) {}

    public function generate(AbstractPdfTemplate $template): Document
    {
        $content = $this->renderer->render(
            view: $template->getView(),
            data: $template->getData(),
            paper: $template->getPaper(),
            orientation: $template->getOrientation(),
        );

        return $this->documentService->storeGenerated(
            documentable: $template->getModel(),
            content: $content,
            type: $template->getDocumentType(),
            name: $template->getFilename(),
            extension: 'pdf',
        );
    }

    public function preview(AbstractPdfTemplate $template): string
    {
        return $this->renderer->render(
            view: $template->getView(),
            data: $template->getData(),
            paper: $template->getPaper(),
            orientation: $template->getOrientation(),
        );
    }
}
