@props(['documents'])

@if($documents->isNotEmpty())
    <div class="border-t pt-4 mt-2">
        <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
            Existing Documents ({{ $documents->count() }})
        </h4>
        <div class="space-y-2">
            @foreach($documents as $doc)
                <div class="flex items-center justify-between rounded-lg border border-gray-200 dark:border-gray-700 px-3 py-2 text-sm">
                    <div class="flex items-center gap-3 min-w-0 flex-1">
                        <span class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset
                            @switch($doc->category->value)
                                @case('certificate') bg-green-50 text-green-700 ring-green-600/20 dark:bg-green-500/10 dark:text-green-400 dark:ring-green-500/20 @break
                                @case('photo') bg-blue-50 text-blue-700 ring-blue-600/20 dark:bg-blue-500/10 dark:text-blue-400 dark:ring-blue-500/20 @break
                                @case('contract') bg-yellow-50 text-yellow-700 ring-yellow-600/20 dark:bg-yellow-500/10 dark:text-yellow-400 dark:ring-yellow-500/20 @break
                                @case('license') bg-indigo-50 text-indigo-700 ring-indigo-600/20 dark:bg-indigo-500/10 dark:text-indigo-400 dark:ring-indigo-500/20 @break
                                @case('price_list') bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-500/10 dark:text-red-400 dark:ring-red-500/20 @break
                                @default bg-gray-50 text-gray-700 ring-gray-600/20 dark:bg-gray-500/10 dark:text-gray-400 dark:ring-gray-500/20
                            @endswitch
                        ">
                            {{ $doc->category->getLabel() }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="font-medium text-gray-900 dark:text-white truncate">{{ $doc->title }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ $doc->original_name }} &middot; {{ $doc->formatted_size }} &middot; {{ $doc->created_at->format('M d, Y') }}
                            </p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 ml-3 shrink-0">
                        <a href="{{ $doc->getUrl() }}" target="_blank"
                           class="inline-flex items-center justify-center rounded-lg p-1 text-gray-400 hover:text-primary-500 transition">
                            <x-heroicon-o-arrow-top-right-on-square class="w-4 h-4" />
                        </a>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@else
    <div class="border-t pt-4 mt-2">
        <p class="text-sm text-gray-500 dark:text-gray-400 text-center py-2">No documents uploaded yet.</p>
    </div>
@endif
