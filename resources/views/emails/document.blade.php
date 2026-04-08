<x-mail::message>
Dear {{ $recipientName }},

@if($customMessage)
{{ $customMessage }}
@endif

**Document:** {{ $document->name }}

If you have any questions, please don't hesitate to contact us.

Best regards,<br>
{{ config('app.name') }}
</x-mail::message>
