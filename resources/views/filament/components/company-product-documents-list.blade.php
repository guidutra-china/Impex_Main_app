@props(['documents'])

@if($documents->isNotEmpty())
    <div style="border-top: 1px solid #e5e7eb; padding-top: 16px; margin-top: 8px;">
        <h4 style="font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 12px;">
            {{ __('forms.labels.existing_documents') }} ({{ $documents->count() }})
        </h4>
        <div style="display: flex; flex-direction: column; gap: 8px;">
            @foreach($documents as $doc)
                <div style="display: flex; align-items: center; justify-content: space-between; border: 1px solid #e5e7eb; border-radius: 8px; padding: 8px 12px; font-size: 13px;">
                    <div style="display: flex; align-items: center; gap: 10px; min-width: 0; flex: 1;">
                        <span style="display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 500; white-space: nowrap;
                            @switch($doc->category->value)
                                @case('certificate') background-color: #dcfce7; color: #15803d; @break
                                @case('photo') background-color: #dbeafe; color: #1d4ed8; @break
                                @case('contract') background-color: #fef3c7; color: #a16207; @break
                                @case('license') background-color: #e0e7ff; color: #4338ca; @break
                                @case('price_list') background-color: #fee2e2; color: #b91c1c; @break
                                @default background-color: #f3f4f6; color: #4b5563;
                            @endswitch
                        ">{{ $doc->category->getLabel() }}</span>
                        <div style="min-width: 0; flex: 1;">
                            <p style="font-weight: 500; color: #111827; margin: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $doc->title }}</p>
                            <p style="font-size: 11px; color: #6b7280; margin: 2px 0 0 0;">
                                {{ $doc->original_name }} &middot; {{ $doc->formatted_size }} &middot; {{ $doc->created_at->format('M d, Y') }}
                            </p>
                        </div>
                    </div>
                    <a href="{{ $doc->getUrl() }}" target="_blank" style="display: inline-flex; align-items: center; justify-content: center; padding: 4px; color: #6b7280; text-decoration: none; margin-left: 12px; flex-shrink: 0;" title="Open file">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width: 16px; height: 16px;">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                        </svg>
                    </a>
                </div>
            @endforeach
        </div>
    </div>
@else
    <div style="border-top: 1px solid #e5e7eb; padding-top: 16px; margin-top: 8px;">
        <p style="font-size: 13px; color: #6b7280; text-align: center; padding: 8px 0;">{{ __('forms.placeholders.no_documents_uploaded_yet') }}</p>
    </div>
@endif
