<?php

namespace App\Domain\Inquiries\Models;

use App\Domain\Inquiries\Enums\ProjectTeamRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectTeamMember extends Model
{
    protected $table = 'project_team_members';

    protected $fillable = [
        'inquiry_id',
        'user_id',
        'role',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'role' => ProjectTeamRole::class,
        ];
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeByRole($query, ProjectTeamRole $role)
    {
        return $query->where('role', $role);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
