<?php

declare(strict_types=1);

namespace App\Filament\Pages\Concerns;

use Anthropic\Core\Exceptions\AnthropicException;
use App\Domain\AI\Import\DocumentExtractor;
use App\Domain\AI\Import\EditSupplierQuotationDraft;
use App\Domain\AI\Import\Exceptions\ExtractionFailedException;
use App\Domain\AI\Import\Exceptions\UnsupportedDocumentException;
use App\Domain\AI\Import\ResolveSupplierQuotationDraft;
use App\Domain\AI\Import\SupplierQuotationExtractor;
use App\Domain\Catalog\Models\Category;
use App\Domain\SupplierQuotations\Actions\ImportSupplierQuotationAction;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Locked;

/**
 * Adds supplier-quotation file import to the assistant page: upload → extract →
 * preview → confirm. The write happens only on confirmImport() (button), never via
 * the chat loop.
 */
trait HandlesSupplierQuotationImport
{
    /** Temporary uploaded file (Livewire). */
    public $upload = null;

    /** Resolved preview model (serializable) shown in the confirm card. */
    #[Locked]
    public ?array $importPreview = null;

    /** Raw extracted draft (pre-resolution), kept so the chat can edit it before confirm. */
    #[Locked]
    public ?array $importDraft = null;

    /** Absolute path of the stored source file, kept until confirm/cancel. */
    #[Locked]
    public ?string $importFilePath = null;

    public function submitImport(): void
    {
        if ($this->upload === null) {
            return;
        }

        $this->validate([
            'upload' => 'required|file|mimes:xlsx,xls,pdf|max:20480',
        ]);

        $ext = strtolower($this->upload->getClientOriginalExtension());
        $stored = $this->upload->storeAs('ai-imports', uniqid('sq_').'.'.$ext, 'local');
        $this->importFilePath = Storage::disk('local')->path($stored);

        try {
            $blocks = app(DocumentExtractor::class)->toContentBlocks($this->importFilePath);
            $draft = app(SupplierQuotationExtractor::class)->extract($blocks, $this->importCategoryNames());
            $this->importDraft = $draft;
            $this->importPreview = app(ResolveSupplierQuotationDraft::class)->resolve($draft);

            $this->messages[] = ['role' => 'assistant', 'text' => __('assistant.extracted')];
        } catch (UnsupportedDocumentException|ExtractionFailedException $e) {
            $this->cancelImport();
            $this->messages[] = ['role' => 'assistant', 'text' => __('assistant.process_failed', ['error' => $e->getMessage()])];
        } catch (AnthropicException $e) {
            report($e);
            $this->cancelImport();
            $this->messages[] = ['role' => 'assistant', 'text' => __('assistant.connection_failed')];
        } catch (\Throwable $e) {
            report($e);
            $this->cancelImport();
            $this->messages[] = ['role' => 'assistant', 'text' => __('assistant.process_error')];
        }

        $this->upload = null;
        $this->dispatch('assistant-updated');
    }

    /**
     * Chat-driven editing of the active import draft: the AI adjusts the extracted
     * data per the user's instruction, then the preview is re-resolved. No DB write —
     * only the in-memory draft/preview change (still gated by the Confirm button).
     */
    public function editImport(string $instruction): void
    {
        if ($this->importDraft === null || $this->importPreview === null) {
            return;
        }

        try {
            $result = app(EditSupplierQuotationDraft::class)->edit(
                $this->importDraft,
                $instruction,
                $this->importCategoryNames(),
            );

            $this->importDraft = $result['draft'];
            $this->importPreview = app(ResolveSupplierQuotationDraft::class)->resolve($result['draft']);

            $reply = trim($result['reply']) !== '' ? $result['reply'] : __('assistant.edit_done');
            $this->messages[] = ['role' => 'assistant', 'text' => $reply];
        } catch (AnthropicException $e) {
            report($e);
            $this->messages[] = ['role' => 'assistant', 'text' => __('assistant.connection_failed')];
        } catch (\Throwable $e) {
            report($e);
            $this->messages[] = ['role' => 'assistant', 'text' => __('assistant.process_error')];
        }

        $this->dispatch('assistant-updated');
    }

    /**
     * Active category names the extractor may assign items to (no inventing).
     *
     * @return list<string>
     */
    protected function importCategoryNames(): array
    {
        return Category::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }

    public function confirmImport(): void
    {
        if ($this->importPreview === null || $this->importFilePath === null) {
            return;
        }

        $base = Storage::disk('local')->path('ai-imports');
        $base = realpath($base) ?: $base;
        $real = realpath($this->importFilePath);
        if ($real === false || ! str_starts_with($real, $base)) {
            Notification::make()->title(__('assistant.invalid_file'))->danger()->send();
            $this->cancelImport();
            $this->importPreview = null;

            return;
        }

        try {
            $sq = app(ImportSupplierQuotationAction::class)(
                $this->importPreview,
                auth()->user(),
                $this->importFilePath,
            );

            Notification::make()->title(__('assistant.imported_title', ['reference' => $sq->reference]))->success()->send();
            $this->messages[] = ['role' => 'assistant', 'text' => __('assistant.imported_message', ['reference' => $sq->reference, 'count' => $this->importPreview['resumo']['total_itens']])];
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        } catch (\Throwable $e) {
            report($e);
            Notification::make()->title(__('assistant.import_failed'))->danger()->send();

            return;
        } finally {
            $this->clearImportFile();
        }

        $this->importPreview = null;
        $this->importDraft = null;
        $this->dispatch('assistant-updated');
    }

    public function cancelImport(): void
    {
        $this->clearImportFile();
        $this->importPreview = null;
        $this->importDraft = null;
        $this->upload = null;
    }

    private function clearImportFile(): void
    {
        if ($this->importFilePath !== null && is_file($this->importFilePath)) {
            @unlink($this->importFilePath);
        }
        $this->importFilePath = null;
    }
}
