<?php

namespace App\Domain\Inquiries\Observers;

use App\Domain\Inquiries\Models\ProjectTeamMember;
use App\Domain\Inquiries\Services\ProjectTeamNotificationService;
use Illuminate\Support\Facades\Log;

class ProjectTeamMemberObserver
{
    public function __construct(
        protected ProjectTeamNotificationService $notificationService,
    ) {}

    public function created(ProjectTeamMember $member): void
    {
        try {
            $member->loadMissing(['user', 'inquiry']);
            $this->notificationService->notifyMemberAdded($member);
        } catch (\Throwable $e) {
            Log::warning('Failed to send project team notification (created)', [
                'member_id' => $member->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function updated(ProjectTeamMember $member): void
    {
        if ($member->isDirty('role')) {
            try {
                $oldRole = $member->getOriginal('role');
                $oldRoleLabel = is_string($oldRole) ? $oldRole : $oldRole->getLabel();
                $member->loadMissing(['user', 'inquiry']);
                $this->notificationService->notifyRoleChanged($member, $oldRoleLabel);
            } catch (\Throwable $e) {
                Log::warning('Failed to send project team notification (role changed)', [
                    'member_id' => $member->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function deleted(ProjectTeamMember $member): void
    {
        try {
            $member->loadMissing(['user', 'inquiry']);
            $this->notificationService->notifyMemberRemoved($member);
        } catch (\Throwable $e) {
            Log::warning('Failed to send project team notification (removed)', [
                'member_id' => $member->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
