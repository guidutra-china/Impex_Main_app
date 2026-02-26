<?php

namespace App\Filament\Actions;

use App\Domain\CRM\Models\Contact;
use App\Mail\DocumentMail;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
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
                && auth()->user()?->can('send-documents-by-email'))
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
                    $toEmail = $data['to'];

                    $mail = Mail::to($toEmail);

                    $ccAddresses = collect($data['cc'] ?? [])
                        ->map(fn ($email) => trim($email))
                        ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
                        ->unique()
                        ->values()
                        ->all();

                    if (! empty($ccAddresses)) {
                        $mail->cc($ccAddresses);
                    }

                    $mail->send(new DocumentMail(
                        document: $document,
                        recipientName: $data['recipient_name'],
                        customMessage: $data['message'] ?? '',
                    ));

                    $allRecipients = collect([$toEmail])->merge($ccAddresses)->join(', ');

                    Notification::make()
                        ->title('Email Sent')
                        ->body("Document sent to {$allRecipients}")
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
        $contact = $record->contact ?? null;
        $company = static::resolveCompany($record);
        $emailOptions = static::buildEmailOptions($company, $contact);
        $emailSuggestions = static::buildEmailSuggestions($company, $contact);
        $defaultTo = static::resolveDefaultTo($contact, $emailOptions);

        return [
            Select::make('to')
                ->label('To')
                ->options($emailOptions)
                ->default($defaultTo)
                ->searchable()
                ->allowHtml()
                ->required()
                ->createOptionForm([
                    TextInput::make('email')
                        ->label('Email Address')
                        ->email()
                        ->required(),
                ])
                ->createOptionUsing(fn (array $data) => $data['email'])
                ->helperText('Select a registered contact or add a new email'),

            TextInput::make('recipient_name')
                ->label('Recipient Name')
                ->required()
                ->default($contact?->name ?? $company?->name),

            TagsInput::make('cc')
                ->label('CC')
                ->suggestions($emailSuggestions)
                ->splitKeys(['Tab', ','])
                ->placeholder('Select or type email addresses')
                ->helperText('Pick from suggestions or type a new email and press Tab/Enter'),

            Textarea::make('message')
                ->label('Message (optional)')
                ->placeholder('Add a custom message to the email...')
                ->rows(4),
        ];
    }

    private static function buildEmailOptions($company, $contact): array
    {
        $options = [];

        if ($company) {
            $contacts = $company->contacts()
                ->whereNotNull('email')
                ->where('email', '!=', '')
                ->orderByDesc('is_primary')
                ->orderBy('name')
                ->get();

            foreach ($contacts as $c) {
                $label = $c->name;
                if ($c->position) {
                    $label .= " ({$c->position})";
                }
                if ($c->is_primary) {
                    $label .= ' ★';
                }
                $options[$c->email] = "{$label} — {$c->email}";
            }

            if ($company->email && ! isset($options[$company->email])) {
                $options[$company->email] = "{$company->name} (Company) — {$company->email}";
            }
        }

        if ($contact?->email && ! isset($options[$contact->email])) {
            $options[$contact->email] = "{$contact->name} — {$contact->email}";
        }

        return $options;
    }

    private static function buildEmailSuggestions($company, $contact): array
    {
        $suggestions = [];

        if ($company) {
            $contacts = $company->contacts()
                ->whereNotNull('email')
                ->where('email', '!=', '')
                ->get();

            foreach ($contacts as $c) {
                $suggestions[] = $c->email;
            }

            if ($company->email) {
                $suggestions[] = $company->email;
            }
        }

        if ($contact?->email) {
            $suggestions[] = $contact->email;
        }

        return array_unique($suggestions);
    }

    private static function resolveDefaultTo($contact, array $emailOptions): ?string
    {
        if ($contact?->email && isset($emailOptions[$contact->email])) {
            return $contact->email;
        }

        if (! empty($emailOptions)) {
            return array_key_first($emailOptions);
        }

        return null;
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
