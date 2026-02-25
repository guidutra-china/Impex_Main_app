<?php

namespace App\Filament\Resources\CRM\SupplierAudits\Pages;

use App\Domain\SupplierAudits\Enums\AuditResult;
use App\Domain\SupplierAudits\Enums\AuditStatus;
use App\Domain\SupplierAudits\Services\AuditScoringService;
use App\Filament\Resources\CRM\SupplierAudits\SupplierAuditResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewSupplierAudit extends ViewRecord
{
    protected static string $resource = SupplierAuditResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('conduct')
                ->label('Conduct Audit')
                ->icon('heroicon-o-pencil-square')
                ->color('success')
                ->url(fn () => SupplierAuditResource::getUrl('conduct', ['record' => $this->record]))
                ->visible(fn () => in_array($this->record->status, [AuditStatus::SCHEDULED, AuditStatus::IN_PROGRESS])),

            Action::make('complete')
                ->label('Complete Audit')
                ->icon('heroicon-o-check-circle')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Complete Audit')
                ->modalDescription('This will calculate the final score and mark the audit as completed.')
                ->action(function () {
                    $scoring = app(AuditScoringService::class)->calculate($this->record);

                    $this->record->update([
                        'status' => AuditStatus::COMPLETED,
                        'total_score' => $scoring['total_score'],
                        'result' => $scoring['result'],
                        'conducted_date' => $this->record->conducted_date ?? now(),
                    ]);

                    Notification::make()
                        ->title('Audit completed')
                        ->body("Score: {$scoring['total_score']}/5.00 — Result: {$scoring['result']->getLabel()}")
                        ->success()
                        ->send();

                    $this->refreshFormData(['status', 'total_score', 'result', 'conducted_date']);
                })
                ->visible(fn () => $this->record->status === AuditStatus::IN_PROGRESS),

            Action::make('review')
                ->label('Review & Approve')
                ->icon('heroicon-o-shield-check')
                ->color('primary')
                ->form([
                    Select::make('result')
                        ->label('Final Result')
                        ->options(AuditResult::class)
                        ->required()
                        ->default(fn () => $this->record->result),
                    Textarea::make('corrective_actions')
                        ->label('Corrective Actions Required')
                        ->rows(3)
                        ->default(fn () => $this->record->corrective_actions),
                    DatePicker::make('next_audit_date')
                        ->label('Next Audit Date')
                        ->default(fn () => $this->record->next_audit_date),
                ])
                ->action(function (array $data) {
                    $this->record->update([
                        'status' => AuditStatus::REVIEWED,
                        'result' => $data['result'],
                        'corrective_actions' => $data['corrective_actions'],
                        'next_audit_date' => $data['next_audit_date'],
                        'reviewed_by' => auth()->id(),
                        'reviewed_at' => now(),
                    ]);

                    Notification::make()
                        ->title('Audit reviewed')
                        ->success()
                        ->send();

                    $this->refreshFormData(['status', 'result', 'corrective_actions', 'next_audit_date', 'reviewed_by', 'reviewed_at']);
                })
                ->visible(fn () => $this->record->status === AuditStatus::COMPLETED),

            EditAction::make()
                ->visible(fn () => in_array($this->record->status, [AuditStatus::SCHEDULED, AuditStatus::IN_PROGRESS])),
        ];
    }
}
