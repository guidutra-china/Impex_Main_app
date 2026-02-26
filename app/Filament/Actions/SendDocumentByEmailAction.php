<?php

namespace App\Filament\Actions;

use App\Mail\DocumentMail;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Mail;

class SendDocumentByEmailAction
{
    public static function make(
        string $documentType,
        string $label = 'Send by Email',
        string $icon = 'heroicon-o-envelope',
    ): Action {
        return Action::make('sendByEmail')
            ->label($label)
            ->icon($icon)
            ->color('warning')
            ->visible(fn ($record) => $record->getLatestDocument($documentType) !== null
                && auth()->user()?->can('download-documents'))
            ->form(fn ($record): array => static::buildForm($record))
            ->action(function (array $data, $record) use ($documentType) {
                $document = $record->getLatestDocument($documentType);

                if (! $document || ! $document->exists()) {
                    Notification::make()
                        ->title('Document Not Found')
                        ->body('No PDF has been generated yet. Please generate one first.')
                        ->warning()
                        ->send();

                    return;
                }

                try {
                    $mail = Mail::to($data['to']);

                    if (! empty($data['cc'])) {
                        $ccAddresses = array_map('trim', explode(',', $data['cc']));
                        $mail->cc($ccAddresses);
                    }

                    $mail->send(new DocumentMail(
                        document: $document,
                        recipientName: $data['recipient_name'],
                        customMessage: $data['message'] ?? '',
                    ));

                    Notification::make()
                        ->title('Email Sent')
                        ->body("Document sent to {$data['to']}")
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    report($e);

                    Notification::make()
                        ->title('Email Failed')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    private static function buildForm($record): array
    {
        $contact = $record->contact;
        $company = static::resolveCompany($record);

        return [
            TextInput::make('to')
                ->label('To')
                ->email()
                ->required()
                ->default($contact?->email),
            TextInput::make('recipient_name')
                ->label('Recipient Name')
                ->required()
                ->default($contact?->name ?? $company?->name),
            TextInput::make('cc')
                ->label('CC')
                ->placeholder('email1@example.com, email2@example.com')
                ->helperText('Separate multiple emails with commas'),
            Textarea::make('message')
                ->label('Message (optional)')
                ->placeholder('Add a custom message to the email...')
                ->rows(4),
        ];
    }

    private static function resolveCompany($record)
    {
        if (method_exists($record, 'supplierCompany') && $record->supplier_company_id) {
            return $record->supplierCompany;
        }

        if (method_exists($record, 'company') && $record->company_id) {
            return $record->company;
        }

        return null;
    }
}
