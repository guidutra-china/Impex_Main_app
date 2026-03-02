<?php

namespace App\Domain\Inquiries\Observers;

use App\Domain\Inquiries\Models\ProjectTeamMember;
use App\Domain\Inquiries\Services\ProjectTeamNotificationService;

class ProjectTeamMemberObserver
{
    public function __construct(
        protected ProjectTeamNotificationService $notificationService,
    ) {}

    public function created(ProjectTeamMember $member): void
    {
        $member->loadMissing(['user', 'inquiry']);
        $this->notificationService->notifyMemberAdded($member);
    }

    public function updated(ProjectTeamMember $member): void
    {
        if ($member->isDirty('role')) {
            $oldRole = $member->getOriginal('role');
            $oldRoleLabel = is_string($oldRole) ? $oldRole : $oldRole->getLabel();

            $member->loadMissing(['user', 'inquiry']);
            $this->notificationService->notifyRoleChanged($member, $oldRoleLabel);
        }
    }

    public function deleted(ProjectTeamMember $member): void
    {
        $member->loadMissing(['user', 'inquiry']);
        $this->notificationService->notifyMemberRemoved($member);
    }
}
