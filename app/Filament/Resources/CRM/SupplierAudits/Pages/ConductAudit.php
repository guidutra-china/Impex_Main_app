<?php

namespace App\Filament\Resources\CRM\SupplierAudits\Pages;

use App\Domain\SupplierAudits\Enums\AuditStatus;
use App\Domain\SupplierAudits\Enums\CriterionType;
use App\Domain\SupplierAudits\Models\AuditCategory;
use App\Domain\SupplierAudits\Models\AuditDocument;
use App\Domain\SupplierAudits\Models\AuditResponse;
use App\Domain\SupplierAudits\Services\AuditScoringService;
use App\Filament\Resources\CRM\SupplierAudits\SupplierAuditResource;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Support\Facades\DB;

class ConductAudit extends Page implements HasForms
{
    use InteractsWithForms;
    use InteractsWithRecord;

    protected static string $resource = SupplierAuditResource::class;

    protected string $view = 'filament.resources.crm.supplier-audits.pages.conduct-audit';

    protected static ?string $title = 'Conduct Audit';

    public array $responses = [];
    public array $documents = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        if (! in_array($this->record->status, [AuditStatus::SCHEDULED, AuditStatus::IN_PROGRESS])) {
            Notification::make()
                ->title(__('messages.audit_cannot_modify'))
                ->warning()
                ->send();

            $this->redirect(SupplierAuditResource::getUrl('view', ['record' => $this->record]));

            return;
        }

        if ($this->record->status === AuditStatus::SCHEDULED) {
            $this->record->update([
                'status' => AuditStatus::IN_PROGRESS,
                'conducted_by' => $this->record->conducted_by ?? auth()->id(),
            ]);
        }

        $this->loadExistingResponses();
    }

    protected function loadExistingResponses(): void
    {
        $existingResponses = $this->getRecord()->responses()
            ->with('criterion')
            ->get()
            ->keyBy('audit_criterion_id');

        $categories = AuditCategory::where('is_active', true)
            ->with(['criteria' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get();

        foreach ($categories as $category) {
            foreach ($category->criteria as $criterion) {
                $existing = $existingResponses->get($criterion->id);

                $this->responses[$criterion->id] = [
                    'score' => $existing?->score,
                    'passed' => $existing?->passed,
                    'notes' => $existing?->notes ?? '',
                ];
            }
        }
    }

    public function form(Schema $schema): Schema
    {
        $categories = AuditCategory::where('is_active', true)
            ->with(['criteria' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get();

        $tabs = [];

        foreach ($categories as $category) {
            $criteriaFields = [];

            foreach ($category->criteria as $criterion) {
                $fields = [
                    Placeholder::make("criterion_label_{$criterion->id}")
                        ->label($criterion->name)
                        ->content($criterion->description ?: 'No guidance notes')
                        ->columnSpanFull(),
                ];

                if ($criterion->type === CriterionType::SCORED) {
                    $fields[] = Radio::make("responses.{$criterion->id}.score")
                        ->label(__('forms.labels.score'))
                        ->options([
                            1 => '1 - Critical Non-Conformance',
                            2 => '2 - Major Non-Conformance',
                            3 => '3 - Minor Non-Conformance',
                            4 => '4 - Acceptable',
                            5 => '5 - Excellent',
                        ])
                        ->inline();
                } else {
                    $fields[] = Toggle::make("responses.{$criterion->id}.passed")
                        ->label(__('forms.labels.pass'))
                        ->onColor('success')
                        ->offColor('danger');
                }

                $fields[] = Textarea::make("responses.{$criterion->id}.notes")
                    ->label(__('forms.labels.notes_evidence'))
                    ->rows(2)
                    ->placeholder(__('forms.placeholders.observations_evidence_or_findings'))
                    ->columnSpanFull();

                $criteriaFields[] = Section::make($criterion->name . ($criterion->is_critical ? ' ⚠️ CRITICAL' : ''))
                    ->description($criterion->description)
                    ->schema($fields)
                    ->collapsible()
                    ->collapsed(false);
            }

            $tabs[] = Tab::make($category->name)
                ->icon('heroicon-o-clipboard-document-list')
                ->badge(count($category->criteria))
                ->schema($criteriaFields);
        }

        $tabs[] = Tab::make(__('forms.tabs.documents_photos'))
            ->icon('heroicon-o-photo')
            ->schema([
                FileUpload::make('documents')
                    ->label(__('forms.labels.upload_documents_photos'))
                    ->multiple()
                    ->directory('audit-documents/' . $this->getRecord()->id)
                    ->maxSize(10240)
                    ->acceptedFileTypes([
                        'image/jpeg', 'image/png', 'image/webp',
                        'application/pdf',
                        'application/msword',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    ])
                    ->helperText(__('forms.helpers.upload_photos_certificates_reports_or_any_supporting'))
                    ->columnSpanFull(),
            ]);

        return $schema
            ->components([
                Tabs::make('Audit Evaluation')
                    ->tabs($tabs)
                    ->columnSpanFull(),
            ]);
    }

    public function save(): void
    {
        $record = $this->getRecord();

        DB::transaction(function () use ($record) {
            $categories = AuditCategory::where('is_active', true)
                ->with(['criteria' => fn ($q) => $q->where('is_active', true)])
                ->get();

            foreach ($categories as $category) {
                foreach ($category->criteria as $criterion) {
                    $responseData = $this->responses[$criterion->id] ?? null;

                    if (! $responseData) {
                        continue;
                    }

                    $hasData = ($responseData['score'] ?? null) !== null
                        || ($responseData['passed'] ?? null) !== null
                        || ! empty($responseData['notes']);

                    if (! $hasData) {
                        continue;
                    }

                    AuditResponse::updateOrCreate(
                        [
                            'supplier_audit_id' => $record->id,
                            'audit_criterion_id' => $criterion->id,
                        ],
                        [
                            'score' => $criterion->type === CriterionType::SCORED ? ($responseData['score'] ?? null) : null,
                            'passed' => $criterion->type === CriterionType::PASS_FAIL ? ($responseData['passed'] ?? false) : null,
                            'notes' => $responseData['notes'] ?? null,
                        ]
                    );
                }
            }

            if (! empty($this->documents)) {
                foreach ($this->documents as $path) {
                    AuditDocument::create([
                        'supplier_audit_id' => $record->id,
                        'type' => $this->guessDocumentType($path),
                        'title' => basename($path),
                        'disk' => 'local',
                        'path' => $path,
                        'original_name' => basename($path),
                        'size' => 0,
                    ]);
                }
            }
        });

        Notification::make()
            ->title(__('messages.audit_responses_saved'))
            ->success()
            ->send();
    }

    public function saveAndComplete(): void
    {
        $this->save();

        $record = $this->getRecord()->fresh();
        $scoring = app(AuditScoringService::class)->calculate($record);

        $record->update([
            'status' => AuditStatus::COMPLETED,
            'total_score' => $scoring['total_score'],
            'result' => $scoring['result'],
            'conducted_date' => now(),
        ]);

        $scoreText = $scoring['total_score'] !== null
            ? number_format($scoring['total_score'], 2) . '/5.00'
            : 'N/A';
        $resultText = $scoring['result']?->getLabel() ?? 'Pending';

        Notification::make()
            ->title(__('messages.audit_completed'))
            ->body("Final Score: {$scoreText} — Result: {$resultText}")
            ->success()
            ->send();

        $this->redirect(SupplierAuditResource::getUrl('view', ['record' => $record]));
    }

    protected function guessDocumentType(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'jpg', 'jpeg', 'png', 'webp', 'gif' => 'photo',
            'pdf' => 'certificate',
            'doc', 'docx' => 'report',
            default => 'other',
        };
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label(__('forms.labels.save_progress'))
                ->icon('heroicon-o-bookmark')
                ->color('gray')
                ->action('save'),

            Action::make('saveAndComplete')
                ->label(__('forms.labels.save_complete_audit'))
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Complete Audit')
                ->modalDescription('This will save all responses, calculate the final score, and mark the audit as completed. You will not be able to modify responses after this.')
                ->action('saveAndComplete'),
        ];
    }
}
