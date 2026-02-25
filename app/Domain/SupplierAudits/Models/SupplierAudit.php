<?php

namespace App\Domain\SupplierAudits\Models;

use App\Domain\CRM\Models\Company;
use App\Domain\SupplierAudits\Enums\AuditResult;
use App\Domain\SupplierAudits\Enums\AuditStatus;
use App\Domain\SupplierAudits\Enums\AuditType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupplierAudit extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'reference',
        'audit_type',
        'status',
        'result',
        'scheduled_date',
        'conducted_date',
        'conducted_by',
        'reviewed_by',
        'reviewed_at',
        'location',
        'total_score',
        'summary',
        'corrective_actions',
        'next_audit_date',
    ];

    protected function casts(): array
    {
        return [
            'audit_type' => AuditType::class,
            'status' => AuditStatus::class,
            'result' => AuditResult::class,
            'scheduled_date' => 'date',
            'conducted_date' => 'date',
            'reviewed_at' => 'datetime',
            'next_audit_date' => 'date',
            'total_score' => 'decimal:2',
        ];
    }

    // --- Relationships ---

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function conductor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'conducted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(AuditResponse::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(AuditDocument::class);
    }

    // --- Scopes ---

    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', AuditStatus::SCHEDULED)
            ->where('scheduled_date', '<', now()->toDateString());
    }

    public function scopeUpcoming($query, int $days = 30)
    {
        return $query->where('status', AuditStatus::SCHEDULED)
            ->whereBetween('scheduled_date', [now()->toDateString(), now()->addDays($days)->toDateString()]);
    }

    // --- Helpers ---

    public function isEditable(): bool
    {
        return in_array($this->status, [AuditStatus::SCHEDULED, AuditStatus::IN_PROGRESS]);
    }

    public function hasFailedCriticalCriteria(): bool
    {
        return $this->responses()
            ->whereHas('criterion', fn ($q) => $q->where('is_critical', true)->where('type', 'pass_fail'))
            ->where('passed', false)
            ->exists();
    }

    public function getResponseForCriterion(int $criterionId): ?AuditResponse
    {
        return $this->responses->firstWhere('audit_criterion_id', $criterionId);
    }

    public function getCompletionPercentage(): float
    {
        $totalCriteria = AuditCategory::active()
            ->with('activeCriteria')
            ->get()
            ->pluck('activeCriteria')
            ->flatten()
            ->count();

        if ($totalCriteria === 0) {
            return 0;
        }

        $answeredCount = $this->responses()
            ->where(function ($q) {
                $q->whereNotNull('score')->orWhereNotNull('passed');
            })
            ->count();

        return round(($answeredCount / $totalCriteria) * 100, 1);
    }
}
