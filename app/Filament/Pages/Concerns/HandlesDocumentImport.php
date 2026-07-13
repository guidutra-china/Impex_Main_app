<?php

declare(strict_types=1);

namespace App\Filament\Pages\Concerns;

use Anthropic\Core\Exceptions\AnthropicException;
use App\Domain\AI\Import\DocumentClassifier;
use App\Domain\AI\Import\DocumentExtractor;
use App\Domain\AI\Import\DocumentImageExtractor;
use App\Domain\AI\Import\DraftEditor;
use App\Domain\AI\Import\DraftExtractor;
use App\Domain\AI\Import\Exceptions\ExtractionFailedException;
use App\Domain\AI\Import\Exceptions\UnsupportedDocumentException;
use App\Domain\AI\Import\ExpandDraftVariants;
use App\Domain\AI\Import\ImageItemMatcher;
use App\Domain\AI\Import\PhotoItemMatcher;
use App\Domain\AI\Import\Targets\ImportTarget;
use App\Domain\AI\Import\Targets\ImportTargetRegistry;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

/**
 * Universal document import on the assistant page: upload → classify (AI suggests
 * the destination) → user confirms the target → extract → editable review →
 * confirm. The write happens only on confirmImport() (button) through the target's
 * Action, never via the chat loop.
 */
trait HandlesDocumentImport
{
    /** Temporary uploaded file (Livewire). */
    public $upload = null;

    /** Confirmed import target key (null = not chosen yet). */
    #[Locked]
    public ?string $importTargetKey = null;

    /** Classifier suggestion awaiting user confirmation: {tipo, confianca, motivo}. */
    #[Locked]
    public ?array $importSuggestion = null;

    /** When set (entry from an Inquiry page), the import is locked to that inquiry. */
    #[Locked]
    public ?int $importLockedInquiryId = null;

    /** Resolved preview snapshot (read-only, for chat-edit re-resolution). */
    #[Locked]
    public ?array $importPreview = null;

    /** Raw extracted draft (pre-resolution), kept so the chat can edit it. */
    #[Locked]
    public ?array $importDraft = null;

    /** Ordered pool of extracted images: list<array{id:int,path:string,page:int}>. Server-controlled. */
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

        // A leftover file from a failed prior attempt must not leak as an orphan.
        $this->clearImportFile();

        // Each import session gets its own directory so cleanup (and the extracted
        // images/ subdir) never touches another concurrent session's files.
        $ext = strtolower($this->upload->getClientOriginalExtension());
        $stored = $this->upload->storeAs('ai-imports/'.uniqid('imp_'), 'source.'.$ext, 'local');
        $this->importFilePath = Storage::disk('local')->path($stored);

        try {
            $blocks = app(DocumentExtractor::class)->toContentBlocks($this->importFilePath);

            // Entry-point lock (e.g. "?import=inquiry&inquiry_id=N"): skip classification.
            if ($this->importTargetKey !== null) {
                $target = $this->resolveTarget($this->importTargetKey);
                if ($target !== null) {
                    $this->runExtraction($target, $blocks);

                    return;
                }
            }

            $this->importSuggestion = $this->classify($blocks);

            $suggested = $this->resolveTarget($this->importSuggestion['tipo']);
            $reason = trim((string) ($this->importSuggestion['motivo'] ?? ''));
            $text = $suggested !== null
                ? ($reason !== ''
                    ? __('assistant.suggest_target', ['label' => $suggested->label(), 'reason' => $reason])
                    : __('assistant.suggest_target_short', ['label' => $suggested->label()]))
                : __('assistant.suggest_unknown');
            $this->messages[] = ['role' => 'assistant', 'text' => $text];
        } catch (UnsupportedDocumentException|ExtractionFailedException $e) {
            $this->cancelImport();
            $this->messages[] = ['role' => 'assistant', 'text' => __('assistant.process_failed', ['error' => $e->getMessage()])];
        } catch (AnthropicException $e) {
            report($e);
            $this->cancelImport();
            $this->messages[] = ['role' => 'assistant', 'text' => $this->anthropicFailureMessage($e)];
        } catch (\Throwable $e) {
            report($e);
            $this->cancelImport();
            $this->messages[] = ['role' => 'assistant', 'text' => __('assistant.process_error')];
        } finally {
            $this->upload = null;
            $this->dispatch('assistant-updated');
        }
    }

    /** User confirmed (or switched) the destination — run the expensive extraction. */
    public function chooseImportTarget(string $key): void
    {
        if ($this->importFilePath === null) {
            return;
        }

        // Atalho do seletor: extrai como SQ e já deixa o fluxo combinado
        // (Inquiry vinculada) pré-marcado no review.
        $withInquiry = $key === 'supplier_quotation_with_inquiry';
        if ($withInquiry) {
            if ($this->importLockedInquiryId !== null || ! auth()->user()->can('create-inquiries')) {
                return;
            }
            $key = 'supplier_quotation';
        }

        $target = $this->resolveTarget($key);
        if ($target === null) {
            return;
        }

        // Switching targets after an extraction discards the previous draft.
        $this->importDraft = null;
        $this->importPreview = null;
        $this->form = null;
        $this->importImagePool = [];

        try {
            $blocks = app(DocumentExtractor::class)->toContentBlocks($this->importFilePath);
            $this->runExtraction($target, $blocks);

            if ($withInquiry && is_array($this->form) && array_key_exists('criar_inquiry', $this->form)) {
                $this->form['criar_inquiry'] = true;
            }
        } catch (UnsupportedDocumentException|ExtractionFailedException $e) {
            $this->messages[] = ['role' => 'assistant', 'text' => __('assistant.process_failed', ['error' => $e->getMessage()])];
        } catch (AnthropicException $e) {
            report($e);
            $this->messages[] = ['role' => 'assistant', 'text' => $this->anthropicFailureMessage($e)];
        } catch (\Throwable $e) {
            report($e);
            $this->messages[] = ['role' => 'assistant', 'text' => __('assistant.process_error')];
        }

        $this->dispatch('assistant-updated');
    }

    /** Re-open the destination chooser for the current file (discards the extraction). */
    public function reopenTargetChooser(): void
    {
        if ($this->importFilePath === null) {
            return;
        }

        $this->importDraft = null;
        $this->importPreview = null;
        $this->form = null;
        $this->importImagePool = [];
        $this->importTargetKey = null;
        $this->importSuggestion = ['tipo' => 'desconhecido', 'confianca' => 'baixa', 'motivo' => ''];
    }

    /**
     * Best-effort classification; failures fall back to 'desconhecido' and the
     * manual chooser — never block the import.
     *
     * @param  list<object>  $blocks
     * @return array{tipo:string,confianca:string,motivo:string}
     */
    private function classify(array $blocks): array
    {
        $targets = app(ImportTargetRegistry::class)->allFor(auth()->user());

        // No authorized targets: don't pay for a classification the user can't act on.
        if ($targets === []) {
            return ['tipo' => 'desconhecido', 'confianca' => 'baixa', 'motivo' => ''];
        }

        try {
            return app(DocumentClassifier::class)->classify($blocks, $targets);
        } catch (\Throwable $e) {
            report($e);

            return ['tipo' => 'desconhecido', 'confianca' => 'baixa', 'motivo' => ''];
        }
    }

    /** @param  list<object>  $blocks */
    private function runExtraction(ImportTarget $target, array $blocks): void
    {
        // Compute everything into locals first — extract(), resolve() and buildForm()
        // can all throw, and a mid-extraction failure must leave the current state
        // (suggestion/chooser) intact for retry instead of a half-initialized import.
        // Variant sub-rows are expanded immediately: the stored draft is always flat.
        $draft = app(ExpandDraftVariants::class)->expand(app(DraftExtractor::class)->extract($target, $blocks));

        $imagesRaw = $target->supportsImages()
            ? app(DocumentImageExtractor::class)->extract($this->importFilePath)
            : ['by_row' => [], 'ordered' => []];

        [$pool, $itemPhoto] = $this->buildImagePool($draft, $imagesRaw);

        // Reconciliação por visão só para PDFs (no xlsx a âncora de linha é confiável
        // e itens sem foto são legítimos) e só quando o matching ficou incompleto.
        if ($imagesRaw['by_row'] === []) {
            $itemPhoto = $this->reconcilePhotosWithVision($draft, $pool, $itemPhoto);
        }

        $preview = $target->resolve($draft);
        $formArr = $target->buildForm($preview, $itemPhoto);

        if ($this->importLockedInquiryId !== null && array_key_exists('modo', $formArr)) {
            $formArr['modo'] = 'existente';
            $formArr['inquiry_id'] = $this->importLockedInquiryId;
        }

        $this->importTargetKey = $target->key();
        $this->importSuggestion = null;
        $this->importDraft = $draft;
        $this->importImagePool = $pool;
        $this->importPreview = $preview;
        $this->form = $formArr;

        $this->messages[] = ['role' => 'assistant', 'text' => __('assistant.extracted_generic')];
    }

    /** Registry lookup gated by the target's own authorize(). */
    private function resolveTarget(?string $key): ?ImportTarget
    {
        if ($key === null) {
            return null;
        }

        $target = app(ImportTargetRegistry::class)->get($key);

        return ($target !== null && $target->authorize(auth()->user())) ? $target : null;
    }

    /**
     * Chooser options for the blade: every registered target, flagged by whether
     * the current user may use it. Unavailable targets render disabled with the
     * reason instead of silently disappearing (users reported "can't import as
     * inquiry" when the option was just permission-hidden).
     *
     * @return array<string,array{label:string,available:bool}>
     */
    public function importTargetOptions(): array
    {
        $user = auth()->user();

        return array_map(
            fn (ImportTarget $t) => ['label' => $t->label(), 'available' => $t->authorize($user)],
            app(ImportTargetRegistry::class)->all(),
        );
    }

    /** Free-text filter for the "add to existing inquiry" select (reference or client name). */
    public string $inquirySearch = '';

    /**
     * Open inquiries for the "add to existing" select: id => "REF — client".
     * Filtered by the optional search box (reference or client name).
     *
     * @return array<int,string>
     */
    public function openInquiryOptions(): array
    {
        $search = trim($this->inquirySearch);

        return Inquiry::query()
            ->open()
            ->with('company')
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('reference', 'like', "%{$search}%")
                ->orWhereHas('company', fn ($c) => $c->where('name', 'like', "%{$search}%"))))
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Inquiry $i) => [$i->id => $i->reference.' — '.($i->company?->name ?? '')])
            ->all();
    }

    /** Free-text filter for the "create linked inquiry" client select (combined Inquiry+SQ flow). */
    public string $importClientSearch = '';

    /**
     * CLIENT-role companies for the combined Inquiry+SQ flow: id => name. The
     * currently selected client is always included so a narrowed search never
     * silently unsets the choice.
     *
     * @return array<int,string>
     */
    public function importClientOptions(): array
    {
        $search = trim($this->importClientSearch);
        $selectedId = filled($this->form['inquiry_company_id'] ?? null) ? (int) $this->form['inquiry_company_id'] : null;

        return Company::query()
            ->withRole(CompanyRole::CLIENT)
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->when($selectedId !== null, fn ($q) => $q->orWhere('companies.id', $selectedId))))
            ->orderBy('name')
            ->limit(50)
            ->pluck('name', 'id')
            ->all();
    }

    /** Label for the locked inquiry (entry-point flow) — direct lookup, independent of the top-50 search results. */
    public function lockedInquiryLabel(): string
    {
        $inquiry = Inquiry::query()->with('company')->find($this->importLockedInquiryId);

        return $inquiry !== null ? $inquiry->reference.' — '.($inquiry->company?->name ?? '') : '#'.$this->importLockedInquiryId;
    }

    /**
     * Chat-driven editing of the active import draft (in-memory only; still gated
     * by the Confirm button).
     */
    public function editImport(string $instruction): void
    {
        $target = $this->resolveTarget($this->importTargetKey);
        if ($this->form === null || $target === null) {
            return;
        }

        // User selections that live outside the draft survive the rebuild.
        $keepModo = $this->form['modo'] ?? null;
        $keepInquiryId = $this->form['inquiry_id'] ?? null;

        try {
            $result = app(DraftEditor::class)->edit($target, $this->importDraft, $instruction);

            // O editor pode reintroduzir "variantes" — o draft armazenado é sempre plano.
            $result['draft'] = app(ExpandDraftVariants::class)->expand($result['draft']);

            $this->importDraft = $result['draft'];
            $this->importPreview = $target->resolve($result['draft']);
            [, $itemPhoto] = $this->buildImagePool($result['draft'], $this->poolAsImagesRaw());
            $itemPhoto = $this->reconcilePhotosWithVision($result['draft'], $this->importImagePool, $itemPhoto, checkOrientation: false);
            $this->form = $target->buildForm($this->importPreview, $itemPhoto);

            if ($keepModo !== null && array_key_exists('modo', $this->form)) {
                $this->form['modo'] = $keepModo;
                $this->form['inquiry_id'] = $keepInquiryId;
            }

            $reply = trim($result['reply']) !== '' ? $result['reply'] : __('assistant.edit_done');
            $this->messages[] = ['role' => 'assistant', 'text' => $reply];
        } catch (AnthropicException $e) {
            report($e);
            $this->messages[] = ['role' => 'assistant', 'text' => $this->anthropicFailureMessage($e)];
        } catch (\Throwable $e) {
            report($e);
            $this->messages[] = ['role' => 'assistant', 'text' => __('assistant.process_error')];
        }

        $this->dispatch('assistant-updated');
    }

    /**
     * Mensagem amigável para falhas da API Anthropic. Erro de créditos/billing é
     * mostrado como tal — antes caía no genérico "verifique conexão/VPN" e
     * mascarava o diagnóstico real (saldo esgotado na key).
     */
    private function anthropicFailureMessage(AnthropicException $e): string
    {
        return str_contains($e->getMessage(), 'credit balance')
            ? __('assistant.api_billing')
            : __('assistant.connection_failed');
    }

    public function confirmImport(): void
    {
        $target = $this->resolveTarget($this->importTargetKey);
        if ($this->form === null || $this->importFilePath === null || $target === null) {
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
            ['preview' => $preview, 'images' => $images] = $target->formToConfirmPayload($this->form, $this->importImagePool);
            $result = $target->confirm($preview, auth()->user(), $this->importFilePath, $images, [
                // Entrada via inquiry: o destino escolhido deve nascer vinculado a ela
                // (o target de inquiry usa o form; o de SQ grava o inquiry_id).
                'inquiry_id' => $this->importLockedInquiryId,
            ]);

            Notification::make()->title(__('assistant.imported_title', ['reference' => $result['reference']]))->success()->send();
            $this->messages[] = ['role' => 'assistant', 'text' => __('assistant.imported_generic', ['reference' => $result['reference'], 'count' => $result['count']])];
        } catch (\Illuminate\Auth\Access\AuthorizationException|\InvalidArgumentException|\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            $message = $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException
                ? __('assistant.record_not_found')
                : $e->getMessage();
            Notification::make()->title($message)->danger()->send();

            return; // keep the review open so the user can fix and retry
        } catch (\Throwable $e) {
            report($e);
            Notification::make()->title(__('assistant.import_failed'))->danger()->send();

            return;
        }

        $this->clearImportFile();
        $this->form = null;
        $this->importPreview = null;
        $this->importDraft = null;
        $this->importSuggestion = null;
        // O lock da inquiry sobrevive (próximo upload continua vinculado a ela),
        // mas o destino volta a ser escolhido por documento.
        $this->importTargetKey = null;
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
        $this->importSuggestion = null;
        // O lock da inquiry sobrevive ao cancel; o destino é re-escolhido por documento.
        $this->importTargetKey = null;
    }

    /**
     * Flatten extracted images into an ordered pool and compute the initial
     * per-item photo (auto-match): xlsx by anchor row, pdf page-aware.
     *
     * @param  array<string,mixed>  $draft
     * @param  array{by_row:array<int,string>,ordered:list<array{path:string,page:int,width:int,height:int}>}  $images
     * @return array{0:list<array{id:int,path:string,page:int}>,1:array<int,?int>}
     */
    private function buildImagePool(array $draft, array $images): array
    {
        return app(ImageItemMatcher::class)->match($draft, $images);
    }

    /**
     * Passe de visão sobre as fotos extraídas do PDF. Sempre corrige orientação
     * (imagens espelhadas pelo pdfimages); o mapeamento foto↔item da visão só
     * substitui o determinístico quando o alinhamento ficou suspeito (item sem
     * foto ou imagem sobrando). Falha mantém o resultado atual.
     *
     * @param  array<string,mixed>  $draft
     * @param  list<array{id:int,path:string,page:int}>  $pool
     * @param  array<int,?int>  $itemPhoto
     * @return array<int,?int>
     */
    private function reconcilePhotosWithVision(array $draft, array $pool, array $itemPhoto, bool $checkOrientation = true): array
    {
        if ($pool === []) {
            return $itemPhoto;
        }

        $matcher = app(PhotoItemMatcher::class);
        $applyMappings = $matcher->shouldRun($pool, $itemPhoto);

        // Sem suspeita de desalinhamento e sem necessidade de checar orientação
        // (ex.: re-match após edição via chat — as fotos já foram corrigidas na
        // extração), não há o que a visão melhorar: pula a chamada.
        if (! $applyMappings && ! $checkOrientation) {
            return $itemPhoto;
        }

        // Contexto de layout: sem ver a página, o modelo não sabe qual foto está em
        // qual linha da tabela (a ordem de desenho no PDF não segue as linhas) nem
        // qual a orientação correta de cada foto.
        $pageRenders = str_ends_with(strtolower((string) $this->importFilePath), '.pdf')
            ? app(DocumentImageExtractor::class)->renderPages($this->importFilePath)
            : [];

        return $matcher->reconcile(
            $pool,
            array_values($draft['itens'] ?? []),
            $itemPhoto,
            applyMappings: $applyMappings,
            pageRenders: $pageRenders,
            checkOrientation: $checkOrientation,
        );
    }

    /** Inverte verticalmente uma imagem do pool (correção manual de foto espelhada). */
    public function flipPoolImage(int $poolId): void
    {
        $entry = $this->importImagePool[$poolId] ?? null;
        if ($entry !== null) {
            PhotoItemMatcher::flipVertical($entry['path']);
        }
    }

    /** Re-expose the current pool as a DocumentImageExtractor-shaped result for re-matching after a chat edit. */
    private function poolAsImagesRaw(): array
    {
        return ['by_row' => [], 'ordered' => array_map(fn ($e) => [
            'path' => $e['path'],
            'page' => (int) ($e['page'] ?? 0),
            'width' => 0,
            'height' => 0,
        ], $this->importImagePool)];
    }

    /**
     * Small data-URI thumbnail for a pool image (server-read, downscaled to keep the
     * rendered HTML tiny — the full-size images would blow up memory when inlined).
     */
    public function importImageThumb(int $id): ?string
    {
        $entry = $this->importImagePool[$id] ?? null;
        if ($entry === null || ! is_file($entry['path'])) {
            return null;
        }

        $data = @file_get_contents($entry['path']);
        if ($data === false || $data === '') {
            return null;
        }

        // Downscale to a small thumbnail with GD so the inline base64 stays a few KB.
        if (function_exists('imagecreatefromstring')) {
            $src = @imagecreatefromstring($data);
            if ($src !== false) {
                $max = 96;
                $w = imagesx($src);
                $h = imagesy($src);
                $scale = min(1, $max / max(1, $w, $h));
                $thumb = imagescale($src, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
                ob_start();
                imagepng($thumb !== false ? $thumb : $src);
                $png = (string) ob_get_clean();
                imagedestroy($src);
                if ($thumb !== false) {
                    imagedestroy($thumb);
                }

                return 'data:image/png;base64,'.base64_encode($png);
            }
        }

        // Fallback: inline the original bytes.
        $mime = match (strtolower(pathinfo($entry['path'], PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/png',
        };

        return 'data:'.$mime.';base64,'.base64_encode($data);
    }

    /** Foto enviada manualmente no review (Livewire temporary upload). */
    public $itemPhotoUpload = null;

    /** Item de destino do upload manual — setado pelo blade antes de abrir o seletor de arquivo. */
    public ?int $photoUploadTarget = null;

    /**
     * Upload manual de foto no review: cobre documentos cujo PDF não traz a foto
     * extraível (ou traz errada). A imagem entra no pool compartilhado (na pasta
     * images/ da sessão, limpa junto com o import) e é atribuída ao item alvo.
     */
    public function updatedItemPhotoUpload(): void
    {
        if ($this->itemPhotoUpload === null || $this->importFilePath === null || $this->form === null) {
            return;
        }

        try {
            $this->validate(['itemPhotoUpload' => 'image|mimes:jpg,jpeg,png,gif,webp|max:10240']);

            $dir = dirname($this->importFilePath).'/images';
            @mkdir($dir, 0775, true);
            $ext = strtolower($this->itemPhotoUpload->getClientOriginalExtension() ?: 'png');
            $path = $dir.'/upload_'.Str::uuid()->toString().'.'.$ext;
            copy($this->itemPhotoUpload->getRealPath(), $path);

            $id = count($this->importImagePool);
            $this->importImagePool[] = ['id' => $id, 'path' => $path, 'page' => 0];

            if ($this->photoUploadTarget !== null && isset($this->form['itens'][$this->photoUploadTarget])) {
                $this->form['itens'][$this->photoUploadTarget]['photo_index'] = $id;
            }

            $this->dispatch('photo-uploaded');
        } finally {
            $this->itemPhotoUpload = null;
            $this->photoUploadTarget = null;
        }
    }

    /**
     * Desvincula o produto casado de um item do review: o item volta a "novo" e o
     * confirm criará um produto draft com a descrição (nome) e o part_no (model
     * number) editados — em vez de manter o vínculo com o produto errado.
     */
    public function unlinkItemProduct(int $itemIndex): void
    {
        if (! isset($this->form['itens'][$itemIndex])) {
            return;
        }

        $this->form['itens'][$itemIndex]['product_id'] = null;
        $this->form['itens'][$itemIndex]['product_name'] = null;
        $this->form['itens'][$itemIndex]['status'] = 'novo';
    }

    /** Busca do painel "vincular a produto existente" no review. */
    public string $importProductSearch = '';

    /**
     * Candidatos para vínculo manual: nome, SKU, modelo ou reference code.
     * Documentos sem coluna de código (com descrições inferidas por foto, que
     * variam a cada extração) não têm como casar automaticamente com produtos já
     * importados — o vínculo manual é o caminho anti-duplicata.
     *
     * @return array<int,string>
     */
    public function importProductOptions(): array
    {
        $search = trim($this->importProductSearch);
        if (mb_strlen($search) < 2) {
            return [];
        }

        // Limite 50: buscas genéricas ("dumbbell") casam com dezenas de variantes de
        // peso e o corte em 20 escondia produtos existentes (caso real DBE-00009 —
        // "8kg"/"9kg" ficavam fora do top-20 alfabético). Ordenação por tamanho do
        // nome aproxima ordem natural: "1kg" antes de "10kg".
        return Product::query()
            ->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('sku', 'like', "%{$search}%")
                ->orWhere('model_number', 'like', "%{$search}%")
                ->orWhere('reference_code', 'like', "%{$search}%"))
            ->orderByRaw('LENGTH(name), name')
            ->limit(50)
            ->get(['id', 'name', 'sku'])
            ->mapWithKeys(fn (Product $p) => [$p->id => $p->name.(filled($p->sku) ? " ({$p->sku})" : '')])
            ->all();
    }

    /** Vincula um item do review a um produto existente do catálogo. */
    public function linkItemProduct(int $itemIndex, int $productId): void
    {
        if (! isset($this->form['itens'][$itemIndex])) {
            return;
        }

        $product = Product::find($productId);
        if ($product === null) {
            return;
        }

        $this->form['itens'][$itemIndex]['product_id'] = $product->id;
        $this->form['itens'][$itemIndex]['product_name'] = $product->name;
        $this->form['itens'][$itemIndex]['status'] = 'existente';
        $this->dispatch('product-linked');
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
     * Active categories as id => name for the review select.
     *
     * @return array<int,string>
     */
    public function importCategoryOptions(): array
    {
        return Category::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();
    }

    private function clearImportFile(): void
    {
        if ($this->importFilePath !== null) {
            $sessionDir = dirname($this->importFilePath);
            $imagesDir = $sessionDir.'/images';
            if (is_dir($imagesDir)) {
                array_map('unlink', glob($imagesDir.'/*') ?: []);
                @rmdir($imagesDir);
            }
            if (is_file($this->importFilePath)) {
                @unlink($this->importFilePath);
            }
            // Legacy state may still point at a file stored flat in ai-imports/ —
            // never remove the shared base dir itself.
            if (basename($sessionDir) !== 'ai-imports') {
                @rmdir($sessionDir);
            }
        }
        $this->importFilePath = null;
        $this->importImagePool = [];
    }
}
