@php($previewKey = 'pdf-preview-' . md5($html))
<div class="w-full">
    <iframe
        id="{{ $previewKey }}"
        srcdoc="{{ $html }}"
        class="w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-white"
        style="height: 80vh;"
        sandbox="allow-same-origin"
    ></iframe>
</div>
