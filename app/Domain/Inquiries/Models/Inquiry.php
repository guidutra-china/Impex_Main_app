<?php

namespace App\Domain\Inquiries\Models;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Models\Contact;
use App\Domain\Infrastructure\Enums\DocumentType;
use App\Domain\Infrastructure\Traits\HasDocuments;
use App\Domain\Infrastructure\Traits\HasReference;
use App\Domain\Infrastructure\Traits\HasStateMachine;
use App\Domain\Inquiries\Enums\InquirySource;
use App\Domain\Inquiries\Enums\InquiryStatus;
use App\Domain\Inquiries\Enums\ProjectTeamRole;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Inquiry extends Model
{
    use HasFactory, SoftDeletes, HasReference, HasStateMachine, HasDocuments;

    protected $fillable = [
        'reference',
        'company_id',
        'contact_id',
        'status',
        'source',
        'currency_code',
        'received_at',
        'deadline',
        'notes',
        'internal_notes',
        'created_by',
        'responsible_user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => InquiryStatus::class,
            'source' => InquirySource::class,
            'received_at' => 'date',
            'deadline' => 'date',
        ];
    }

    // --- HasReference ---

    public function getDocumentType(): DocumentType
    {
        return DocumentType::INQUIRY;
    }

    // --- HasStateMachine ---

    public static function allowedTransitions(): array
    {
        return [
            InquiryStatus::RECEIVED->value => [
                InquiryStatus::QUOTING->value,
                InquiryStatus::CANCELLED->value,
            ],
            InquiryStatus::QUOTING->value => [
                InquiryStatus::QUOTED->value,
                InquiryStatus::CANCELLED->value,
            ],
            InquiryStatus::QUOTED->value => [
                InquiryStatus::WON->value,
                InquiryStatus::LOST->value,
                InquiryStatus::QUOTING->value,
            ],
            InquiryStatus::WON->value => [],
            InquiryStatus::LOST->value => [
                InquiryStatus::QUOTING->value,
            ],
            InquiryStatus::CANCELLED->value => [],
        ];
    }

    // --- Boot ---

    protected static function booted(): void
    {
        static::creating(function (Inquiry $inquiry) {
            if (empty($inquiry->received_at)) {
                $inquiry->received_at = now()->toDateString();
            }

            if (empty($inquiry->created_by)) {
                $inquiry->created_by = auth()->id();
            }
        });
    }

    // --- Relationships ---

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function teamMembers(): HasMany
    {
        return $this->hasMany(ProjectTeamMember::class);
    }

    public function getTeamMemberByRole(ProjectTeamRole $role): ?User
    {
        $member = $this->teamMembers()->byRole($role)->first();

        return $member?->user;
    }

    public function items(): HasMany
    {
        return $this->hasMany(InquiryItem::class)->orderBy('sort_order');
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }

    public function supplierQuotations(): HasMany
    {
        return $this->hasMany(SupplierQuotation::class);
    }

    // --- Scopes ---

    public function scopeForClient($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeActive($query)
    {
        return $query->whereNotIn('status', [
            InquiryStatus::CANCELLED,
            InquiryStatus::WON,
            InquiryStatus::LOST,
        ]);
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', [
            InquiryStatus::RECEIVED,
            InquiryStatus::QUOTING,
        ]);
    }
}
