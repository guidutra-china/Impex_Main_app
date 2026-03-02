<?php

namespace App\Filament\Actions;

use App\Domain\Infrastructure\Models\StateTransition;
use App\Domain\Inquiries\Enums\InquiryStatus;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

class RevertInquiryStatusAction
{
    public static function make(): Action
    {
        return Action::make('revertStatus')
            ->label(__('forms.labels.revert_status'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->visible(fn (Inquiry $record) => self::canRevert($record))
            ->form(fn (Inquiry $record) => self::buildForm($record))
            ->requiresConfirmation()
            ->modalHeading(__('forms.labels.revert_status'))
            ->modalDescription(__('messages.revert_status_warning'))
            ->modalIcon('heroicon-o-exclamation-triangle')
            ->modalIconColor('danger')
            ->action(fn (Inquiry $record, array $data) => self::execute($record, $data));
    }

    protected static function canRevert(Inquiry $record): bool
    {
        if (! auth()->user()?->is_admin) {
            return false;
        }

        return in_array($record->status, [
            InquiryStatus::WON,
            InquiryStatus::CANCELLED,
        ]);
    }

    protected static function getRevertOptions(Inquiry $record): array
    {
        return match ($record->status) {
            InquiryStatus::WON => [
                InquiryStatus::QUOTED->value => InquiryStatus::QUOTED->getLabel(),
                InquiryStatus::QUOTING->value => InquiryStatus::QUOTING->getLabel(),
            ],
            InquiryStatus::CANCELLED => self::getCancelledRevertOptions($record),
            default => [],
        };
    }

    protected static function getCancelledRevertOptions(Inquiry $record): array
    {
        $lastTransition = StateTransition::where('model_type', $record->getMorphClass())
            ->where('model_id', $record->getKey())
            ->where('to_status', InquiryStatus::CANCELLED->value)
            ->orderByDesc('created_at')
            ->first();

        $previousStatus = $lastTransition?->from_status;

        $options = [];

        if ($previousStatus && $previousStatus !== InquiryStatus::CANCELLED->value) {
            $enum = InquiryStatus::from($previousStatus);
            $options[$previousStatus] = $enum->getLabel() . ' (' . __('messages.previous_status') . ')';
        }

        $options[InquiryStatus::RECEIVED->value] = InquiryStatus::RECEIVED->getLabel();

        return $options;
    }

    protected static function getChildDocumentsSummary(Inquiry $record): array
    {
        $summary = [];

        $sqCount = $record->supplierQuotations()->count();
        if ($sqCount > 0) {
            $summary[] = "{$sqCount} " . __('navigation.resources.supplier_quotations');
        }

        $qtCount = $record->quotations()->count();
        if ($qtCount > 0) {
            $summary[] = "{$qtCount} " . __('navigation.resources.quotations');
        }

        $piCount = ProformaInvoice::where('inquiry_id', $record->id)->count();
        if ($piCount > 0) {
            $summary[] = "{$piCount} " . __('navigation.resources.proforma_invoices');
        }

        return $summary;
    }

    protected static function buildForm(Inquiry $record): array
    {
        $fields = [];

        $childDocs = self::getChildDocumentsSummary($record);
        if (! empty($childDocs)) {
            $warningHtml = '<div class="text-sm text-danger-600 dark:text-danger-400 space-y-1">'
                . '<p class="font-semibold">' . __('messages.revert_child_documents_warning') . '</p>'
                . '<ul class="list-disc pl-4">';
            foreach ($childDocs as $doc) {
                $warningHtml .= "<li>{$doc}</li>";
            }
            $warningHtml .= '</ul></div>';

            $fields[] = Placeholder::make('child_documents_warning')
                ->label('')
                ->content(new HtmlString($warningHtml));
        }

        $fields[] = Select::make('target_status')
            ->label(__('forms.labels.revert_to'))
            ->options(self::getRevertOptions($record))
            ->required();

        $fields[] = Textarea::make('reason')
            ->label(__('forms.labels.revert_reason'))
            ->required()
            ->rows(3)
            ->maxLength(1000)
            ->helperText(__('forms.helpers.revert_reason_required'));

        return $fields;
    }

    protected static function execute(Inquiry $record, array $data): void
    {
        try {
            DB::transaction(function () use ($record, $data) {
                $fromStatus = $record->getCurrentStatus();
                $toStatus = $data['target_status'];

                $record->{$record->getStatusColumn()} = InquiryStatus::from($toStatus);
                $record->save();

                StateTransition::create([
                    'model_type' => $record->getMorphClass(),
                    'model_id' => $record->getKey(),
                    'from_status' => $fromStatus,
                    'to_status' => $toStatus,
                    'notes' => '[REVERT] ' . $data['reason'],
                    'metadata' => [
                        'type' => 'revert',
                        'reverted_by' => auth()->user()->name,
                    ],
                    'user_id' => auth()->id(),
                    'created_at' => now(),
                ]);
            });

            $newLabel = InquiryStatus::from($data['target_status'])->getLabel();

            Notification::make()
                ->title(__('messages.status_reverted_to') . ' ' . $newLabel)
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title(__('messages.revert_status_failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}
