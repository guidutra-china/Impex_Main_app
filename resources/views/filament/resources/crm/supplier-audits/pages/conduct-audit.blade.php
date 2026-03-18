<x-filament-panels::page>
    @php
        $audit = $this->getRecord();
        $completionPct = $audit->getCompletionPercentage();
    @endphp

    <style>
        /* Mobile-first audit layout */
        @media (max-width: 768px) {
            /* Compact tab labels */
            .fi-tabs-item {
                font-size: 0.75rem !important;
                padding: 0.5rem 0.75rem !important;
            }
            /* Stack radio options vertically */
            .fi-fo-radio .flex.flex-wrap {
                flex-direction: column !important;
                gap: 0.5rem !important;
            }
            /* Larger touch targets for radio/toggle */
            .fi-fo-radio label,
            .fi-fo-toggle label {
                min-height: 44px;
                display: flex;
                align-items: center;
            }
            /* Compact sections */
            .fi-section-content {
                padding: 0.75rem !important;
            }
            .fi-section-header {
                padding: 0.75rem !important;
            }
            /* Fixed bottom action bar */
            .audit-bottom-actions {
                position: fixed;
                bottom: 0;
                left: 0;
                right: 0;
                z-index: 50;
                padding: 0.75rem 1rem;
                background: white;
                border-top: 1px solid rgb(229 231 235);
                box-shadow: 0 -4px 6px -1px rgb(0 0 0 / 0.1);
                display: flex;
                gap: 0.5rem;
            }
            .dark .audit-bottom-actions {
                background: rgb(31 41 55);
                border-top-color: rgb(55 65 81);
            }
            /* Add bottom padding so content isn't hidden behind fixed bar */
            .audit-form-container {
                padding-bottom: 5rem;
            }
        }
        /* Progress bar */
        .audit-progress-bar {
            height: 6px;
            background: rgb(229 231 235);
            border-radius: 3px;
            overflow: hidden;
        }
        .dark .audit-progress-bar {
            background: rgb(55 65 81);
        }
        .audit-progress-fill {
            height: 100%;
            border-radius: 3px;
            transition: width 0.3s ease;
        }
        /* Critical criterion highlight */
        .criterion-critical {
            border-left: 3px solid rgb(239 68 68) !important;
        }
    </style>

    <div class="space-y-3 audit-form-container">
        {{-- Compact header card --}}
        <div class="p-3 sm:p-4 rounded-lg bg-white dark:bg-gray-800 shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0 flex-1">
                    <h3 class="text-base sm:text-lg font-semibold text-gray-900 dark:text-white truncate">
                        {{ $audit->reference }}
                    </h3>
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300 truncate">
                        {{ $audit->company->name }}
                    </p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                        {{ $audit->audit_type->getLabel() }}
                        @if($audit->location) · {{ $audit->location }} @endif
                        · {{ $audit->scheduled_date->format('d/m/Y') }}
                    </p>
                </div>
                <x-filament::badge :color="$audit->status->getColor()" class="shrink-0">
                    {{ $audit->status->getLabel() }}
                </x-filament::badge>
            </div>

            {{-- Progress bar --}}
            <div class="mt-3">
                <div class="flex items-center justify-between mb-1">
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
                        {{ __('Progress') }}
                    </span>
                    <span class="text-xs font-semibold {{ $completionPct >= 100 ? 'text-green-600' : 'text-gray-600 dark:text-gray-300' }}">
                        {{ number_format($completionPct, 0) }}%
                    </span>
                </div>
                <div class="audit-progress-bar">
                    <div class="audit-progress-fill {{ $completionPct >= 100 ? 'bg-green-500' : ($completionPct >= 50 ? 'bg-blue-500' : 'bg-amber-500') }}"
                         style="width: {{ min($completionPct, 100) }}%"></div>
                </div>
            </div>
        </div>

        {{-- Form --}}
        <form wire:submit="save">
            {{ $this->form }}
        </form>

        {{-- Mobile fixed bottom action bar --}}
        <div class="audit-bottom-actions md:hidden">
            <x-filament::button
                wire:click="save"
                color="gray"
                icon="heroicon-o-bookmark"
                class="flex-1"
                size="sm"
            >
                {{ __('Save') }}
            </x-filament::button>
            <x-filament::button
                wire:click="saveAndComplete"
                wire:confirm="{{ __('This will save all responses, calculate the final score, and mark the audit as completed. Continue?') }}"
                color="success"
                icon="heroicon-o-check-circle"
                class="flex-1"
                size="sm"
            >
                {{ __('Complete') }}
            </x-filament::button>
        </div>
    </div>
</x-filament-panels::page>
