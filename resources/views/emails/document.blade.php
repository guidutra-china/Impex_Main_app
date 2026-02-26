<x-mail::message>
Dear {{ $recipientName }},

@if($customMessage)
{{ $customMessage }}
@else
Please find the attached document for your reference.
@endif

**Document:** {{ $document->name }}

If you have any questions, please don't hesitate to contact us.

Best regards,<br>
{{ config('app.name') }}
</x-mail::message>
