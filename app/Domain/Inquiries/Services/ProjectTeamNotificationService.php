<?php

namespace App\Domain\Inquiries\Services;

use App\Domain\Inquiries\Models\ProjectTeamMember;
use App\Filament\Resources\Inquiries\InquiryResource;
use App\Models\User;
use Filament\Notifications\Notification;

class ProjectTeamNotificationService
{
    public function notifyMemberAdded(ProjectTeamMember $member): void
    {
        $user = $member->user;
        $inquiry = $member->inquiry;
        $addedBy = auth()->user();

        if (! $user || ! $inquiry) {
            return;
        }

        if ($user->id === $addedBy?->id) {
            return;
        }

        $url = InquiryResource::getUrl('edit', ['record' => $inquiry]);

        Notification::make()
            ->title(__('notifications.project_team.added_title'))
            ->body(__('notifications.project_team.added_body', [
                'inquiry' => $inquiry->reference ?? '#' . $inquiry->id,
                'role' => $member->role->getLabel(),
                'by' => $addedBy?->name ?? 'System',
            ]))
            ->icon('heroicon-o-user-plus')
            ->info()
            ->actions([
                \Filament\Notifications\Actions\Action::make('view_inquiry')
                    ->label(__('notifications.project_team.view_inquiry'))
                    ->url($url)
                    ->markAsRead(),
            ])
            ->sendToDatabase($user);
    }

    public function notifyMemberRemoved(ProjectTeamMember $member): void
    {
        $user = $member->user;
        $inquiry = $member->inquiry;
        $removedBy = auth()->user();

        if (! $user || ! $inquiry) {
            return;
        }

        if ($user->id === $removedBy?->id) {
            return;
        }

        Notification::make()
            ->title(__('notifications.project_team.removed_title'))
            ->body(__('notifications.project_team.removed_body', [
                'inquiry' => $inquiry->reference ?? '#' . $inquiry->id,
                'by' => $removedBy?->name ?? 'System',
            ]))
            ->icon('heroicon-o-user-minus')
            ->warning()
            ->sendToDatabase($user);
    }

    public function notifyRoleChanged(ProjectTeamMember $member, string $oldRole): void
    {
        $user = $member->user;
        $inquiry = $member->inquiry;
        $changedBy = auth()->user();

        if (! $user || ! $inquiry) {
            return;
        }

        if ($user->id === $changedBy?->id) {
            return;
        }

        $url = InquiryResource::getUrl('edit', ['record' => $inquiry]);

        Notification::make()
            ->title(__('notifications.project_team.role_changed_title'))
            ->body(__('notifications.project_team.role_changed_body', [
                'inquiry' => $inquiry->reference ?? '#' . $inquiry->id,
                'old_role' => $oldRole,
                'new_role' => $member->role->getLabel(),
                'by' => $changedBy?->name ?? 'System',
            ]))
            ->icon('heroicon-o-arrows-right-left')
            ->info()
            ->actions([
                \Filament\Notifications\Actions\Action::make('view_inquiry')
                    ->label(__('notifications.project_team.view_inquiry'))
                    ->url($url)
                    ->markAsRead(),
            ])
            ->sendToDatabase($user);
    }

    public function notifyResponsibleAssigned(User $user, string $documentType, string $documentReference, string $url): void
    {
        $assignedBy = auth()->user();

        if ($user->id === $assignedBy?->id) {
            return;
        }

        Notification::make()
            ->title(__('notifications.project_team.responsible_assigned_title'))
            ->body(__('notifications.project_team.responsible_assigned_body', [
                'document_type' => $documentType,
                'reference' => $documentReference,
                'by' => $assignedBy?->name ?? 'System',
            ]))
            ->icon('heroicon-o-clipboard-document-check')
            ->success()
            ->actions([
                \Filament\Notifications\Actions\Action::make('view_document')
                    ->label(__('notifications.project_team.view_document'))
                    ->url($url)
                    ->markAsRead(),
            ])
            ->sendToDatabase($user);
    }
}
