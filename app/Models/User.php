<?php

namespace App\Models;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\ConversationParticipant;
use App\Domain\Users\Enums\UserType;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasTenants
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'job_title',
        'type',
        'company_id',
        'is_admin',
        'status',
        'locale',
        'records_per_page',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'type' => UserType::class,
            'records_per_page' => 'integer',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() === 'admin') {
            return $this->type->isInternal() && $this->status === 'active';
        }

        if ($panel->getId() === 'portal') {
            return $this->type === UserType::CLIENT
                && $this->status === 'active'
                && $this->company_id !== null;
        }

        if ($panel->getId() === 'supplier-portal') {
            return $this->type === UserType::SUPPLIER
                && $this->status === 'active'
                && $this->company_id !== null;
        }

        if ($panel->getId() === 'forwarder-portal') {
            return $this->type === UserType::FORWARDER
                && $this->status === 'active'
                && $this->company_id !== null
                && $this->company?->hasRole(CompanyRole::FORWARDER);
        }

        // Fair panel: internal (staff) users only
        if ($panel->getId() === 'fair') {
            return $this->type === UserType::INTERNAL
                && $this->status === 'active';
        }

        return false;
    }

    // --- Tenancy (Filament) ---

    public function getTenants(Panel $panel): array|Collection
    {
        if (in_array($panel->getId(), ['portal', 'supplier-portal', 'forwarder-portal'])) {
            return Company::where('id', $this->company_id)->get();
        }

        return collect();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $this->company_id === $tenant->getKey();
    }

    // --- Relationships ---

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class, 'conversation_participants')
            ->using(ConversationParticipant::class)
            ->withPivot(['role', 'last_read_at', 'muted_at', 'left_at'])
            ->withTimestamps()
            ->wherePivotNull('left_at');
    }

    /**
     * Count of unread messages across all conversations the user participates in.
     * Counts messages newer than the user's per-conversation last_read_at,
     * excluding messages the user authored themselves.
     */
    public function unreadMessagesCount(): int
    {
        return \App\Domain\Messaging\Models\Message::query()
            ->whereHas('conversation.participants', function ($query) {
                $query->where('user_id', $this->id)->whereNull('left_at');
            })
            ->where('sender_id', '!=', $this->id)
            ->whereRaw(
                '(messages.created_at > (
                    select coalesce(last_read_at, "1970-01-01") from conversation_participants
                    where conversation_participants.conversation_id = messages.conversation_id
                      and conversation_participants.user_id = ?
                ))',
                [$this->id]
            )
            ->count();
    }

    // --- Helpers ---

    public function isInternal(): bool
    {
        return $this->type === UserType::INTERNAL;
    }

    public function isExternal(): bool
    {
        return $this->type?->isExternal() ?? false;
    }
}
