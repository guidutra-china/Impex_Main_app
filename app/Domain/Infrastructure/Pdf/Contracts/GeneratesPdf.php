<?php

namespace App\Domain\Infrastructure\Pdf\Contracts;

interface GeneratesPdf
{
    public function getPdfTemplateName(): string;

    public function getPdfFilename(): string;
}
