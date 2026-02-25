<?php

namespace App\Filament\Resources\CRM\SupplierAudits\Pages;

use App\Domain\SupplierAudits\Enums\AuditStatus;
use App\Domain\SupplierAudits\Enums\CriterionType;
use App\Domain\SupplierAudits\Models\AuditCategory;
use App\Domain\SupplierAudits\Models\AuditDocument;
use App\Domain\SupplierAudits\Models\AuditResponse;
use App\Domain\SupplierAudits\Models\SupplierAudit;
use App\Domain\SupplierAudits\Services\AuditScoringService;
use App\Filament\Resources\CRM\SupplierAudits\SupplierAuditResource;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Support\Facades\DB;

class ConductAudit extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $resource = SupplierAuditResource::class;

    protected static string $view = 'filament.resources.crm.supplier-audits.pages.conduct-audit';

    protected static ?string $title = 'Conduct Audit';

    public SupplierAudit $record;

    public array $responses = [];
    public array $documents = [];

    public function mount(int|string $record): void
    {
        $this->record = SupplierAudit::findOrFail($record);

        if (!in_array($this->record->status, [AuditStatus::SCHEDULED, AuditStatus::IN_PROGRESS])) {
            Notification::make()
                ->title('This audit cannot be modified')
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
        $existingResponses = $this->record->responses()
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

    public function form(Form $form): Form
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
                        ->label('Score')
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
                        ->label('Pass')
                        ->onColor('success')
                        ->offColor('danger');
                }

                $fields[] = Textarea::make("responses.{$criterion->id}.notes")
                    ->label('Notes / Evidence')
                    ->rows(2)
                    ->placeholder('Observations, evidence, or findings')
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

        $tabs[] = Tab::make('Documents & Photos')
            ->icon('heroicon-o-photo')
            ->schema([
                FileUpload::make('documents')
                    ->label('Upload Documents & Photos')
                    ->multiple()
                    ->directory('audit-documents/' . $this->record->id)
                    ->maxSize(10240)
                    ->acceptedFileTypes([
                        'image/jpeg', 'image/png', 'image/webp',
                        'application/pdf',
                        'application/msword',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    ])
                    ->helperText('Upload photos, certificates, reports, or any supporting documents. Max 10MB per file.')
                    ->columnSpanFull(),
            ]);

        return $form
            ->schema([
                Tabs::make('Audit Evaluation')
                    ->tabs($tabs)
                    ->columnSpanFull(),
            ]);
    }

    public function save(): void
    {
        DB::transaction(function () {
            $categories = AuditCategory::where('is_active', true)
                ->with(['criteria' => fn ($q) => $q->where('is_active', true)])
                ->get();

            foreach ($categories as $category) {
                foreach ($category->criteria as $criterion) {
                    $responseData = $this->responses[$criterion->id] ?? null;

                    if (!$responseData) {
                        continue;
                    }

                    $hasData = ($responseData['score'] ?? null) !== null
                        || ($responseData['passed'] ?? null) !== null
                        || !empty($responseData['notes']);

                    if (!$hasData) {
                        continue;
                    }

                    AuditResponse::updateOrCreate(
                        [
                            'supplier_audit_id' => $this->record->id,
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

            if (!empty($this->documents)) {
                foreach ($this->documents as $path) {
                    AuditDocument::create([
                        'supplier_audit_id' => $this->record->id,
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
            ->title('Audit responses saved')
            ->success()
            ->send();
    }

    public function saveAndComplete(): void
    {
        $this->save();

        $scoring = app(AuditScoringService::class)->calculate($this->record->fresh());

        $this->record->update([
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
            ->title('Audit completed')
            ->body("Final Score: {$scoreText} — Result: {$resultText}")
            ->success()
            ->send();

        $this->redirect(SupplierAuditResource::getUrl('view', ['record' => $this->record]));
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
                ->label('Save Progress')
                ->icon('heroicon-o-bookmark')
                ->color('gray')
                ->action('save'),

            Action::make('saveAndComplete')
                ->label('Save & Complete Audit')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Complete Audit')
                ->modalDescription('This will save all responses, calculate the final score, and mark the audit as completed. You will not be able to modify responses after this.')
                ->action('saveAndComplete'),
        ];
    }
}
