<?php

namespace App\Livewire\Logistics;

use App\Domain\Logistics\Actions\AddContentToCartonAction;
use App\Domain\Logistics\Actions\BulkFillAction;
use App\Domain\Logistics\Actions\BulkUpdateCartonsAction;
use App\Domain\Logistics\Actions\CreateCartonAction;
use App\Domain\Logistics\Actions\CreateContainerAction;
use App\Domain\Logistics\Actions\CreatePalletAction;
use App\Domain\Logistics\Actions\DeleteCartonAction;
use App\Domain\Logistics\Actions\DeleteCartonContentAction;
use App\Domain\Logistics\Actions\DeleteContainerAction;
use App\Domain\Logistics\Actions\DeletePalletAction;
use App\Domain\Logistics\Actions\GeneratePackingListV2Action;
use App\Domain\Logistics\Actions\RecalculateShipmentTotalsAction;
use App\Domain\Logistics\Actions\RenumberCartonsAction;
use App\Domain\Logistics\Actions\SplitProductAcrossCartonsAction;
use App\Domain\Logistics\Actions\UpdateCartonAction;
use App\Domain\Logistics\Actions\UpdateContainerAction;
use App\Domain\Logistics\Actions\UpdatePalletAction;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\CartonContent;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Services\PackingProgressService;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Component;

class PackingListBuilder extends Component
{
    /** How many carton cards each expanded subgroup shows per "load more" page. */
    public const SUBGROUP_PAGE = 25;

    public Shipment $shipment;

    // Lazy cargo tree: collapsed subgroups render nothing server-side, so the
    // payload stays small even with thousands of cartons. Keyed by subgroup key.
    /** @var array<string, bool> */
    public array $expandedSubgroups = [];

    /** @var array<string, int> */
    public array $subgroupLimits = [];

    // Containers colapsados (padrão: expandido). Colapsado não renderiza
    // pallets/caixas do container — só o cabeçalho com o resumo.
    /** @var array<int, bool> */
    public array $collapsedContainers = [];

    // Transient "Add content" form (per-carton)
    public ?int $addContentCartonId = null;

    public ?int $addContentItemId = null;

    public mixed $addContentPieces = 0;

    // Transient "Split product" form
    public ?int $splitItemId = null;

    public mixed $splitPartsCount = 2;

    public array $splitPartLabels = ['Part 1', 'Part 2'];

    // Transient per-part placement form (keyed by "itemId::partLabel")
    public array $placeForm = [];

    // Transient "Edit container" form
    public ?int $editContainerId = null;

    public array $editContainerForm = [
        'container_number' => null,
        'type' => null,
        'seal_number' => null,
        'notes' => null,
    ];

    // Transient "Edit pallet" form
    public ?int $editPalletId = null;

    public array $editPalletForm = [
        'shipment_container_id' => null,
        'length' => null,
        'width' => null,
        'height' => null,
        'notes' => null,
    ];

    // Transient "Edit carton" form (box-level: dims + weight + packaging type)
    public ?int $editCartonId = null;

    public array $editCartonForm = [
        'packaging_type' => null,
        'gross_weight' => null,
        'net_weight' => null,
        'length' => null,
        'width' => null,
        'height' => null,
        'volume' => null,
        'notes' => null,
    ];

    // Transient "Bulk edit cartons" form (applies dims/weight to a whole subgroup).
    // $bulkEditKey identifies which subgroup card is in edit mode; a blank field
    // means "leave unchanged" on save.
    public ?string $bulkEditKey = null;

    public array $bulkEditCartonIds = [];

    public array $bulkEditForm = [
        'gross_weight' => null,
        'net_weight' => null,
        'length' => null,
        'width' => null,
        'height' => null,
    ];

    // "Move carton" target (shown when user clicks "Move" on a carton)
    public ?int $moveCartonId = null;

    // "Move pallet" target
    public ?int $movePalletId = null;

    // "Bulk fill" / "Pack product" form state
    // fillTargetType: 'container' | 'pallet' | null
    public ?string $fillTargetType = null;

    public ?int $fillTargetId = null;

    public ?int $fillItemId = null;

    public mixed $fillPieces = 0; // mixed: browser sends "" when input cleared

    // Pcs por caixa fora do padrão para esta operação (em branco = padrão do
    // cadastro do produto). Não altera o ProductPackaging.
    public mixed $fillPcsPerCarton = null;

    public bool $fillFromProduct = false; // true when initiated from product card (pick target)

    // Filtro dos selects de caixas: com 3000+ caixas num embarque, listar todas
    // é inviável — os selects mostram uma lista curta e este termo busca o resto.
    public string $cartonSearch = '';

    public function mount(Shipment $shipment): void
    {
        $this->shipment = $shipment;
    }

    // ---------- Computed properties ----------

    #[Computed]
    public function products(): Collection
    {
        $progress = app(PackingProgressService::class)->forShipment($this->shipment);

        $items = $this->shipment
            ->items()
            ->with('proformaInvoiceItem')
            ->orderBy('sort_order')
            ->get();

        // Load products with trashed — soft-deleted products still have valid packaging.
        $productIds = $items->pluck('proformaInvoiceItem.product_id')->filter()->unique();
        $products = \App\Domain\Catalog\Models\Product::withTrashed()
            ->with([
                'packaging',
                // Pivot do cliente do embarque — fonte do MODEL NO (mesma regra do CI/PL).
                'companies' => fn ($q) => $q
                    ->where('companies.id', $this->shipment->company_id)
                    ->where('company_product.role', 'client'),
            ])
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        return $items->map(function ($item) use ($progress, $products) {
            $product = $products[$item->proformaInvoiceItem?->product_id] ?? null;
            $packaging = $product?->packaging;
            $clientPivot = $product?->companies->first()?->pivot;

            return [
                'item' => $item,
                'progress' => $progress[$item->id] ?? null,
                'pcs_per_carton' => (int) ($packaging?->pcs_per_carton ?? 0),
                'carton_weight' => $packaging?->carton_weight,
                'model_no' => $clientPivot?->external_code ?: ($product?->model_number ?: $product?->sku),
            ];
        });
    }

    #[Computed]
    public function containers(): Collection
    {
        return $this->shipment
            ->shipmentContainers()
            ->with([
                'pallets.cartons.contents.shipmentItem.proformaInvoiceItem',
                'directCartons.contents.shipmentItem.proformaInvoiceItem',
            ])
            ->get();
    }

    #[Computed]
    public function loosePallets(): Collection
    {
        return $this->shipment
            ->shipmentPallets()
            ->whereNull('shipment_container_id')
            ->with('cartons.contents.shipmentItem.proformaInvoiceItem')
            ->get();
    }

    #[Computed]
    public function looseCartons(): Collection
    {
        return $this->shipment
            ->cartons()
            ->whereNull('shipment_container_id')
            ->whereNull('shipment_pallet_id')
            ->with('contents.shipmentItem.proformaInvoiceItem')
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get();
    }

    /**
     * Total de caixas do embarque (contagem barata para o cabeçalho e para a
     * dica "mostrando X de Y" dos selects).
     */
    #[Computed]
    public function cartonCount(): int
    {
        return $this->shipment->cartons()->count();
    }

    /**
     * Totais gerais do embarque (todas as caixas, independente de container/
     * pallet) — agregado em SQL para não hidratar milhares de cartons.
     *
     * @return array{boxes: int, gross: float, net: float, cbm: float}
     */
    #[Computed]
    public function shipmentTotals(): array
    {
        $row = $this->shipment->cartons()
            ->selectRaw('COUNT(*) AS boxes')
            ->selectRaw('COALESCE(SUM(gross_weight), 0) AS gross')
            ->selectRaw('COALESCE(SUM(net_weight), 0) AS net')
            ->selectRaw('COALESCE(SUM(volume), 0) AS cbm')
            ->first();

        return [
            'boxes' => (int) $row->boxes,
            'gross' => (float) $row->gross,
            'net' => (float) $row->net,
            'cbm' => (float) $row->cbm,
        ];
    }

    /**
     * Existing cartons as pack destinations, labelled with their parent and
     * current piece count so the operator can pick a specific box.
     *
     * Nunca lista todas as caixas: sem busca, mostra só as candidatas
     * relevantes (vazias, com o produto selecionado, recém-usadas); com
     * $cartonSearch, busca por label da caixa/container/pallet.
     *
     * @return Collection<int, array{id: int, label: string}>
     */
    #[Computed]
    public function cartonFillOptions(): Collection
    {
        $base = fn () => $this->shipment
            ->cartons()
            ->with(['contents:id,carton_id,pieces', 'shipmentContainer:id,label', 'pallet:id,label']);

        $search = trim($this->cartonSearch);

        if ($search !== '') {
            $cartons = $base()
                ->where(function ($query) use ($search) {
                    $query->where('label', 'like', "%{$search}%")
                        ->orWhereHas('shipmentContainer', fn ($q) => $q->where('label', 'like', "%{$search}%"))
                        ->orWhereHas('pallet', fn ($q) => $q->where('label', 'like', "%{$search}%"));
                })
                ->orderBy('sort_order')
                ->orderBy('label')
                ->limit(20)
                ->get();
        } else {
            $empty = $base()
                ->whereDoesntHave('contents')
                ->orderBy('sort_order')
                ->orderBy('label')
                ->limit(15)
                ->get();

            $withSelectedProduct = $this->fillItemId
                ? $base()
                    ->whereHas('contents', fn ($q) => $q->where('shipment_item_id', $this->fillItemId))
                    ->latest('updated_at')
                    ->limit(10)
                    ->get()
                : collect();

            $recent = $base()->latest('updated_at')->limit(5)->get();

            $cartons = $empty
                ->concat($withSelectedProduct)
                ->concat($recent)
                ->unique('id')
                ->sortBy([['sort_order', 'asc'], ['label', 'asc']])
                ->values();
        }

        // A caixa já escolhida como destino precisa continuar na lista, senão o
        // select nativo deixa de exibi-la como selecionada.
        if ($this->fillTargetType === 'carton' && $this->fillTargetId && ! $cartons->contains('id', $this->fillTargetId)) {
            $cartons = $base()->whereKey($this->fillTargetId)->get()->concat($cartons);
        }

        return $cartons->map(function ($carton) {
            $pieces = (int) $carton->contents->sum('pieces');
            $parent = $carton->pallet?->label ?? $carton->shipmentContainer?->label;

            $context = $parent ? " · {$parent}" : ' · solta';
            $load = $pieces > 0 ? " ({$pieces} pcs)" : ' (vazia)';

            return [
                'id' => $carton->id,
                'label' => $carton->label.$context.$load,
            ];
        });
    }

    // ---------- Container actions ----------

    public function createContainer(): void
    {
        app(CreateContainerAction::class)->execute($this->shipment);
        $this->refreshData();
    }

    public function startEditContainer(int $containerId): void
    {
        $container = $this->shipment->shipmentContainers()->find($containerId);
        if (! $container) {
            return;
        }

        $this->editContainerId = $containerId;
        $this->editContainerForm = [
            'container_number' => $container->container_number,
            'type' => $container->type,
            'seal_number' => $container->seal_number,
            'notes' => $container->notes,
        ];
    }

    public function cancelEditContainer(): void
    {
        $this->editContainerId = null;
        $this->editContainerForm = [
            'container_number' => null,
            'type' => null,
            'seal_number' => null,
            'notes' => null,
        ];
    }

    public function saveEditContainer(): void
    {
        if (! $this->editContainerId) {
            return;
        }

        $container = $this->shipment->shipmentContainers()->find($this->editContainerId);
        if (! $container) {
            $this->cancelEditContainer();

            return;
        }

        $attrs = array_map(fn ($v) => $v === '' ? null : $v, $this->editContainerForm);

        app(UpdateContainerAction::class)->execute($container, $attrs);

        Notification::make()->success()->title("{$container->label} updated")->send();

        $this->cancelEditContainer();
        $this->refreshData();
    }

    public function deleteContainer(int $containerId): void
    {
        $container = $this->shipment->shipmentContainers()->find($containerId);
        if (! $container) {
            return;
        }

        app(DeleteContainerAction::class)->execute($container);

        Notification::make()->success()->title('Container removed — contents are now loose')->send();
        $this->refreshData();
    }

    // ---------- Pallet actions ----------

    public function createPallet(?int $containerId = null): void
    {
        app(CreatePalletAction::class)->execute($this->shipment, [
            'shipment_container_id' => $containerId,
        ]);

        $this->refreshData();
    }

    public function startEditPallet(int $palletId): void
    {
        $pallet = $this->shipment->shipmentPallets()->find($palletId);
        if (! $pallet) {
            return;
        }

        $this->editPalletId = $palletId;
        $this->editPalletForm = [
            'shipment_container_id' => $pallet->shipment_container_id,
            'length' => $pallet->length,
            'width' => $pallet->width,
            'height' => $pallet->height,
            'notes' => $pallet->notes,
        ];
    }

    public function cancelEditPallet(): void
    {
        $this->editPalletId = null;
        $this->editPalletForm = [
            'shipment_container_id' => null,
            'length' => null,
            'width' => null,
            'height' => null,
            'notes' => null,
        ];
    }

    public function saveEditPallet(): void
    {
        if (! $this->editPalletId) {
            return;
        }

        $pallet = $this->shipment->shipmentPallets()->find($this->editPalletId);
        if (! $pallet) {
            $this->cancelEditPallet();

            return;
        }

        $attrs = array_map(fn ($v) => $v === '' ? null : $v, $this->editPalletForm);

        app(UpdatePalletAction::class)->execute($pallet, $attrs);

        Notification::make()->success()->title("{$pallet->label} updated")->send();

        $this->cancelEditPallet();
        $this->refreshData();
    }

    public function deletePallet(int $palletId): void
    {
        $pallet = $this->shipment->shipmentPallets()->find($palletId);
        if (! $pallet) {
            return;
        }

        app(DeletePalletAction::class)->execute($pallet);

        Notification::make()->success()->title('Pallet removed — cartons preserved under container')->send();
        $this->refreshData();
    }

    public function startMovePallet(int $palletId): void
    {
        $this->movePalletId = $palletId;
    }

    public function cancelMovePallet(): void
    {
        $this->movePalletId = null;
    }

    /**
     * Move a pallet to a new parent:
     *   $target = "loose"          → no container
     *   $target = "container:{id}" → inside that container (cartons on pallet follow)
     */
    public function movePalletTo(string $target): void
    {
        if (! $this->movePalletId) {
            return;
        }

        $pallet = $this->shipment->shipmentPallets()->find($this->movePalletId);
        if (! $pallet) {
            $this->cancelMovePallet();

            return;
        }

        if ($target === 'loose') {
            $pallet->update(['shipment_container_id' => null]);

            // Cartons on this pallet also become loose (no container)
            $pallet->cartons()->update(['shipment_container_id' => null]);
        } elseif (str_starts_with($target, 'container:')) {
            $containerId = (int) substr($target, 10);
            $container = $this->shipment->shipmentContainers()->find($containerId);
            if ($container) {
                $pallet->update(['shipment_container_id' => $container->id]);

                // Cartons on this pallet inherit the new container
                $pallet->cartons()->update(['shipment_container_id' => $container->id]);
            }
        }

        Notification::make()->success()->title("{$pallet->label} moved")->send();
        $this->cancelMovePallet();
        $this->refreshData();
    }

    // ---------- Carton actions ----------

    public function createCarton(?int $containerId = null, ?int $palletId = null): void
    {
        app(CreateCartonAction::class)->execute($this->shipment, [
            'shipment_container_id' => $containerId,
            'shipment_pallet_id' => $palletId,
        ]);

        $this->refreshData();
    }

    public function deleteCarton(int $cartonId): void
    {
        $carton = $this->shipment->cartons()->find($cartonId);
        if (! $carton) {
            return;
        }

        app(DeleteCartonAction::class)->execute($carton);
        $this->refreshData();
    }

    /**
     * Delete multiple cartons at once (used by the grouped summary card).
     *
     * @param  array<int>  $cartonIds
     */
    public function deleteAllCartons(array $cartonIds): void
    {
        if (empty($cartonIds)) {
            return;
        }

        $ids = $this->shipment->cartons()->whereIn('id', $cartonIds)->pluck('id');
        $count = $ids->count();

        if ($count === 0) {
            return;
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($ids) {
            CartonContent::whereIn('carton_id', $ids)->delete();
            \App\Domain\Logistics\Models\Carton::whereKey($ids)->delete();
        });

        if ($this->shipment->status === ShipmentStatus::DRAFT) {
            app(RenumberCartonsAction::class)->execute($this->shipment);
        }

        app(RecalculateShipmentTotalsAction::class)->execute($this->shipment);

        Notification::make()
            ->success()
            ->title("Deleted {$count} carton(s)")
            ->send();

        $this->refreshData();
    }

    public function startEditCarton(int $cartonId): void
    {
        $carton = $this->shipment->cartons()->find($cartonId);
        if (! $carton) {
            return;
        }

        $this->editCartonId = $cartonId;
        $this->editCartonForm = [
            'packaging_type' => $carton->packaging_type?->value ?? $carton->getRawOriginal('packaging_type'),
            'gross_weight' => $carton->gross_weight,
            'net_weight' => $carton->net_weight,
            'length' => $carton->length,
            'width' => $carton->width,
            'height' => $carton->height,
            'volume' => $carton->volume,
            'notes' => $carton->notes,
        ];
    }

    public function cancelEditCarton(): void
    {
        $this->editCartonId = null;
        $this->editCartonForm = [
            'packaging_type' => null,
            'gross_weight' => null,
            'net_weight' => null,
            'length' => null,
            'width' => null,
            'height' => null,
            'volume' => null,
            'notes' => null,
        ];
    }

    public function saveEditCarton(): void
    {
        if (! $this->editCartonId) {
            return;
        }

        $carton = $this->shipment->cartons()->find($this->editCartonId);
        if (! $carton) {
            $this->cancelEditCarton();

            return;
        }

        $attrs = array_map(fn ($v) => $v === '' ? null : $v, $this->editCartonForm);

        // Auto-compute volume
        if (empty($attrs['volume']) && ! empty($attrs['length']) && ! empty($attrs['width']) && ! empty($attrs['height'])) {
            $attrs['volume'] = round(
                ((float) $attrs['length'] * (float) $attrs['width'] * (float) $attrs['height']) / 1_000_000,
                6,
            );
        }

        if (empty($attrs['net_weight']) && ! empty($attrs['gross_weight'])) {
            $attrs['net_weight'] = round((float) $attrs['gross_weight'] * 0.9, 3);
        }

        try {
            app(UpdateCartonAction::class)->execute($carton, $attrs);

            Notification::make()->success()->title("{$carton->label} updated")->send();
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title('Could not update carton')->body($e->getMessage())->send();

            return;
        }

        $this->cancelEditCarton();
        $this->refreshData();
    }

    // ---------- Bulk edit cartons (whole subgroup at once) ----------

    /**
     * Open the bulk-edit form for a subgroup. Pre-fills each field with the
     * value shared by every carton in the group; leaves it blank when they
     * differ (blank = "leave unchanged" on save).
     *
     * @param  array<int>  $cartonIds
     */
    public function startBulkEditCartons(array $cartonIds, string $key): void
    {
        $cartons = $this->shipment->cartons()->whereIn('id', $cartonIds)->get();
        if ($cartons->isEmpty()) {
            return;
        }

        $common = fn (string $field) => $cartons->pluck($field)->unique()->count() === 1
            ? $cartons->first()->{$field}
            : null;

        $this->bulkEditKey = $key;
        $this->bulkEditCartonIds = $cartons->pluck('id')->values()->all();
        $this->bulkEditForm = [
            'gross_weight' => $common('gross_weight'),
            'net_weight' => $common('net_weight'),
            'length' => $common('length'),
            'width' => $common('width'),
            'height' => $common('height'),
        ];
    }

    public function cancelBulkEditCartons(): void
    {
        $this->bulkEditKey = null;
        $this->bulkEditCartonIds = [];
        $this->bulkEditForm = [
            'gross_weight' => null,
            'net_weight' => null,
            'length' => null,
            'width' => null,
            'height' => null,
        ];
    }

    public function saveBulkEditCartons(): void
    {
        if (empty($this->bulkEditCartonIds)) {
            return;
        }

        // Blank field = leave unchanged: keep only the fields the user filled in.
        $attrs = array_filter(
            array_map(fn ($v) => $v === '' ? null : $v, $this->bulkEditForm),
            fn ($v) => $v !== null,
        );

        // Recompute volume only when all three dimensions are provided together.
        if (isset($attrs['length'], $attrs['width'], $attrs['height'])) {
            $attrs['volume'] = round(
                ((float) $attrs['length'] * (float) $attrs['width'] * (float) $attrs['height']) / 1_000_000,
                6,
            );
        }

        // Auto net weight from gross when gross is set but net was left blank.
        if (isset($attrs['gross_weight']) && ! isset($attrs['net_weight'])) {
            $attrs['net_weight'] = round((float) $attrs['gross_weight'] * 0.9, 3);
        }

        if (empty($attrs)) {
            $this->cancelBulkEditCartons();

            return;
        }

        $result = app(BulkUpdateCartonsAction::class)->execute($this->shipment, $this->bulkEditCartonIds, $attrs);

        Notification::make()
            ->success()
            ->title("{$result['updated']} caixa(s) atualizada(s)")
            ->send();

        if ($result['skipped'] > 0) {
            Notification::make()
                ->warning()
                ->title("{$result['skipped']} caixa(s) ignorada(s)")
                ->body($result['error'])
                ->send();
        }

        $this->cancelBulkEditCartons();
        $this->refreshData();
    }

    // ---------- Move carton (change parent) ----------

    public function startMoveCarton(int $cartonId): void
    {
        $this->moveCartonId = $cartonId;
    }

    public function cancelMoveCarton(): void
    {
        $this->moveCartonId = null;
    }

    /**
     * Move a carton to a new parent:
     *   $target = "loose"         → no container, no pallet
     *   $target = "container:{id}" → inside container, no pallet
     *   $target = "pallet:{id}"    → inside pallet (container inherited)
     */
    public function moveCartonTo(string $target): void
    {
        if (! $this->moveCartonId) {
            return;
        }

        $carton = $this->shipment->cartons()->find($this->moveCartonId);
        if (! $carton) {
            $this->cancelMoveCarton();

            return;
        }

        if ($target === 'loose') {
            $carton->update([
                'shipment_container_id' => null,
                'shipment_pallet_id' => null,
            ]);
        } elseif (str_starts_with($target, 'container:')) {
            $containerId = (int) substr($target, 10);
            $container = $this->shipment->shipmentContainers()->find($containerId);
            if ($container) {
                $carton->update([
                    'shipment_container_id' => $container->id,
                    'shipment_pallet_id' => null,
                ]);
            }
        } elseif (str_starts_with($target, 'pallet:')) {
            $palletId = (int) substr($target, 7);
            $pallet = $this->shipment->shipmentPallets()->find($palletId);
            if ($pallet) {
                $carton->update([
                    'shipment_container_id' => $pallet->shipment_container_id,
                    'shipment_pallet_id' => $pallet->id,
                ]);
            }
        }

        Notification::make()->success()->title("{$carton->label} moved")->send();
        $this->cancelMoveCarton();
        $this->refreshData();
    }

    // ---------- Content actions ----------

    public function startAddContent(int $cartonId): void
    {
        $this->addContentCartonId = $cartonId;
        $this->addContentItemId = null;
        $this->addContentPieces = 0;
    }

    public function cancelAddContent(): void
    {
        $this->addContentCartonId = null;
        $this->addContentItemId = null;
        $this->addContentPieces = 0;
    }

    public function updatedAddContentItemId(): void
    {
        // Clear the quantity when the product changes so the field shows the
        // remaining-count placeholder. We intentionally do NOT prefill the total
        // here: a server-pushed value collides with the deferred input's DOM
        // morph and would overwrite whatever the user types. confirmAddContent()
        // treats a blank/zero quantity as "pack all remaining".
        $this->addContentPieces = null;
    }

    public function confirmAddContent(): void
    {
        if (! $this->addContentCartonId || ! $this->addContentItemId) {
            $this->cancelAddContent();

            return;
        }

        $carton = $this->shipment->cartons()->find($this->addContentCartonId);
        $item = $this->shipment->items()->find($this->addContentItemId);

        if (! $carton || ! $item) {
            $this->cancelAddContent();

            return;
        }

        // Blank/zero quantity means "pack all remaining" for this product.
        $pieces = (int) $this->addContentPieces;
        if ($pieces <= 0) {
            $pieces = app(PackingProgressService::class)->forShipmentItem($item)->remaining();
        }

        if ($pieces <= 0) {
            $this->cancelAddContent();

            return;
        }

        if ($item->packing_split !== null) {
            Notification::make()
                ->warning()
                ->title('Use the split controls for this product')
                ->body('This product has an active split — use the part rows below the product to place pieces.')
                ->send();
            $this->cancelAddContent();

            return;
        }

        try {
            app(AddContentToCartonAction::class)->execute($carton, $item, $pieces);

            Notification::make()->success()->title('Content added')->send();
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title('Cannot add content')->body($e->getMessage())->send();
        }

        $this->cancelAddContent();
        $this->refreshData();
    }

    public function deleteContent(int $contentId): void
    {
        $content = CartonContent::find($contentId);
        if (! $content) {
            return;
        }

        if ($content->carton?->shipment_id !== $this->shipment->id) {
            return;
        }

        app(DeleteCartonContentAction::class)->execute($content);
        $this->refreshData();
    }

    // ---------- Split actions ----------

    public function startSplit(int $itemId): void
    {
        $this->splitItemId = $itemId;
        $this->splitPartsCount = 2;
        $this->splitPartLabels = ['Part 1', 'Part 2'];
    }

    public function cancelSplit(): void
    {
        $this->splitItemId = null;
        $this->splitPartsCount = 2;
        $this->splitPartLabels = ['Part 1', 'Part 2'];
    }

    public function updatedSplitPartsCount($value): void
    {
        $count = max(2, min(10, (int) ($value ?: 2)));
        $this->splitPartsCount = $count;

        while (count($this->splitPartLabels) < $count) {
            $this->splitPartLabels[] = 'Part '.(count($this->splitPartLabels) + 1);
        }
        $this->splitPartLabels = array_slice($this->splitPartLabels, 0, $count);
    }

    public function confirmSplit(): void
    {
        if (! $this->splitItemId) {
            return;
        }

        $item = $this->shipment->items()->find($this->splitItemId);
        if (! $item) {
            $this->cancelSplit();

            return;
        }

        try {
            app(SplitProductAcrossCartonsAction::class)->execute($item, $this->splitPartLabels);

            Notification::make()->success()->title('Split created')->send();
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title('Cannot split')->body($e->getMessage())->send();
        }

        $this->cancelSplit();
        $this->refreshData();
    }

    public function clearSplit(int $itemId): void
    {
        $item = $this->shipment->items()->find($itemId);
        if (! $item) {
            return;
        }

        try {
            $removed = app(SplitProductAcrossCartonsAction::class)->clear($item);

            Notification::make()
                ->success()
                ->title('Split cleared')
                ->body($removed > 0 ? "Removed {$removed} carton content(s)." : null)
                ->send();
        } catch (\Throwable $e) {
            Notification::make()->danger()->title('Could not clear split')->body($e->getMessage())->send();
        }

        $this->refreshData();
    }

    public function placePartPieces(int $itemId, string $partLabel): void
    {
        $key = $this->placeFormKey($itemId, $partLabel);
        $form = $this->placeForm[$key] ?? null;

        if (! $form || empty($form['cartonId']) || empty($form['pieces'])) {
            Notification::make()->warning()->title('Select a box and enter pieces')->send();

            return;
        }

        $cartonId = (int) $form['cartonId'];
        $pieces = (int) $form['pieces'];

        $item = $this->shipment->items()->find($itemId);
        $carton = $this->shipment->cartons()->find($cartonId);

        if (! $item || ! $carton || $item->packing_split === null) {
            return;
        }

        $setId = $item->packing_split['set_id'] ?? null;

        try {
            app(AddContentToCartonAction::class)->execute($carton, $item, $pieces, $partLabel, $setId);

            Notification::make()->success()->title("Added {$pieces} × {$partLabel} to {$carton->label}")->send();

            unset($this->placeForm[$key]);
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title('Cannot place part')->body($e->getMessage())->send();
        }

        $this->refreshData();
    }

    // ---------- Bulk fill ----------

    public function startFillContainer(int $containerId): void
    {
        $this->fillTargetType = 'container';
        $this->fillTargetId = $containerId;
        $this->fillItemId = null;
        $this->fillPieces = null;
        $this->fillPcsPerCarton = null;
    }

    public function startFillPallet(int $palletId): void
    {
        $this->fillTargetType = 'pallet';
        $this->fillTargetId = $palletId;
        $this->fillItemId = null;
        $this->fillPieces = null;
        $this->fillPcsPerCarton = null;
    }

    /**
     * Start fill from product card — user picks the target container/pallet/box.
     */
    public function startPackProduct(int $itemId): void
    {
        $this->fillFromProduct = true;
        $this->fillTargetType = null;
        $this->fillTargetId = null;
        $this->fillItemId = $itemId;

        // Do not prefill the total: the quantity field shows the remaining as a
        // placeholder and confirmFill() treats blank/zero as "all remaining". A
        // server-pushed value collides with the input's DOM morph.
        $this->fillPieces = null;
        $this->fillPcsPerCarton = null;
    }

    public function cancelFill(): void
    {
        $this->fillTargetType = null;
        $this->fillTargetId = null;
        $this->fillItemId = null;
        $this->fillPieces = 0;
        $this->fillPcsPerCarton = null;
        $this->fillFromProduct = false;
        $this->cartonSearch = '';
    }

    public function updatedFillItemId(): void
    {
        // Clear the quantity when the product changes so the field shows the
        // remaining-count placeholder. We intentionally do NOT prefill the total:
        // confirmFill() treats a blank/zero quantity as "pack all remaining".
        $this->fillPieces = null;
    }

    /**
     * When user picks a target in the "Pack product" form.
     */
    public function updatedFillTargetId(): void
    {
        // Auto-detect type from the value (set by the select)
    }

    /**
     * Set fill target from the combined select (used by pack-from-product form).
     */
    public function setFillTarget(string $target): void
    {
        if ($target === '') {
            $this->fillTargetType = null;
            $this->fillTargetId = null;

            return;
        }

        if ($target === 'loose') {
            $this->fillTargetType = 'loose';
            $this->fillTargetId = null;
        } elseif (str_starts_with($target, 'container:')) {
            $this->fillTargetType = 'container';
            $this->fillTargetId = (int) substr($target, 10);
        } elseif (str_starts_with($target, 'pallet:')) {
            $this->fillTargetType = 'pallet';
            $this->fillTargetId = (int) substr($target, 7);
        } elseif (str_starts_with($target, 'carton:')) {
            $this->fillTargetType = 'carton';
            $this->fillTargetId = (int) substr($target, 7);
        }
    }

    /**
     * Preview: how many cartons will be created for the current fill form.
     * Used by the view to show "→ N cartons @ X pcs/carton".
     *
     * @return array{cartons: int, per_carton: int, remainder: int}
     */
    #[Computed]
    public function fillPreview(): array
    {
        if (! $this->fillItemId) {
            return ['cartons' => 0, 'per_carton' => 0, 'remainder' => 0];
        }

        $item = $this->shipment->items()->with('proformaInvoiceItem')->find($this->fillItemId);
        if (! $item) {
            return ['cartons' => 0, 'per_carton' => 0, 'remainder' => 0];
        }

        // Blank/zero mirrors confirmFill: preview all remaining.
        $pieces = (int) $this->fillPieces;
        if ($pieces <= 0) {
            $pieces = app(PackingProgressService::class)->forShipmentItem($item)->remaining();
        }
        if ($pieces <= 0) {
            return ['cartons' => 0, 'per_carton' => 0, 'remainder' => 0];
        }

        $productId = $item->proformaInvoiceItem?->product_id;
        $product = $productId
            ? \App\Domain\Catalog\Models\Product::withTrashed()->with('packaging')->find($productId)
            : null;
        $packaging = $product?->packaging;

        // Override pontual digitado no form vence o padrão do cadastro.
        $pcsPerCarton = (int) $this->fillPcsPerCarton > 0
            ? (int) $this->fillPcsPerCarton
            : (int) ($packaging?->pcs_per_carton ?? 0);

        if ($pcsPerCarton <= 0) {
            return ['cartons' => 1, 'per_carton' => $pieces, 'remainder' => 0];
        }

        $full = intdiv($pieces, $pcsPerCarton);
        $remainder = $pieces % $pcsPerCarton;

        return [
            'cartons' => $full,
            'per_carton' => $pcsPerCarton,
            'remainder' => $remainder,
        ];
    }

    public function confirmFill(): void
    {
        if (! $this->fillTargetType || ! $this->fillItemId) {
            Notification::make()->warning()->title('Select a destination and quantity')->send();

            return;
        }

        $item = $this->shipment->items()->find($this->fillItemId);
        if (! $item) {
            $this->cancelFill();

            return;
        }

        // Blank/zero quantity means "pack all remaining" for this product.
        $fillPieces = (int) $this->fillPieces;
        if ($fillPieces <= 0) {
            $fillPieces = app(PackingProgressService::class)->forShipmentItem($item)->remaining();
        }

        if ($fillPieces <= 0) {
            Notification::make()->warning()->title('Select a destination and quantity')->send();

            return;
        }

        // Target a specific existing box: add the pieces straight into it
        // (lets the user put several different products in one carton).
        if ($this->fillTargetType === 'carton') {
            $carton = $this->shipment->cartons()->find($this->fillTargetId);
            if (! $carton) {
                $this->cancelFill();

                return;
            }

            try {
                app(AddContentToCartonAction::class)->execute($carton, $item, $fillPieces);

                Notification::make()
                    ->success()
                    ->title("Packed {$fillPieces} pcs into {$carton->label}")
                    ->send();
            } catch (InvalidArgumentException $e) {
                Notification::make()
                    ->danger()
                    ->title('Cannot add to box')
                    ->body($e->getMessage())
                    ->send();
            }

            $this->cancelFill();
            $this->refreshData();

            return;
        }

        $container = null;
        $pallet = null;

        if ($this->fillTargetType === 'container') {
            $container = $this->shipment->shipmentContainers()->find($this->fillTargetId);
            if (! $container) {
                $this->cancelFill();

                return;
            }
        } elseif ($this->fillTargetType === 'pallet') {
            $pallet = $this->shipment->shipmentPallets()->find($this->fillTargetId);
            if (! $pallet) {
                $this->cancelFill();

                return;
            }
        }
        // 'loose' → both null, cartons created without parent

        $pcsOverride = (int) $this->fillPcsPerCarton > 0 ? (int) $this->fillPcsPerCarton : null;

        try {
            $created = app(BulkFillAction::class)->execute($item, $fillPieces, $container, $pallet, $pcsOverride);

            $targetLabel = $container?->label ?? $pallet?->label ?? 'target';
            Notification::make()
                ->success()
                ->title("Packed {$fillPieces} pcs into {$created} carton(s)")
                ->body("Placed in {$targetLabel}")
                ->send();
        } catch (InvalidArgumentException $e) {
            Notification::make()
                ->danger()
                ->title('Cannot bulk fill')
                ->body($e->getMessage())
                ->send();
        }

        $this->cancelFill();
        $this->refreshData();
    }

    public function generateFromItems(bool $mixed = false): void
    {
        try {
            $created = app(GeneratePackingListV2Action::class)->execute($this->shipment, $mixed);

            Notification::make()->success()->title("Generated {$created} carton(s) from shipment items")->send();
        } catch (\Throwable $e) {
            Notification::make()->danger()->title('Generation failed')->body($e->getMessage())->send();
        }

        $this->refreshData();
    }

    // ---------- Subgroup expand/collapse (lazy rendering) ----------

    public function toggleSubgroup(string $key): void
    {
        if ($this->expandedSubgroups[$key] ?? false) {
            unset($this->expandedSubgroups[$key], $this->subgroupLimits[$key]);
        } else {
            $this->expandedSubgroups[$key] = true;
        }
    }

    public function showMoreInSubgroup(string $key): void
    {
        $this->subgroupLimits[$key] = ($this->subgroupLimits[$key] ?? self::SUBGROUP_PAGE) + self::SUBGROUP_PAGE;
    }

    public function toggleContainer(int $containerId): void
    {
        if ($this->collapsedContainers[$containerId] ?? false) {
            unset($this->collapsedContainers[$containerId]);
        } else {
            $this->collapsedContainers[$containerId] = true;
        }
    }

    // ---------- Helpers ----------

    public function placeFormKey(int $itemId, string $partLabel): string
    {
        return $itemId.'::'.$partLabel;
    }

    private function refreshData(): void
    {
        unset(
            $this->containers,
            $this->loosePallets,
            $this->looseCartons,
            $this->cartonFillOptions,
            $this->cartonCount,
            $this->products,
            $this->fillPreview,
        );
    }

    public function render()
    {
        return view('livewire.logistics.packing-list-builder');
    }
}
