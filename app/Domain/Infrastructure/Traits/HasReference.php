<?php

namespace App\Domain\Infrastructure\Traits;

use App\Domain\Infrastructure\Actions\GenerateReferenceAction;
use App\Domain\Infrastructure\Enums\DocumentType;

trait HasReference
{
    public static function bootHasReference(): void
    {
        static::creating(function ($model) {
            if (empty($model->reference)) {
                $model->reference = app(GenerateReferenceAction::class)
                    ->execute($model->getDocumentType());
            }
        });
    }

    abstract public function getDocumentType(): DocumentType;
}
