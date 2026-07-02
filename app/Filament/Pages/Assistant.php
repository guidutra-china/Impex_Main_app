<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\AI\ClaudeAssistant;
use App\Domain\Inquiries\Enums\InquiryStatus;
use App\Domain\Inquiries\Models\Inquiry;
use App\Filament\Pages\Concerns\HandlesDocumentImport;
use BackedEnum;
use Filament\Pages\Page;
use Livewire\WithFileUploads;

class Assistant extends Page
{
    use HandlesDocumentImport;
    use WithFileUploads;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-sparkles';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.assistant';

    /**
     * Display transcript (text-only, fully serializable by Livewire).
     *
     * @var list<array{role:string,text:string}>
     */
    public array $messages = [];

    public string $draft = '';

    public function mount(): void
    {
        // Entry point from an Inquiry page: lock the import to that inquiry.
        if (request()->query('import') !== 'inquiry') {
            return;
        }

        $inquiry = Inquiry::find((int) request()->query('inquiry_id'));

        if ($inquiry === null
            || ! (auth()->user()?->can('edit-inquiries') ?? false)
            || ! in_array($inquiry->status, [InquiryStatus::RECEIVED, InquiryStatus::QUOTING], true)) {
            return; // invalid/unauthorized param → normal assistant flow
        }

        $this->importTargetKey = 'inquiry';
        $this->importLockedInquiryId = $inquiry->id;
        $this->messages[] = ['role' => 'assistant', 'text' => __('assistant.import_locked_inquiry', ['reference' => $inquiry->reference])];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-assistant') ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->can('view-assistant') ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.pages.assistant');
    }

    public function getTitle(): string
    {
        return __('navigation.pages.assistant');
    }

    public function send(): void
    {
        $text = trim($this->draft);

        if ($text === '') {
            return;
        }

        $this->messages[] = ['role' => 'user', 'text' => $text];
        $this->draft = '';

        // While an import preview is on screen, the chat edits the extracted draft
        // instead of running the read-only assistant.
        if ($this->importPreview !== null) {
            $this->editImport($text);

            return;
        }

        // Build the API conversation from the text transcript. Cross-turn history is
        // text-only; each turn's internal tool round-trips happen inside ask().
        $apiMessages = array_map(
            fn (array $message) => ['role' => $message['role'], 'content' => $message['text']],
            $this->messages,
        );

        try {
            $reply = app(ClaudeAssistant::class)->ask($apiMessages, auth()->user());
        } catch (\Throwable $e) {
            report($e);
            $reply = __('assistant.chat_error');
        }

        $this->messages[] = ['role' => 'assistant', 'text' => $reply];

        $this->dispatch('assistant-updated');
    }

    public function clearConversation(): void
    {
        $this->messages = [];
    }
}
