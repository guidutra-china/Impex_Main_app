<?php

namespace App\Filament\Concerns;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * Standardized header actions for all Operations resources.
 *
 * Page classes (ViewRecord and EditRecord subclasses) under
 * app/Filament/Resources/{Operations}/Pages/ use this trait to render
 * an identical header action layout. The slot order is fixed left → right:
 *
 *   [1] Primary Lifecycle  (Finalize, Confirm, Execute, Reconcile, ...)
 *   [2] Discuss            (always, via DiscussAction)
 *   [3] Documents          (PDF/Excel generation, send by email, ...)
 *   [4] Workflow           (Conversions to other resources)
 *   [5] Versions           (Save Version snapshot, restore, ...)
 *   [6] Status             (Status transitions + revert)
 *   [7] Edit/View toggle   (auto: EditAction on View page, ViewAction on Edit page)
 *   [8] Danger group       (Delete + Restore + ForceDelete)
 *
 * Slots that return null/[] are dropped, so a resource that doesn't generate
 * documents simply omits slot 3 — it will not render an empty placeholder.
 *
 * To use, the page (and a per-resource trait) declares the relevant slots:
 *
 *   class ViewQuotation extends ViewRecord {
 *       use HasOperationsHeaderActions;
 *       use QuotationHeaderActions; // declares concrete slots
 *
 *       protected function getHeaderActions(): array {
 *           return $this->buildOperationsHeader();
 *       }
 *   }
 *
 * Both View and Edit pages of the same resource use the same per-resource
 * trait, guaranteeing View ↔ Edit parity.
 */
trait HasOperationsHeaderActions
{
    /**
     * Build the standardized Operations header actions array.
     *
     * Slots are duck-typed via method_exists to avoid trait method
     * collisions when a per-resource trait provides slot implementations
     * alongside this base trait. A page that wants to declare a slot just
     * defines a method with the matching name (or uses a per-resource trait
     * that defines it) — there's no need to override defaults from this
     * trait or use `insteadof` resolution.
     *
     * Slot methods (all optional):
     *   - primaryLifecycleActions(): array<int, Action>
     *   - documentsActionGroup(): ?ActionGroup
     *   - workflowActionGroup(): ?ActionGroup
     *   - versionActionGroup(): Action|ActionGroup|null
     *   - statusActionGroup(): Action|ActionGroup|null
     *
     * @return array<int, Action|ActionGroup>
     */
    protected function buildOperationsHeader(): array
    {
        $header = [];

        if (method_exists($this, 'primaryLifecycleActions')) {
            foreach ($this->primaryLifecycleActions() as $action) {
                $header[] = $action;
            }
        }

        // DiscussAction ships with the messaging module. Guard the reference
        // so this trait is safe to use in environments where the messaging
        // module hasn't been deployed yet — the slot simply disappears
        // instead of 500'ing on a missing class.
        if (class_exists(\App\Filament\Actions\DiscussAction::class)) {
            $header[] = \App\Filament\Actions\DiscussAction::make();
        }

        foreach (['documentsActionGroup', 'workflowActionGroup', 'versionActionGroup', 'statusActionGroup'] as $slot) {
            if (method_exists($this, $slot)) {
                $group = $this->{$slot}();
                if ($group !== null) {
                    $header[] = $group;
                }
            }
        }

        $header[] = $this->editOrViewAction();
        $header[] = $this->dangerActionGroup();

        return $header;
    }

    /**
     * Slot 6 — Edit ↔ View toggle.
     *
     * Automatically returns:
     *   - EditAction when called from a ViewRecord page
     *   - ViewAction when called from an EditRecord page
     */
    protected function editOrViewAction(): Action
    {
        return $this instanceof ViewRecord
            ? EditAction::make()
            : ViewAction::make();
    }

    /**
     * Slot 7 — destructive action group (Delete + Restore + ForceDelete).
     *
     * Restore and ForceDelete are only visible on soft-deleted records (Filament
     * handles that automatically). The group is collapsed under an ellipsis icon
     * so destructive actions never compete visually with primary workflow.
     */
    protected function dangerActionGroup(): ActionGroup
    {
        return ActionGroup::make([
            DeleteAction::make(),
            RestoreAction::make(),
            ForceDeleteAction::make(),
        ])
            ->icon('heroicon-o-ellipsis-vertical')
            ->color('gray')
            ->button();
    }
}
