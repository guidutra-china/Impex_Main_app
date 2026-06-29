<?php

declare(strict_types=1);

namespace App\Filament\Pages\Concerns;

use Anthropic\Core\Exceptions\AnthropicException;
use App\Domain\AI\Import\DocumentExtractor;
use App\Domain\AI\Import\DocumentImageExtractor;
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

    /** Resolved preview snapshot (read-only, for chat-edit re-resolution). */
    #[Locked]
    public ?array $importPreview = null;

    /** Raw extracted draft (pre-resolution), kept so the chat can edit it. */
    #[Locked]
    public ?array $importDraft = null;

    /** Ordered pool of extracted images: list<array{id:int,path:string}>. Server-controlled. */
    #[Locked]
    public array $importImagePool = [];

    /** Absolute path of the stored source file, kept until confirm/cancel. */
    #[Locked]
    public ?string $importFilePath = null;

    /** Editable review form (NOT locked) — the source of truth for Confirm. */
    public ?array $form = null;

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
            $imagesRaw = app(DocumentImageExtractor::class)->extract($this->importFilePath);

            [$pool, $itemPhoto] = $this->buildImagePool($draft, $imagesRaw);
            $this->importDraft = $draft;
            $this->importImagePool = $pool;
            $this->importPreview = app(ResolveSupplierQuotationDraft::class)->resolve($draft);
            $this->form = $this->buildForm($this->importPreview, $itemPhoto);

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
        if ($this->form === null) {
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
            [, $itemPhoto] = $this->buildImagePool($result['draft'], $this->poolAsImagesRaw());
            $this->form = $this->buildForm($this->importPreview, $itemPhoto);

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
     * Flatten extracted images into an ordered pool and compute the initial
     * per-item photo (auto-match): xlsx by anchor row, pdf by position.
     *
     * @param  array<string,mixed>  $draft
     * @param  array{by_row:array<int,string>,ordered:list<string>}  $images
     * @return array{0:list<array{id:int,path:string}>,1:array<int,?int>}
     */
    private function buildImagePool(array $draft, array $images): array
    {
        $pool = [];
        $itemPhoto = [];
        $itens = array_values($draft['itens'] ?? []);

        if ($images['by_row'] !== []) {
            $byRow = $images['by_row'];
            ksort($byRow);
            $rowToId = [];
            foreach ($byRow as $row => $path) {
                $id = count($pool);
                $pool[] = ['id' => $id, 'path' => $path];
                $rowToId[$row] = $id;
            }
            foreach ($itens as $i => $item) {
                $itemPhoto[$i] = $rowToId[(int) ($item['source_row'] ?? 0)] ?? null;
            }
        } elseif ($images['ordered'] !== []) {
            foreach (array_values($images['ordered']) as $i => $path) {
                $pool[] = ['id' => $i, 'path' => $path];
            }
            foreach ($itens as $i => $item) {
                $itemPhoto[$i] = isset($pool[$i]) ? $i : null;
            }
        }

        return [$pool, $itemPhoto];
    }

    /** Re-expose the current pool as a DocumentImageExtractor-shaped result for re-matching after a chat edit. */
    private function poolAsImagesRaw(): array
    {
        return ['by_row' => [], 'ordered' => array_map(fn ($e) => $e['path'], $this->importImagePool)];
    }

    /**
     * Build the editable form from a resolved preview.
     *
     * @param  array<string,mixed>  $preview
     * @param  array<int,?int>  $itemPhoto  item index => pool id
     * @return array<string,mixed>
     */
    private function buildForm(array $preview, array $itemPhoto): array
    {
        $itens = [];
        foreach (array_values($preview['itens']) as $i => $it) {
            $itens[] = [
                'description' => $it['description'] ?? '',
                'quantity' => $it['quantity'] ?? 0,
                'unit' => $it['unit'] ?? 'pcs',
                'unit_price' => round(((int) ($it['unit_cost_minor'] ?? 0)) / \App\Domain\Infrastructure\Support\Money::SCALE, 2),
                'category_id' => $it['category_id'] ?? null,
                'part_no' => $it['part_no'] ?? null,
                'status' => $it['status'] ?? 'novo',
                'product_id' => $it['product_id'] ?? null,
                'specifications' => $it['specifications'] ?? null,
                'moq' => $it['moq'] ?? null,
                'lead_time_days' => $it['lead_time_days'] ?? null,
                'notes' => $it['notes'] ?? null,
                'photo_index' => $itemPhoto[$i] ?? null,
            ];
        }

        return [
            'fornecedor' => array_merge(
                [
                    'nome' => $preview['fornecedor']['nome'] ?? '',
                    'status' => $preview['fornecedor']['status'] ?? 'novo',
                    'company_id' => $preview['fornecedor']['company_id'] ?? null,
                ],
                $preview['fornecedor_dados'] ?? [],
            ),
            'cabecalho' => $preview['cabecalho'] ?? [],
            'itens' => $itens,
        ];
    }

    /**
     * Convert the edited form back to the Action's preview shape + images map.
     *
     * @return array{preview:array<string,mixed>,images:array<string,string>}
     */
    private function formToActionPreview(): array
    {
        $form = $this->form ?? [];
        $contactKeys = ['legal_name', 'tax_number', 'phone', 'email', 'website', 'address_street', 'address_number', 'address_complement', 'address_city', 'address_state', 'address_zip', 'address_country'];

        $itens = [];
        $images = [];
        foreach (array_values($form['itens'] ?? []) as $i => $it) {
            $partNo = trim((string) ($it['part_no'] ?? ''));
            $key = $partNo !== '' ? $partNo : 'idx:'.$i;

            $itens[] = [
                'status' => $it['status'] ?? 'novo',
                'product_id' => $it['product_id'] ?? null,
                'part_no' => $partNo !== '' ? $partNo : null,
                'description' => (string) ($it['description'] ?? ''),
                'quantity' => (int) ($it['quantity'] ?? 0),
                'unit' => $it['unit'] ?? null,
                'unit_cost_minor' => \App\Domain\Infrastructure\Support\Money::toMinor($it['unit_price'] ?? 0),
                'category_id' => $it['category_id'] ?? null,
                'specifications' => $it['specifications'] ?? null,
                'moq' => $it['moq'] ?? null,
                'lead_time_days' => $it['lead_time_days'] ?? null,
                'notes' => $it['notes'] ?? null,
            ];

            $idx = $it['photo_index'] ?? null;
            if ($idx !== null && isset($this->importImagePool[$idx])) {
                $images[$key] = $this->importImagePool[$idx]['path'];
            }
        }

        $f = $form['fornecedor'] ?? [];

        return [
            'preview' => [
                'fornecedor' => ['status' => $f['status'] ?? 'novo', 'company_id' => $f['company_id'] ?? null, 'nome' => $f['nome'] ?? ''],
                'fornecedor_dados' => array_intersect_key($f, array_flip($contactKeys)),
                'cabecalho' => $form['cabecalho'] ?? [],
                'itens' => $itens,
            ],
            'images' => $images,
        ];
    }

    /** Data-URI thumbnail for a pool image (base64, server-read). Null if missing. */
    public function importImageThumb(int $id): ?string
    {
        $entry = $this->importImagePool[$id] ?? null;
        if ($entry === null || ! is_file($entry['path'])) {
            return null;
        }
        $mime = match (strtolower(pathinfo($entry['path'], PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/png',
        };

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($entry['path']));
    }

    /** Assign (or clear) the chosen pool image for an item. */
    public function setItemPhoto(int $itemIndex, ?int $poolId): void
    {
        if (! isset($this->form['itens'][$itemIndex])) {
            return;
        }
        $this->form['itens'][$itemIndex]['photo_index'] =
            ($poolId !== null && isset($this->importImagePool[$poolId])) ? $poolId : null;
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

    /**
     * Active categories as id => name for the review select.
     *
     * @return array<int,string>
     */
    public function importCategoryOptions(): array
    {
        return Category::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();
    }

    public function confirmImport(): void
    {
        if ($this->form === null || $this->importFilePath === null) {
            return;
        }

        $base = Storage::disk('local')->path('ai-imports');
        $base = realpath($base) ?: $base;
        $real = realpath($this->importFilePath);
        if ($real === false || ! str_starts_with($real, $base)) {
            Notification::make()->title(__('assistant.invalid_file'))->danger()->send();
            $this->cancelImport();

            return;
        }

        try {
            ['preview' => $preview, 'images' => $images] = $this->formToActionPreview();
            $sq = app(ImportSupplierQuotationAction::class)(
                $preview,
                auth()->user(),
                $this->importFilePath,
                $images,
            );

            Notification::make()->title(__('assistant.imported_title', ['reference' => $sq->reference]))->success()->send();
            $this->messages[] = ['role' => 'assistant', 'text' => __('assistant.imported_message', ['reference' => $sq->reference, 'count' => count($preview['itens'])])];
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        } catch (\Throwable $e) {
            report($e);
            Notification::make()->title(__('assistant.import_failed'))->danger()->send();

            return;
        } finally {
            $this->clearImportFile();
        }

        $this->form = null;
        $this->importPreview = null;
        $this->importDraft = null;
        $this->dispatch('assistant-updated');
    }

    public function cancelImport(): void
    {
        $this->clearImportFile();
        $this->importPreview = null;
        $this->importDraft = null;
        $this->importImagePool = [];
        $this->form = null;
        $this->upload = null;
    }

    private function clearImportFile(): void
    {
        if ($this->importFilePath !== null) {
            $imagesDir = dirname($this->importFilePath).'/images';
            if (is_dir($imagesDir)) {
                array_map('unlink', glob($imagesDir.'/*') ?: []);
                @rmdir($imagesDir);
            }
            if (is_file($this->importFilePath)) {
                @unlink($this->importFilePath);
            }
        }
        $this->importFilePath = null;
        $this->importImagePool = [];
    }
}
