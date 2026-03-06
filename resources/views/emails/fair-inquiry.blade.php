<x-mail::message>
Dear {{ $recipientName }},

It was a pleasure visiting your booth at **{{ $tradeFairName }}**.

@if($customMessage)
{{ $customMessage }}
@else
We are interested in your products and would like to request more information, including pricing, specifications, and minimum order quantities for the following items:
@endif

@if(count($productNames) > 0)
**Products of Interest:**

@foreach($productNames as $productName)
- {{ $productName }}
@endforeach
@endif

Could you please provide us with:

1. Updated price list and catalog
2. Product specifications and certifications
3. Minimum order quantities (MOQ)
4. Lead time and payment terms
5. Available samples

We look forward to hearing from you and exploring a potential business partnership.

Best regards,<br>
{{ $senderName }}<br>
{{ config('app.name') }}
</x-mail::message>
