<?php

namespace App\Domain\SupplierAudits\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditResponse extends Model
{
    protected $fillable = [
        'supplier_audit_id',
        'audit_criterion_id',
        'score',
        'passed',
        'is_not_applicable',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'passed' => 'boolean',
            'is_not_applicable' => 'boolean',
        ];
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(SupplierAudit::class, 'supplier_audit_id');
    }

    public function criterion(): BelongsTo
    {
        return $this->belongsTo(AuditCriterion::class, 'audit_criterion_id');
    }
}
