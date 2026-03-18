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
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
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
                    'is_not_applicable' => $existing?->is_not_applicable ?? false,
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
            $answeredCount = 0;
            $totalCount = count($category->criteria);

            foreach ($category->criteria as $criterion) {
                $responseData = $this->responses[$criterion->id] ?? [];
                $hasAnswer = ($responseData['is_not_applicable'] ?? false)
                    || ($responseData['score'] ?? null) !== null
                    || ($responseData['passed'] ?? null) !== null;

                if ($hasAnswer) {
                    $answeredCount++;
                }

                $fields = [];

                $fields[] = Toggle::make("responses.{$criterion->id}.is_not_applicable")
                    ->label('N/A')
                    ->helperText(__('forms.helpers.mark_as_not_applicable'))
                    ->live()
                    ->afterStateUpdated(function ($state, \Filament\Schemas\Components\Utilities\Set $set) use ($criterion) {
                        if ($state) {
                            $set("responses.{$criterion->id}.score", null);
                            $set("responses.{$criterion->id}.passed", null);
                        }
                    });

                if ($criterion->type === CriterionType::SCORED) {
                    $fields[] = Radio::make("responses.{$criterion->id}.score")
                        ->label(__('forms.labels.score'))
                        ->options([
                            1 => '1 - Critical',
                            2 => '2 - Major NC',
                            3 => '3 - Minor NC',
                            4 => '4 - OK',
                            5 => '5 - Excellent',
                        ])
                        ->disabled(fn (Get $get) => $get("responses.{$criterion->id}.is_not_applicable"));
                } else {
                    $fields[] = Toggle::make("responses.{$criterion->id}.passed")
                        ->label(__('forms.labels.pass'))
                        ->onColor('success')
                        ->offColor('danger')
                        ->disabled(fn (Get $get) => $get("responses.{$criterion->id}.is_not_applicable"));
                }

                $fields[] = Textarea::make("responses.{$criterion->id}.notes")
                    ->label(__('forms.labels.notes_evidence'))
                    ->rows(2)
                    ->placeholder(__('forms.placeholders.observations_evidence_or_findings'))
                    ->columnSpanFull();

                $sectionLabel = $criterion->is_critical
                    ? $criterion->name . ' ⚠️ CRITICAL'
                    : $criterion->name;

                $criteriaFields[] = Section::make($sectionLabel)
                    ->description($criterion->description)
                    ->schema($fields)
                    ->collapsible()
                    ->collapsed($hasAnswer)
                    ->extraAttributes($criterion->is_critical ? ['class' => 'criterion-critical'] : []);
            }

            $badgeLabel = "{$answeredCount}/{$totalCount}";

            $tabs[] = Tab::make($category->name)
                ->icon($answeredCount === $totalCount ? 'heroicon-o-check-circle' : 'heroicon-o-clipboard-document-list')
                ->badge($badgeLabel)
                ->badgeColor($answeredCount === $totalCount ? 'success' : ($answeredCount > 0 ? 'warning' : 'gray'))
                ->schema($criteriaFields);
        }

        $tabs[] = Tab::make(__('forms.tabs.documents_photos'))
            ->icon('heroicon-o-camera')
            ->schema([
                FileUpload::make('documents')
                    ->label(__('forms.labels.upload_documents_photos'))
                    ->multiple()
                    ->disk('public')
                    ->directory('audit-documents/' . $this->getRecord()->id)
                    ->maxSize(10240)
                    ->acceptedFileTypes([
                        'image/jpeg', 'image/png', 'image/webp',
                        'application/pdf',
                        'application/msword',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    ])
                    ->imageEditor()
                    ->imageResizeMode('cover')
                    ->imageResizeTargetWidth('1920')
                    ->imageResizeTargetHeight('1920')
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

                    $isNA = $responseData['is_not_applicable'] ?? false;
                    $hasData = $isNA
                        || ($responseData['score'] ?? null) !== null
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
                            'score' => (! $isNA && $criterion->type === CriterionType::SCORED) ? ($responseData['score'] ?? null) : null,
                            'passed' => (! $isNA && $criterion->type === CriterionType::PASS_FAIL) ? ($responseData['passed'] ?? false) : null,
                            'is_not_applicable' => $isNA,
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
