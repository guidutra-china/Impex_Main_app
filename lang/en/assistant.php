<?php

return [
    // Chat UI
    'empty_hint' => 'Ask a question — e.g. "Find the company DeepFitness" or "What is the status of client Gencrea?"',
    'thinking' => 'Thinking…',
    'composer_placeholder' => 'Ask the assistant something…',
    'send' => 'Send',
    'clear_conversation' => 'Clear conversation',
    'chat_error' => 'There was an error talking to the assistant. Please try again shortly.',
    'edit_done' => 'Done.',
    'edit_hint' => 'Something off? Ask me here in the chat to fix it (e.g. "set unit to pcs for all").',
    'review_hint' => 'Review and adjust each item and its photo, then confirm.',
    'photo_none' => 'No photo',
    'currency' => 'Currency',

    // Import — drop zone
    'dropzone_drag' => 'Drag and drop',
    'dropzone_rest' => 'a supplier quotation here, or click to select',
    'dropzone_formats' => 'xlsx, xls or PDF',
    'processing' => 'Processing…',
    'processing_file' => 'Processing :file…',
    'import_button' => 'Import',

    // Import — preview
    'preview_title' => 'Import preview',
    'supplier' => 'Supplier',
    'status_new' => 'new',
    'status_existing' => 'existing',
    'summary_counts' => ':total items — :existing existing products, :new new.',
    'items_total' => 'Items total',
    'document_total' => 'Document total',
    'extras_note' => 'Fees/discounts (added to the notes, not imported as items):',
    'divergence_warning' => '⚠️ The sum of items + fees/discounts does not match the document total. Check the values before confirming.',
    'uncategorized_warning' => '⚠️ :count item(s) with no matching category (flagged below). They will be created without a category — classify them later if needed.',
    'no_category' => 'no category',
    'confirm_import' => 'Confirm import',
    'cancel' => 'Cancel',
    'col' => [
        'part_no' => 'Part No.',
        'description' => 'Description',
        'qty' => 'Qty',
        'unit' => 'Unit',
        'unit_price' => 'Unit price',
        'total' => 'Total',
        'category' => 'Category',
        'status' => 'Status',
        'photo' => 'Photo',
        'product' => 'Product',
    ],

    // Chat / flow messages
    'extracted' => 'I extracted the quotation from the file. Review the summary below and confirm to import.',
    'process_failed' => 'I could not process the file: :error',
    'connection_failed' => 'Could not connect to the AI service. Check your connection/VPN and try again.',
    'process_error' => 'Error processing the file. Please try again.',
    'imported_title' => 'Quotation imported: :reference',
    'imported_message' => 'Quotation :reference created with :count items.',
    'invalid_file' => 'Invalid import file.',
    'import_failed' => 'Failed to import the quotation.',

    // Permissions
    'perm_supplier_quotations' => 'You do not have permission to create supplier quotations.',
    'perm_companies' => 'You do not have permission to create companies (new supplier).',
    'perm_products' => 'You do not have permission to create products.',

    // Agentic loop
    'too_many_steps' => 'I could not complete the request (too many steps). Try rephrasing it more simply.',
    'no_answer' => '(no answer)',
    'tool_unknown' => 'Unknown tool.',
    'tool_denied' => 'Permission denied: you do not have access to this data.',
    'tool_error' => 'Error running the tool: :error',
];
