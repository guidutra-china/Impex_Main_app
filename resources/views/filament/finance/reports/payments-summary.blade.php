<x-filament-panels::page>
    @php
        $report = $this->report;
        $filters = $report->filters;
        $isInbound = $filters->direction->value === 'inbound';
        $accent = $isInbound ? 'text-green-600' : 'text-red-600';
    @endphp

    <div class="space-y-6">
        {{-- Filters --}}
        <x-filament::section>
            {{ $this->form }}
        </x-filament::section>

        {{-- KPI cards --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <x-filament::section>
                <div class="text-xs uppercase text-gray-500">{{ __('payments_summary_report.kpi.total_payments') }}</div>
                <div class="text-2xl font-bold">{{ $report->totalPaymentCount }}</div>
            </x-filament::section>
            <x-filament::section>
                <div class="text-xs uppercase text-gray-500">{{ __('payments_summary_report.kpi.totals_by_currency') }}</div>
                <div class="space-y-1">
                    @forelse ($report->grandTotalsByCurrency as $total)
                        <div class="flex items-baseline justify-between">
                            <span class="text-sm text-gray-600">{{ $total->currency }}</span>
                            <span class="text-lg font-semibold {{ $accent }}">
                                {{ \App\Domain\Infrastructure\Support\Money::format($total->total) }}
                            </span>
                        </div>
                    @empty
                        <div class="text-sm text-gray-500">—</div>
                    @endforelse
                </div>
            </x-filament::section>
            <x-filament::section>
                <div class="text-xs uppercase text-gray-500">{{ __('payments_summary_report.kpi.period') }}</div>
                <div class="text-sm">
                    {{ $filters->from->isoFormat('DD MMM YYYY') }}
                    →
                    {{ $filters->to->isoFormat('DD MMM YYYY') }}
                </div>
                <div class="text-xs text-gray-500 mt-1">
                    @if ($filters->approvedOnly)
                        {{ __('payments_summary_report.filters.status_approved_only') }}
                    @else
                        {{ __('payments_summary_report.filters.status_include_pending') }}
                    @endif
                </div>
            </x-filament::section>
        </div>

        {{-- By Company --}}
        <x-filament::section>
            <x-slot name="heading">{{ __('payments_summary_report.sections.by_company') }}</x-slot>

            @if (empty($report->byCompany))
                <div class="text-sm text-gray-500 text-center py-6">{{ __('payments_summary_report.empty_state') }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-gray-700 text-left">
                                <th class="py-2 pr-4">{{ __('payments_summary_report.columns.company') }}</th>
                                <th class="py-2 pr-4 text-right">{{ __('payments_summary_report.columns.payment_count') }}</th>
                                <th class="py-2 pr-4">{{ __('payments_summary_report.columns.totals_by_currency') }}</th>
                                <th class="py-2 pr-4">{{ __('payments_summary_report.columns.last_payment') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($report->byCompany as $bucket)
                                <tr class="border-b border-gray-100 dark:border-gray-800">
                                    <td class="py-2 pr-4 font-medium">{{ $bucket->companyName }}</td>
                                    <td class="py-2 pr-4 text-right">{{ $bucket->paymentCount }}</td>
                                    <td class="py-2 pr-4">
                                        <div class="flex flex-wrap gap-x-4 gap-y-1">
                                            @foreach ($bucket->totalsByCurrency as $total)
                                                <span class="whitespace-nowrap">
                                                    <span class="text-gray-500">{{ $total->currency }}</span>
                                                    <span class="font-semibold {{ $accent }}">{{ \App\Domain\Infrastructure\Support\Money::format($total->total) }}</span>
                                                </span>
                                            @endforeach
                                        </div>
                                    </td>
                                    <td class="py-2 pr-4">
                                        {{ $bucket->lastPaymentDate?->isoFormat('DD MMM YYYY') ?? '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        {{-- By Month --}}
        <x-filament::section>
            <x-slot name="heading">{{ __('payments_summary_report.sections.by_month') }}</x-slot>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-700 text-left">
                            <th class="py-2 pr-4">{{ __('payments_summary_report.columns.month') }}</th>
                            <th class="py-2 pr-4 text-right">{{ __('payments_summary_report.columns.payment_count') }}</th>
                            <th class="py-2 pr-4">{{ __('payments_summary_report.columns.totals_by_currency') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($report->byMonth as $month)
                            <tr class="border-b border-gray-100 dark:border-gray-800 {{ $month->paymentCount === 0 ? 'opacity-50' : '' }}">
                                <td class="py-2 pr-4 font-medium">{{ $month->label }}</td>
                                <td class="py-2 pr-4 text-right">{{ $month->paymentCount }}</td>
                                <td class="py-2 pr-4">
                                    @if ($month->paymentCount === 0)
                                        <span class="text-gray-400">—</span>
                                    @else
                                        <div class="flex flex-wrap gap-x-4 gap-y-1">
                                            @foreach ($month->totalsByCurrency as $total)
                                                <span class="whitespace-nowrap">
                                                    <span class="text-gray-500">{{ $total->currency }}</span>
                                                    <span class="font-semibold {{ $accent }}">{{ \App\Domain\Infrastructure\Support\Money::format($total->total) }}</span>
                                                </span>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        {{-- Allocation Detail --}}
        <x-filament::section>
            <x-slot name="heading">{{ __('payments_summary_report.sections.allocations') }}</x-slot>

            @if (empty($report->allocations))
                <div class="text-sm text-gray-500 text-center py-6">{{ __('payments_summary_report.empty_state') }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-gray-700 text-left">
                                <th class="py-2 pr-4">{{ __('payments_summary_report.columns.date') }}</th>
                                <th class="py-2 pr-4">{{ __('payments_summary_report.columns.reference') }}</th>
                                <th class="py-2 pr-4">{{ __('payments_summary_report.columns.company') }}</th>
                                <th class="py-2 pr-4">{{ __('payments_summary_report.columns.status') }}</th>
                                <th class="py-2 pr-4">{{ __('payments_summary_report.columns.currency') }}</th>
                                <th class="py-2 pr-4 text-right">{{ __('payments_summary_report.columns.amount') }}</th>
                                <th class="py-2 pr-4">{{ __('payments_summary_report.columns.allocated_to') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($report->allocations as $detail)
                                <tr class="border-b border-gray-100 dark:border-gray-800 align-top">
                                    <td class="py-2 pr-4 whitespace-nowrap">{{ $detail->paymentDate->isoFormat('DD MMM YYYY') }}</td>
                                    <td class="py-2 pr-4">
                                        @if ($detail->detailUrl)
                                            <a href="{{ $detail->detailUrl }}" class="text-primary-600 hover:underline">
                                                {{ $detail->paymentReference }}
                                            </a>
                                        @else
                                            {{ $detail->paymentReference }}
                                        @endif
                                    </td>
                                    <td class="py-2 pr-4">{{ $detail->companyName }}</td>
                                    <td class="py-2 pr-4">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs
                                            @if ($detail->paymentStatus->value === 'approved') bg-green-100 text-green-800
                                            @elseif ($detail->paymentStatus->value === 'pending_approval') bg-yellow-100 text-yellow-800
                                            @else bg-gray-100 text-gray-800 @endif">
                                            {{ $detail->paymentStatus->getLabel() }}
                                        </span>
                                    </td>
                                    <td class="py-2 pr-4">{{ $detail->currency }}</td>
                                    <td class="py-2 pr-4 text-right font-semibold {{ $accent }}">
                                        {{ \App\Domain\Infrastructure\Support\Money::format($detail->paymentAmount) }}
                                    </td>
                                    <td class="py-2 pr-4">
                                        @if (empty($detail->allocations))
                                            <span class="text-xs text-gray-500 italic">
                                                {{ __('payments_summary_report.unallocated') }}
                                                ({{ \App\Domain\Infrastructure\Support\Money::format($detail->unallocatedAmount) }})
                                            </span>
                                        @else
                                            <ul class="space-y-0.5">
                                                @foreach ($detail->allocations as $alloc)
                                                    <li class="text-xs">
                                                        @if ($alloc['document_url'])
                                                            <a href="{{ $alloc['document_url'] }}" class="text-primary-600 hover:underline">
                                                                {{ $alloc['document_type'] }} {{ $alloc['document_reference'] }}
                                                            </a>
                                                        @elseif ($alloc['document_reference'])
                                                            {{ $alloc['document_type'] }} {{ $alloc['document_reference'] }}
                                                        @endif
                                                        @if (! empty($alloc['client_reference']))
                                                            <span class="text-gray-500">({{ __('payments_summary_report.client_ref') }}: {{ $alloc['client_reference'] }})</span>
                                                        @endif
                                                        <span class="text-gray-500">— {{ $alloc['label'] }}:</span>
                                                        <span class="font-medium">{{ \App\Domain\Infrastructure\Support\Money::format($alloc['amount']) }}</span>
                                                        @if ($alloc['is_credit'])
                                                            <span class="text-blue-600">({{ __('payments_summary_report.credit') }})</span>
                                                        @endif
                                                    </li>
                                                @endforeach
                                                @if ($detail->unallocatedAmount > 0)
                                                    <li class="text-xs text-amber-600 italic">
                                                        {{ __('payments_summary_report.unallocated') }}:
                                                        {{ \App\Domain\Infrastructure\Support\Money::format($detail->unallocatedAmount) }}
                                                    </li>
                                                @endif
                                            </ul>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
