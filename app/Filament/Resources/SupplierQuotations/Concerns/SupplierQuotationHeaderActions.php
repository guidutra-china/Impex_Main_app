<?php

namespace App\Filament\Resources\SupplierQuotations\Concerns;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Models\Contact;
use App\Domain\Infrastructure\Actions\TransitionStatusAction;
use App\Domain\Infrastructure\Excel\Templates\RfqExcelTemplate;
use App\Domain\Infrastructure\Pdf\Templates\RfqPdfTemplate;
use App\Domain\Quotations\Actions\CreateQuotationFromSupplierQuotationAction;
use App\Domain\Quotations\Enums\CommissionType;
use App\Domain\Quotations\Enums\Incoterm;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Exceptions\QuotationLockedException;
use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\PaymentTerm;
use App\Domain\SupplierQuotations\Actions\SyncInquiryFromSupplierQuotationAction;
use App\Domain\SupplierQuotations\Enums\SupplierQuotationStatus;
use App\Filament\Actions\GenerateExcelAction;
use App\Filament\Actions\GeneratePdfAction;
use App\Filament\Actions\SendDocumentByEmailAction;
use App\Filament\Resources\Quotations\QuotationResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Facades\Log;

/**
 * Concrete slot implementations for SupplierQuotation pages (View + Edit).
 *
 * Both ViewSupplierQuotation and EditSupplierQuotation use this trait
 * (alongside HasOperationsHeaderActions) to render an identical header.
 */
trait SupplierQuotationHeaderActions
{
    protected function documentsActionGroup(): ?ActionGroup
    {
        return ActionGroup::make([
            GeneratePdfAction::make(
                templateClass: RfqPdfTemplate::class,
                label: 'Generate RFQ',
                icon: 'heroicon-o-document-arrow-down',
                formSchema: [
                    Toggle::make('show_target_price')
                        ->label('Include Target Price')
                        ->helperText('Show the client\'s target price in the RFQ document')
                        ->default(false),
                ],
            ),
            GeneratePdfAction::download(
                documentType: 'rfq_pdf',
                label: 'Download RFQ',
            ),
            GeneratePdfAction::preview(
                templateClass: RfqPdfTemplate::class,
                label: 'Preview RFQ',
                formSchema: [
                    Toggle::make('show_target_price')
                        ->label('Include Target Price')
                        ->helperText('Show the client\'s target price in the RFQ document')
                        ->live()
                        ->default(false),
                ],
            ),
            GenerateExcelAction::make(
                templateClass: RfqExcelTemplate::class,
                label: 'Generate RFQ Excel',
                icon: 'heroicon-o-table-cells',
                formSchema: [
                    Toggle::make('show_target_price')
                        ->label('Include Target Price')
                        ->default(false),
                ],
            ),
            GenerateExcelAction::downloadStored(
                documentType: 'rfq_excel',
                label: 'Download RFQ Excel',
            ),
            SendDocumentByEmailAction::make(
                documentType: 'rfq_pdf',
                settingsKey: 'email_default_message_rfq',
                label: 'Send RFQ PDF',
            ),
            SendDocumentByEmailAction::make(
                documentType: 'rfq_excel',
                settingsKey: 'email_default_message_rfq',
                label: 'Send RFQ Excel',
                icon: 'heroicon-o-envelope',
            ),
        ])
            ->label(__('forms.labels.documents'))
            ->icon('heroicon-o-document-text')
            ->color('info')
            ->button();
    }

    protected function workflowActionGroup(): ?ActionGroup
    {
        return ActionGroup::make([
            $this->createClientQuotationAction(),
        ])
            ->label(__('forms.labels.workflow'))
            ->icon('heroicon-o-arrows-right-left')
            ->color('primary')
            ->button();
    }

    protected function createClientQuotationAction(): Action
    {
        return Action::make('createClientQuotation')
            ->label(__('forms.labels.create_client_quotation'))
            ->icon('heroicon-o-document-plus')
            ->color('success')
            ->modalHeading(__('forms.labels.create_client_quotation'))
            ->modalDescription(__('forms.helpers.create_client_quotation_help'))
            ->visible(fn () => CreateQuotationFromSupplierQuotationAction::canBeSource($this->record)
                && $this->record->items()->where('unit_cost', '>', 0)->exists())
            ->fillForm(function () {
                $inquiry = $this->record->inquiry;

                return [
                    'company_id' => $inquiry?->company_id,
                    'contact_id' => $inquiry?->contact_id,
                    'commission_type' => CommissionType::EMBEDDED->value,
                    'commission_rate' => 10,
                    'currency_code' => $inquiry?->currency_code ?? $this->record->currency_code,
                    // O incoterm da SQ é texto livre ("FOB Shanghai Port" existe em
                    // produção); só pré-preenche quando casa com o enum do Select.
                    'incoterm' => Incoterm::tryFrom((string) ($this->record->incoterm ?? ''))?->value,
                    'payment_term_id' => $this->record->payment_term_id,
                    'validity_days' => 30,
                    'show_suppliers' => false,
                    'force_new_version' => false,
                ];
            })
            ->form([
                Select::make('company_id')
                    ->label(__('forms.labels.client'))
                    ->options(fn () => Company::query()
                        ->withRole(CompanyRole::CLIENT)
                        ->orderBy('name')
                        ->pluck('name', 'id'))
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('contact_id', null)),
                Select::make('contact_id')
                    ->label(__('forms.labels.contact'))
                    ->options(fn (Get $get) => $get('company_id')
                        ? Contact::query()
                            ->where('company_id', $get('company_id'))
                            ->orderBy('name')
                            ->pluck('name', 'id')
                        : [])
                    ->searchable()
                    ->placeholder(__('forms.placeholders.select_contact')),
                Select::make('commission_type')
                    ->label(__('forms.labels.commission_type'))
                    ->options(CommissionType::class)
                    ->required(),
                TextInput::make('commission_rate')
                    ->label(__('forms.labels.commission_rate'))
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->step(0.01)
                    ->suffix('%')
                    ->required(),
                Select::make('currency_code')
                    ->label(__('forms.labels.currency'))
                    ->options(fn () => Currency::query()->where('is_active', true)->pluck('code', 'code'))
                    ->required(),
                Select::make('incoterm')
                    ->label(__('forms.labels.incoterm'))
                    ->options(Incoterm::class)
                    ->placeholder(__('forms.placeholders.select_incoterm')),
                Select::make('payment_term_id')
                    ->label(__('forms.labels.payment_terms'))
                    ->options(fn () => PaymentTerm::query()->where('is_active', true)->pluck('name', 'id'))
                    ->searchable()
                    ->placeholder(__('forms.placeholders.select_payment_term')),
                TextInput::make('validity_days')
                    ->label(__('forms.labels.validity_days'))
                    ->numeric()
                    ->minValue(1)
                    ->required(),
                Toggle::make('show_suppliers')
                    ->label(__('forms.labels.show_suppliers_to_client'))
                    ->helperText(__('forms.helpers.show_suppliers_or_anonymize_help')),
                Toggle::make('force_new_version')
                    ->label(__('forms.labels.force_new_version'))
                    ->helperText(__('forms.helpers.force_new_version_help'))
                    ->visible(fn (Get $get) => $this->clientQuotationIsLocked(
                        $get('company_id') ? (int) $get('company_id') : null
                    )),
            ])
            ->action(function (array $data, Action $action) {
                try {
                    $commissionType = $data['commission_type'] instanceof CommissionType
                        ? $data['commission_type']
                        : CommissionType::from($data['commission_type']);

                    $incoterm = $data['incoterm'] ?? null;
                    if ($incoterm !== null && ! $incoterm instanceof Incoterm) {
                        $incoterm = Incoterm::tryFrom((string) $incoterm);
                    }

                    $quotation = app(CreateQuotationFromSupplierQuotationAction::class)->execute(
                        sq: $this->record,
                        companyId: (int) $data['company_id'],
                        contactId: $data['contact_id'] ?? null,
                        currencyCode: $data['currency_code'],
                        commissionType: $commissionType,
                        commissionRate: (float) ($data['commission_rate'] ?? 0),
                        showSuppliers: (bool) ($data['show_suppliers'] ?? false),
                        incoterm: $incoterm,
                        paymentTermId: $data['payment_term_id'] ?? null,
                        validityDays: $data['validity_days'] ?? null,
                        forceNewVersion: (bool) ($data['force_new_version'] ?? false),
                    );

                    Notification::make()
                        ->title(__('messages.client_quotation_created_from_sq').': '.$quotation->reference)
                        ->success()
                        ->send();

                    return redirect(QuotationResource::getUrl('edit', ['record' => $quotation]));
                } catch (QuotationLockedException $e) {
                    // getMessage() é redigida para logs (em inglês, citando o parâmetro
                    // forceNewVersion) — nunca mostrada ao trader. O corpo do toast usa a
                    // mensagem traduzida, que nomeia a saída real: marcar o toggle.
                    Notification::make()
                        ->title(__('messages.quotation_locked'))
                        ->body(__('messages.quotation_locked_needs_new_version', [
                            'reference' => $e->quotation->reference,
                        ]))
                        ->danger()
                        ->send();

                    $action->halt();
                } catch (\Throwable $e) {
                    // A mensagem real (SQLSTATE, stack trace, etc.) é só para o log —
                    // nunca aparece no toast, que exibiria texto de infraestrutura para
                    // quem não é dev. O mesmo tratamento que o catch (QuotationLockedException)
                    // já recebeu logo acima.
                    Log::error('Falha ao criar cotação do cliente a partir da SQ '.$this->record->reference.': '.$e->getMessage());

                    Notification::make()
                        ->title(__('messages.error_creating_quotation'))
                        ->body(__('messages.error_creating_quotation_body'))
                        ->danger()
                        ->send();

                    $action->halt();
                }
            });
    }

    /**
     * A Quotation já existente para o cliente $companyId está travada (qualquer
     * status além de DRAFT)? Se sim, o toggle "nova versão" aparece — do contrário
     * CreateQuotationFromSupplierQuotationAction lançaria QuotationLockedException
     * ao tentar sobrescrever uma versão enviada/negociada.
     *
     * $companyId vem do campo `company_id` do MODAL, não necessariamente do cliente
     * da inquiry original desta SQ — a mesma SQ pode ter gerado, em rodadas
     * anteriores, inquiries diferentes para clientes diferentes (uma por cliente).
     * A resolução usa SyncInquiryFromSupplierQuotationAction::findExistingInquiry()
     * — o mesmo método que resolveInquiry() consulta lá — em vez de reproduzir a
     * lógica aqui: UI e domínio não podem divergir sobre qual inquiry está em jogo
     * (a mesma lição de CreateQuotationFromSupplierQuotationAction::canBeSource()).
     */
    private function clientQuotationIsLocked(?int $companyId): bool
    {
        $inquiry = $companyId === null
            ? $this->record->inquiry
            : SyncInquiryFromSupplierQuotationAction::findExistingInquiry($this->record, $companyId);

        if (! $inquiry) {
            return false;
        }

        $latest = $inquiry->quotations()->latest('version')->first();

        return $latest !== null && $latest->status !== QuotationStatus::DRAFT;
    }

    protected function statusActionGroup(): ?ActionGroup
    {
        return ActionGroup::make([
            $this->transitionStatusAction(),
        ])
            ->label(__('forms.labels.status'))
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->button()
            ->visible(fn () => ! empty($this->record->getAllowedNextStatuses()));
    }

    protected function transitionStatusAction(): Action
    {
        return Action::make('transitionStatus')
            ->label(__('forms.labels.change_status'))
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->visible(fn () => ! empty($this->record->getAllowedNextStatuses()))
            ->form(function () {
                $allowed = $this->record->getAllowedNextStatuses();
                $options = collect($allowed)->mapWithKeys(function ($status) {
                    $enum = SupplierQuotationStatus::from($status);

                    return [$status => $enum->getLabel()];
                })->toArray();

                return [
                    Select::make('new_status')
                        ->label(__('forms.labels.new_status'))
                        ->options($options)
                        ->required(),
                    Textarea::make('notes')
                        ->label(__('forms.labels.transition_notes'))
                        ->rows(2)
                        ->maxLength(1000),
                ];
            })
            ->action(function (array $data) {
                try {
                    app(TransitionStatusAction::class)->execute(
                        $this->record,
                        SupplierQuotationStatus::from($data['new_status']),
                        $data['notes'] ?? null,
                    );

                    Notification::make()
                        ->title(__('messages.status_changed_to').' '.SupplierQuotationStatus::from($data['new_status'])->getLabel())
                        ->success()
                        ->send();

                    $this->refreshFormData(['status']);
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title(__('messages.status_transition_failed'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
